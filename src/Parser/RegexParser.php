<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Parser;

use PHPRegex\Parser\Analysis\ComplexityScorer;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Cache\RemovableCacheInterface;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Internal\PcreVerb;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\Syntax\TokenParser;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Parser\Validation\Validator;

/**
 * Reads a delimited pattern into a tree and judges it for a PHP version and
 * a PCRE2 release: the one place a verdict on a pattern is made.
 *
 * Every other layer reads patterns through it — ReDoS analysis, automata,
 * the linter, the transpiler, the bridges — and the Regex facade hands its
 * own to them.
 */
final readonly class RegexParser
{
    /**
     * Cache version for AST serialization, and part of the key of a
     * persistent DFA cache.
     *
     * A cached tree or automaton is only worth restoring while the current
     * code would build the same one, so this is not a number anybody raises
     * by hand: it is a fingerprint of the code that decides what a pattern
     * parses into — the lexer, the parser, the nodes, the validator and the
     * readers they use — and of the code that turns a tree into a DFA.
     *
     * "task cache-version" writes it, "task lint" runs that, and the test
     * suite fails while the constant and the code disagree.
     */
    public const CACHE_VERSION = 'ast-1cc5deca3f9c62f78c40aef038020e8a';

    /**
     * Default maximum allowed regex pattern length.
     */
    public const DEFAULT_MAX_PATTERN_LENGTH = 100_000;

    /**
     * Default maximum length of a variable-length lookbehind, PCRE2's own
     * default for max_varlookbehind. A fixed-length lookbehind is only
     * limited by PCRE's ceiling of 65535 characters.
     */
    public const DEFAULT_MAX_LOOKBEHIND_LENGTH = 255;

    /**
     * How deep the parser may nest groups before it stops.
     */
    public const DEFAULT_MAX_RECURSION_DEPTH = 1024;

    // Visual snippet constants
    private const MAX_CONTEXT_WIDTH = 80;
    private const ELLIPSIS_LENGTH = 3;

    // Cache seed patterns
    private const CACHE_VERSION_PREFIX = '#cache=';
    private const TARGET_PREFIX = '#target=';

    /**
     * @param int            $maxPatternLength      Maximum allowed pattern length
     * @param int            $maxLookbehindLength   Maximum length of a variable-length lookbehind
     * @param CacheInterface $cache                 Cache for parsed trees
     * @param bool           $runtimePcreValidation Whether to also compile the pattern with the running PHP
     * @param int            $maxRecursionDepth     Maximum recursion depth during parsing
     * @param PcreTarget     $target                The PHP and PCRE2 judged
     * @param PcreEngine     $engine                Compiles the pattern with the running PHP
     */
    private function __construct(
        private int $maxPatternLength,
        private int $maxLookbehindLength,
        private CacheInterface $cache,
        private bool $runtimePcreValidation,
        private int $maxRecursionDepth,
        private PcreTarget $target,
        private PcreEngine $engine = new PcreEngine(),
    ) {}

    /**
     * @param array<string, mixed> $options The options Regex::create() takes
     */
    public static function create(array $options = []): self
    {
        return self::fromOptions(ParserOptions::fromArray($options));
    }

    public static function fromOptions(ParserOptions $options): self
    {
        return new self(
            $options->maxPatternLength,
            $options->maxLookbehindLength,
            $options->cache,
            $options->runtimePcreValidation,
            $options->maxRecursionDepth,
            $options->target,
        );
    }

    /**
     * Parse a regular expression into an Abstract Syntax Tree (AST).
     */
    public function parse(string $regex): RegexNode
    {
        return $this->doParse($regex);
    }

    /**
     * Parse a regular expression, returning a best-effort AST plus the parse
     * errors instead of throwing on invalid input.
     *
     * @param string $regex The regular expression to parse
     */
    public function parseTolerant(string $regex): TolerantParseResult
    {
        try {
            return new TolerantParseResult($this->doParse($regex));
        } catch (LexerException|ParserException $parseException) {
            $fallbackAst = $this->buildFallbackAstFromException($parseException, $regex);

            // The first error is the one validate() reports first.
            return new TolerantParseResult($fallbackAst, [$this->firstParseError($regex, $parseException)]);
        }
    }

    /**
     * Validate a regular expression and return detailed validation results.
     *
     * @param string $regex The regular expression to validate
     *
     * @return ValidationResult Detailed validation result
     */
    public function validate(string $regex): ValidationResult
    {
        try {
            $extractedPattern = $this->extractPatternSafely($regex);
            $ast = $this->parse($regex);

            $this->validateAst($ast, $extractedPattern);
            $complexityScore = $this->calculateComplexity($ast);

            if ($this->runtimePcreValidation) {
                $runtimeResult = $this->checkRuntimeCompilation($regex, $extractedPattern, $complexityScore);
                if (null !== $runtimeResult) {
                    return $runtimeResult;
                }
            }

            return new ValidationResult(true, null, $complexityScore);
        } catch (ResourceLimitException|RecursionLimitException $e) {
            // The library's own limits: the pattern is not read further.
            return $this->buildValidationFailure($e);
        } catch (LexerException|ParserException $e) {
            return $this->buildValidationFailure($this->firstParseError($regex, $e));
        } catch (ExceptionInterface $e) {
            // Only a judgement on the pattern; a failure of the library
            // surfaces, never reported as a pattern error.
            return $this->buildValidationFailure($e);
        }
    }

    /**
     * Parse a regular expression pattern with separate flags and delimiter.
     *
     * @param string $pattern   The regex pattern body
     * @param string $flags     The regex flags
     * @param string $delimiter The regex delimiter
     *
     * @return RegexNode Parsed AST
     */
    public function parsePattern(string $pattern, string $flags = '', string $delimiter = '/'): RegexNode
    {
        $closingDelimiter = PatternParser::closingDelimiter($delimiter);
        $regex = $delimiter.$pattern.$closingDelimiter.$flags;

        return $this->parse($regex);
    }

    /**
     * Tokenize a regex into a token stream with positions, as the parser
     * reads it for this target.
     */
    public function tokenize(string $regex): TokenStream
    {
        [$pattern, $flags] = PatternParser::extractPatternAndFlags($regex, $this->target);

        return (new Lexer($this->target))->tokenize($pattern, $flags);
    }

    /**
     * The PHP version and the PCRE2 release patterns are judged for.
     */
    public function target(): PcreTarget
    {
        return $this->target;
    }

    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * Get cache statistics.
     *
     * @return array{hits: int, misses: int} Cache hits and misses (zeroed if unsupported)
     */
    public function getCacheStats(): array
    {
        if (!$this->cache instanceof RemovableCacheInterface) {
            return ['hits' => 0, 'misses' => 0];
        }

        return $this->cache->getStats();
    }

    /**
     * Empty every process-wide cache the library keeps (useful for
     * long-running processes): the validator's, and every other one a class
     * filled so far (the lexer's, the compiler's, the scorer's, the sample
     * generator's, the automata's).
     */
    public function clearCaches(): void
    {
        Validator::clearCaches();
        StaticCaches::clear();
    }

    /**
     * The seed a pattern's cache key is hashed from, spelled out so callers
     * that need to predict where an entry lands share one implementation
     * with the cache itself. The seed's shape follows the cache version and
     * may change in any release.
     *
     * @internal
     *
     * @param string     $regex             The regex as written, delimiters included
     * @param PcreTarget $target            The PHP and PCRE2 judged
     * @param int        $maxRecursionDepth The parse recursion limit in force
     */
    public static function cacheSeed(string $regex, PcreTarget $target, int $maxRecursionDepth): string
    {
        // The PHP and the PCRE2 judged shape the tree, so they are part of
        // the key: a shared cache directory must not serve a tree read for
        // another engine. The recursion limit does too: a pattern cached
        // under a high limit may be one a lower limit refuses to parse at
        // all, and the exception must still be thrown.
        return $regex
            ."\n".self::CACHE_VERSION_PREFIX.self::CACHE_VERSION
            ."\n".self::TARGET_PREFIX.$target->cacheKey()
            ."\n#depth=".$maxRecursionDepth;
    }

    /**
     * The error PCRE meets first in a pattern that failed to parse with
     * $error: one earlier in the pattern, or $error itself. A judgement the
     * parser had to pass on as a parse error keeps its code.
     */
    private function firstParseError(string $regex, LexerException|ParserException $error): RegexException
    {
        $cause = $error->getPrevious();
        $judged = $cause instanceof SemanticErrorException && $cause->getPosition() === $error->getPosition() ? $cause : $error;

        return $this->earlierError($regex, $error) ?? $judged;
    }

    /**
     * PCRE reads the pattern in one pass, left to right. This library
     * tokenizes it whole, then parses it, then judges its escapes, so the
     * error it stops on may lie after one PCRE meets first: an escape PCRE
     * refuses, a class holding an unknown POSIX name or a reversed range,
     * an extended class, a version condition; what the tree read before
     * the error shows, a relative reference to no group, a reversed count,
     * a code point too large, a verb out of place or unknown; or, when
     * tokenizing failed or a name swallows the rest, a syntax error in what
     * was read before. The earliest of those before the error found is
     * PCRE's. What PCRE only finds once it has read the whole pattern, a
     * lookbehind's length or a group a name or number points to that does
     * not exist, is not.
     *
     * A "\p{" that no "}" closes, or a "(*" that no ")" closes, ends what is
     * read: PCRE looks for the closing character to the end of the pattern,
     * so nothing after it is judged, and it is judged last.
     */
    private function earlierError(string $regex, LexerException|ParserException $error): ?RegexException
    {
        $position = $error->getPosition() ?? 0;

        try {
            [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->target);
        } catch (ParserException) {
            return null;
        }

        $lexer = new Lexer($this->target);

        try {
            $lexer->tokenize($pattern, $flags);
        } catch (LexerException) {
            // The tokens read before the error are what is judged.
        }

        $tokens = $lexer->tokensRead();

        // The escapes, classes and conditions of an alphabetic assertion's
        // body are judged where they stand, as PCRE reads them: in one pass
        // reading each body in place, never again for the bodies inside it.
        try {
            $lexer->tokenizeInPlace($pattern, $flags);
        } catch (LexerException) {
            // The tokens read before the error are what is judged.
        }

        $inPlace = $lexer->tokensRead();

        // A "\p{" that no "}" closes, or a "(*" that no ")" closes, read
        // before the error: PCRE reads nothing past it, and refuses it at
        // the end of the pattern or where its name goes wrong. What stands
        // before it is judged, and it last.
        $swallowing = self::readUpToAnUnclosedName($inPlace, $pattern, $position);
        $read = $swallowing ?? $inPlace;
        $limit = null === $swallowing ? $position : \strlen($pattern) + 1;

        $earlier = (new Validator($this->maxLookbehindLength, $pattern, $this->target))
            ->firstEscapeErrorBefore($read, $pattern, $flags, $limit);

        $laterErrors = [
            $this->firstClassErrorBefore($read, $pattern, $flags, $delimiter, $limit),
            $this->firstExtendedClassErrorBefore($read, $pattern, $flags, $delimiter, $limit),
            $this->firstVersionConditionErrorBefore($read, $pattern, $flags, $delimiter, $limit),
            $this->firstErrorReadBefore($read, $pattern, $flags, $delimiter, $limit),
        ];
        foreach ($laterErrors as $laterError) {
            if (null !== $laterError && (null === $earlier || ($laterError->getPosition() ?? $position) < ($earlier->getPosition() ?? $position))) {
                $earlier = $laterError;
            }
        }

        if (null !== $swallowing || $error instanceof LexerException) {
            // The pattern read as if it ended where tokenizing stopped: an
            // error that ending causes lies there, and is not taken. A "(?"
            // read last is read with what follows it, which is not read:
            // what is refused past it is refused for want of that. Read up
            // to an unclosed name, the pattern is read whole.
            $syntaxTokens = $swallowing ?? $tokens;
            $stream = new TokenStream([...$syntaxTokens, new Token(TokenType::Eof, '', min($limit, \strlen($pattern)))], $pattern);
            $last = [] === $syntaxTokens ? null : $syntaxTokens[array_key_last($syntaxTokens)];
            $readUpTo = null !== $last && TokenType::GroupModifierOpen === $last->type ? $last->end() : $limit;

            try {
                (new TokenParser($this->maxRecursionDepth, $this->target))->parse($stream, $flags, $delimiter, \strlen($pattern));
            } catch (LexerException|ParserException $syntaxError) {
                $at = $syntaxError->getPosition() ?? $position;
                // A body that never closes is read to the end: an error met
                // there, other than that ")" missing, comes first.
                $atTheEnd = $at === $position && ErrorCode::GroupUnclosed === $error->getErrorCode()
                    && ErrorCode::GroupUnclosed !== $syntaxError->getErrorCode();
                if (($at < min($limit, $readUpTo) || $atTheEnd) && (null === $earlier || $at < ($earlier->getPosition() ?? $position))) {
                    return $syntaxError;
                }
            }
        }

        return $earlier;
    }

    /**
     * The tokens read up to the first "\p{" that no "}" closes, or "(*"
     * that no ")" closes, before $position, where parsing failed, that one
     * included; null when none stands there. PCRE reads nothing after it:
     * it looks for the "}" or ")" to the end of the pattern.
     *
     * @param list<Token> $tokens
     *
     * @return list<Token>|null
     */
    private static function readUpToAnUnclosedName(array $tokens, string $pattern, int $position): ?array
    {
        $lastBrace = strrpos($pattern, '}');

        foreach ($tokens as $index => $token) {
            if ($token->position >= $position) {
                break;
            }

            // The lexer reads "\p{" with no "}" after it as "\p" then "{".
            if (TokenType::LiteralEscaped === $token->type && \in_array($token->value, ['p', 'P'], true)
                && '{' === ($pattern[$token->end()] ?? '') && (false === $lastBrace || $lastBrace < $token->end())) {
                return \array_slice($tokens, 0, $index + 1);
            }

            // And "(*MARK:a" with no ")" after it as "(" then "*".
            $star = $tokens[$index + 1] ?? null;
            if (TokenType::GroupOpen === $token->type && null !== $star && TokenType::Quantifier === $star->type
                && $star->position === $token->end() && '*' === $star->value[0] && $star->position + 1 < \strlen($pattern)) {
                return \array_slice($tokens, 0, $index + 2);
            }
        }

        return null;
    }

    /**
     * The first error PCRE meets before $position, where parsing failed,
     * that the library only finds in the tree: a relative reference to no
     * group, a reversed count, a code point too large, a verb out of place
     * or unknown. The tokens are parsed once to learn how far they are read
     * whole; that much, its groups closed, is parsed and judged once.
     *
     * @param list<Token> $tokens the tokens read in place
     */
    private function firstErrorReadBefore(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?SemanticErrorException
    {
        if (!self::mayHoldAnErrorOfTheTree($tokens)) {
            return null;
        }

        // PCRE refuses the 251st level of parentheses as it opens it, at the
        // start of its body, though the groups are never closed.
        $tooDeep = self::openerPastTheNestingLimit($tokens);
        if (null !== $tooDeep) {
            return new SemanticErrorException('Parentheses are nested too deeply: PCRE allows at most 250 levels.', ErrorCode::GroupNestedTooDeep, $tooDeep->end(), $pattern, null, 'Flatten the pattern: drop groups that only wrap one item.');
        }

        $length = \strlen($pattern);
        $parser = new TokenParser($this->maxRecursionDepth, $this->target);

        try {
            $ast = $parser->parse(new TokenStream([...$tokens, new Token(TokenType::Eof, '', $length)], $pattern), $flags, $delimiter, $length);
        } catch (LexerException|ParserException) {
            $read = \array_slice($tokens, 0, $parser->tokensReadWhole());
            $cutAt = [] === $read ? 0 : $read[array_key_last($read)]->end();

            // Each group still open there is closed where the text read ends.
            $closers = [];
            foreach ($read as $token) {
                if (TokenType::GroupClose === $token->type) {
                    array_pop($closers);
                } elseif (self::opensGroup($token)) {
                    $closers[] = new Token(TokenType::GroupClose, ')', $cutAt, 0);
                }
            }

            try {
                $ast = (new TokenParser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([...$read, ...$closers, new Token(TokenType::Eof, '', $cutAt)], $pattern), $flags, $delimiter, $length);
            } catch (LexerException|ParserException) {
                // Not expected: these are the tokens the first parse read
                // whole, each group they open closed. Kept should one throw.
                return null;
            }
        }

        return (new Validator($this->maxLookbehindLength, $pattern, $this->target))->firstErrorReadBefore($ast, $position);
    }

    /**
     * Whether any of the tokens becomes a node the Validator may refuse as
     * it walks the tree: text, plain groups, lookarounds, classes (judged
     * apart), alternatives, dots, anchors, quotes, comments and repeats
     * without a count never are, and a pattern of them alone is not parsed
     * again.
     *
     * @param list<Token> $tokens
     */
    private static function mayHoldAnErrorOfTheTree(array $tokens): bool
    {
        // Past 250 levels, plain groups are refused too.
        if (null !== self::openerPastTheNestingLimit($tokens)) {
            return true;
        }

        foreach ($tokens as $token) {
            $plain = match ($token->type) {
                TokenType::Literal, TokenType::GroupOpen, TokenType::GroupClose, TokenType::Alternation,
                TokenType::Dot, TokenType::Anchor, TokenType::CharClassOpen, TokenType::CharClassClose,
                TokenType::Range, TokenType::Negation, TokenType::PosixClass, TokenType::QuoteModeStart,
                TokenType::QuoteModeEnd, TokenType::CommentOpen => true,
                TokenType::Quantifier => '{' !== $token->value[0],
                TokenType::PcreVerb => self::opensGroup($token) && null !== PcreVerb::read($token->value)->assertion,
                default => false,
            };

            if (!$plain) {
                return true;
            }
        }

        return false;
    }

    /**
     * The token that opens the 251st level of parentheses, past PCRE's
     * limit, or null when none does.
     *
     * @param list<Token> $tokens
     */
    private static function openerPastTheNestingLimit(array $tokens): ?Token
    {
        $open = [];
        foreach ($tokens as $token) {
            if (self::opensGroup($token)) {
                $open[] = $token;
                if (\count($open) > 250) {
                    return $token;
                }
            } elseif (TokenType::GroupClose === $token->type) {
                array_pop($open);
            }
        }

        return null;
    }

    /**
     * Whether the token opens a group a ")" closes: "(", "(?", "(?#", or the
     * opener of a body read in place, "(*pla:" or "(?*", whose value is its
     * text without the "(" and the character after it.
     */
    private static function opensGroup(Token $token): bool
    {
        return match ($token->type) {
            TokenType::GroupOpen, TokenType::GroupModifierOpen, TokenType::CommentOpen => true,
            TokenType::PcreVerb => $token->end() - $token->position === \strlen($token->value) + 2,
            default => false,
        };
    }

    /**
     * The first error in a character class the tokens close before $position,
     * where parsing failed: each such class is parsed alone and judged.
     *
     * @param list<Token> $tokens
     */
    private function firstClassErrorBefore(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?SemanticErrorException
    {
        $depth = 0;
        $opening = 0;

        foreach ($tokens as $index => $token) {
            if (TokenType::CharClassOpen === $token->type && 0 === $depth++) {
                $opening = $index;
            }

            if (TokenType::CharClassClose !== $token->type || 0 !== --$depth) {
                continue;
            }

            $class = \array_slice($tokens, $opening, $index - $opening + 1);

            try {
                $ast = (new TokenParser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([...$class, new Token(TokenType::Eof, '', $token->end())], $pattern), $flags, $delimiter, \strlen($pattern));
            } catch (LexerException|ParserException) {
                continue;
            }

            $error = (new Validator($this->maxLookbehindLength, $pattern, $this->target))
                ->firstErrorInClassBefore($ast, $token->position, $position);
            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }

    /**
     * The first error in an extended class "(?[...])" before $position: each
     * is read alone and judged, as PCRE judges it when it reads it.
     *
     * @param list<Token> $tokens
     */
    private function firstExtendedClassErrorBefore(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?RegexException
    {
        foreach ($tokens as $token) {
            if (TokenType::ExtendedClass !== $token->type || $token->end() > $position) {
                continue;
            }

            try {
                $ast = (new TokenParser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([$token, new Token(TokenType::Eof, '', $token->end())], $pattern), $flags, $delimiter, \strlen($pattern));
                $ast->accept(new Validator($this->maxLookbehindLength, $pattern, $this->target));
            } catch (RegexException $error) {
                if (($error->getPosition() ?? $position) < $position) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * The first error in a version condition, "(?(VERSION>=10.4)", the tokens
     * close before $position, where parsing failed: PCRE judges the version
     * as it reads it. Each such condition is parsed alone and judged.
     *
     * @param list<Token> $tokens
     */
    private function firstVersionConditionErrorBefore(array $tokens, string $pattern, string $flags, string $delimiter, int $position): ?SemanticErrorException
    {
        foreach ($tokens as $index => $open) {
            $group = $tokens[$index + 1] ?? null;
            if (TokenType::GroupModifierOpen !== $open->type || null === $group || TokenType::GroupOpen !== $group->type
                || $group->position !== $open->end() || !str_starts_with(substr($pattern, $group->end(), 7), 'VERSION')) {
                continue;
            }

            // The condition runs to the first ")", read from where it opens,
            // not from a copy of the rest of the tokens for each condition.
            $condition = [$open, $group];
            for ($at = $index + 2; isset($tokens[$at]); $at++) {
                $condition[] = $tokens[$at];
                if (TokenType::GroupClose === $tokens[$at]->type) {
                    break;
                }
            }

            $close = $condition[\count($condition) - 1];
            if (TokenType::GroupClose !== $close->type || $close->end() > $position) {
                continue;
            }

            try {
                $ast = (new TokenParser($this->maxRecursionDepth, $this->target))->parse(
                    new TokenStream([...$condition, new Token(TokenType::GroupClose, ')', $close->end()), new Token(TokenType::Eof, '', $close->end() + 1)], $pattern),
                    $flags,
                    $delimiter,
                    \strlen($pattern),
                );
                // A condition the parser reads as a name is looked up once the
                // whole pattern is read.
                if (!$ast->pattern instanceof ConditionalNode || !$ast->pattern->condition instanceof VersionConditionNode) {
                    continue;
                }

                $ast->accept(new Validator($this->maxLookbehindLength, $pattern, $this->target));
            } catch (SemanticErrorException $versionError) {
                if (($versionError->getPosition() ?? $position) < $position) {
                    return $versionError;
                }
            } catch (LexerException|ParserException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Perform the actual parsing with caching and resource limits.
     *
     * @param string $regex The regex to parse
     *
     * @return RegexNode The parsed AST
     */
    private function doParse(string $regex): RegexNode
    {
        $this->validateResourceLimits($regex);

        [$cachedAst, $cacheKey] = $this->loadFromCache($regex);
        if (null !== $cachedAst) {
            return $cachedAst;
        }

        $ast = $this->parseFromScratch($regex);
        $this->storeInCache($cacheKey, $ast);

        return $ast;
    }

    /**
     * Compiles the pattern with the running engine, without its JIT: its error, when it refuses it.
     */
    private function checkRuntimeCompilation(
        string $regex,
        ?string $pattern,
        int $complexityScore,
    ): ?ValidationResult {
        $error = $this->engine->compile($regex);
        if (null === $error) {
            return null;
        }

        $offset = $error->offset;
        $snippet = $this->buildVisualSnippet($pattern, $offset);
        $fullMessage = 'PCRE runtime error: '.$error->message;

        return new ValidationResult(
            false,
            $fullMessage,
            $complexityScore,
            ValidationErrorCategory::PcreRuntime,
            $offset,
            '' !== $snippet ? $snippet : null,
            null,
            ErrorCode::PcreRuntime,
        );
    }

    private function buildVisualSnippet(?string $pattern, ?int $position): string
    {
        if (null === $pattern || null === $position || $position < 0) {
            return '';
        }

        $length = \strlen($pattern);
        $caretIndex = $position > $length ? $length : $position;

        $lineStart = strrpos($pattern, "\n", $caretIndex - $length);
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $lineEnd = strpos($pattern, "\n", $caretIndex);
        $lineEnd = false === $lineEnd ? $length : $lineEnd;

        $lineNumber = substr_count($pattern, "\n", 0, $lineStart) + 1;

        $displayStart = $lineStart;
        $displayEnd = $lineEnd;

        $maxContextWidth = self::MAX_CONTEXT_WIDTH;
        if (($displayEnd - $displayStart) > $maxContextWidth) {
            $half = intdiv($maxContextWidth, 2);
            $displayStart = max($lineStart, $caretIndex - $half);
            $displayEnd = min($lineEnd, $displayStart + $maxContextWidth);
        }

        $prefixEllipsis = $displayStart > $lineStart ? '...' : '';
        $suffixEllipsis = $displayEnd < $lineEnd ? '...' : '';

        $excerpt = $prefixEllipsis
            .substr($pattern, $displayStart, $displayEnd - $displayStart)
            .$suffixEllipsis;

        // The window starts at or before the caret: the offset is never negative.
        $caretOffset = ('' === $prefixEllipsis ? 0 : self::ELLIPSIS_LENGTH) + ($caretIndex - $displayStart);

        $lineLabel = 'Line '.$lineNumber.': ';

        return $lineLabel.$excerpt."\n"
            .str_repeat(' ', \strlen($lineLabel) + $caretOffset).'^';
    }

    /**
     * Attempt to load a parsed regex from cache.
     *
     * @param string $regex The regex pattern to look up
     *
     * @return array{0: RegexNode|null, 1: string|null} Cached AST and cache key
     */
    private function loadFromCache(string $regex): array
    {
        if ($this->cache instanceof NullCache) {
            return [null, null];
        }

        // A cache that cannot answer is a cache miss, the way a cache that
        // cannot store is already treated: parsing the pattern again is
        // always an option, and it is never the pattern's fault.
        try {
            $cacheKey = $this->cache->generateKey($this->getCacheSeed($regex));
            $cachedResult = $this->cache->load($cacheKey);
        } catch (\Throwable) {
            return [null, null];
        }

        return [$cachedResult, $cacheKey];
    }

    private function getCacheSeed(string $regex): string
    {
        return self::cacheSeed($regex, $this->target, $this->maxRecursionDepth);
    }

    /**
     * Store a parsed regex AST in cache.
     *
     * @param string|null $cacheKey The cache key to store under
     * @param RegexNode   $ast      The AST to cache
     */
    private function storeInCache(?string $cacheKey, RegexNode $ast): void
    {
        if (null === $cacheKey) {
            return;
        }

        try {
            $this->cache->write($cacheKey, $ast);
        } catch (\Throwable) {
            // Cache failures are silently ignored
        }
    }

    /**
     * Safely extract pattern components from a regex string.
     *
     * @param string $regex The regex to extract from
     *
     * @return string|null Extracted pattern or null on failure
     */
    private function extractPatternSafely(string $regex): ?string
    {
        try {
            [$pattern] = PatternParser::extractPatternAndFlags($regex, $this->target);

            return (string) $pattern;
        } catch (ParserException) {
            return null;
        }
    }

    /**
     * Validate an AST with the appropriate validators.
     *
     * @param RegexNode   $ast     The AST to validate
     * @param string|null $pattern The original pattern for context
     */
    private function validateAst(RegexNode $ast, ?string $pattern): void
    {
        $validator = new Validator($this->maxLookbehindLength, $pattern, $this->target);
        $ast->accept($validator);
    }

    /**
     * Calculate complexity score for an AST.
     *
     * @param RegexNode $ast The AST to score
     *
     * @return int Complexity score
     */
    private function calculateComplexity(RegexNode $ast): int
    {
        $scorer = new ComplexityScorer();

        return $ast->accept($scorer);
    }

    /**
     * Build a validation failure result from an exception.
     *
     * @param ExceptionInterface $exception The judgement on the pattern
     *
     * @return ValidationResult Validation failure result
     */
    private function buildValidationFailure(ExceptionInterface $exception): ValidationResult
    {
        $errorMessage = $exception->getMessage();
        $visualSnippet = '';
        if (method_exists($exception, 'getVisualSnippet')) {
            $snippet = $exception->getVisualSnippet();
            $visualSnippet = \is_string($snippet) ? $snippet : '';
        }
        $position = null;
        $errorCode = null;
        $hint = null;

        if ($exception instanceof RegexException) {
            $position = $exception->getPosition();
            $errorCode = $exception->getErrorCode();
        }

        if ($exception instanceof SemanticErrorException) {
            $hint = $exception->getHint();
        }

        if ($exception instanceof SemanticErrorException) {
            return new ValidationResult(
                false,
                $errorMessage,
                0,
                ValidationErrorCategory::Semantic,
                $position,
                '' !== $visualSnippet ? $visualSnippet : null,
                $hint,
                $errorCode,
            );
        }

        return new ValidationResult(
            false,
            $errorMessage,
            0,
            ValidationErrorCategory::Syntax,
            $position,
            '' !== $visualSnippet ? $visualSnippet : null,
            null,
            $errorCode,
        );
    }

    /**
     * Build a fallback AST when parsing fails.
     *
     * @param LexerException|ParserException $exception The parse exception
     * @param string                         $regex     The original regex
     *
     * @return RegexNode Fallback AST
     */
    private function buildFallbackAstFromException(LexerException|ParserException $exception, string $regex): RegexNode
    {
        [$pattern, $flags, $delimiter, $length] = $this->safeExtractPattern($regex);

        return $this->buildFallbackAst($pattern, $flags, $delimiter, $length, $exception->getPosition());
    }

    /**
     * Safely extract pattern components with error handling.
     *
     * @return array{0: string, 1: string, 2: string, 3: int} Pattern components
     */
    private function safeExtractPattern(string $regex): array
    {
        try {
            [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->target);
            $pattern = (string) $pattern;
            $flags = (string) $flags;
            $delimiter = (string) $delimiter;
            $patternLength = \strlen($pattern);

            return [$pattern, $flags, $delimiter, $patternLength];
        } catch (ParserException) {
            return [$regex, '', '/', \strlen($regex)];
        }
    }

    /**
     * Build a fallback AST for partial parsing.
     *
     * @param string   $pattern       The pattern string
     * @param string   $flags         Regex flags
     * @param string   $delimiter     Pattern delimiter
     * @param int      $patternLength Length of the pattern
     * @param int|null $errorPosition Position where error occurred
     *
     * @return RegexNode Fallback AST
     */
    private function buildFallbackAst(
        string $pattern,
        string $flags,
        string $delimiter,
        int $patternLength,
        ?int $errorPosition
    ): RegexNode {
        $validPattern = null === $errorPosition
            ? $pattern
            : substr($pattern, 0, max(0, $errorPosition));

        $literalNode = new LiteralNode($validPattern, 0, \strlen($validPattern));
        $sequenceNode = new SequenceNode([$literalNode], 0, $literalNode->getEndPosition());

        return new RegexNode($sequenceNode, $flags, $delimiter, 0, $patternLength);
    }

    /**
     * Validate resource limits for the regex pattern.
     *
     * @param string $regex The regex to validate
     */
    private function validateResourceLimits(string $regex): void
    {
        if (\strlen($regex) > $this->maxPatternLength) {
            // Past the last character allowed, counted from the body: after
            // the whitespace PHP skips and the opening delimiter. The snippet
            // shows the pattern as written, from its delimiter.
            $trimmed = Ascii::trimLeadingSpaces($regex);
            $bodyStart = \strlen($regex) - \strlen($trimmed) + 1;
            $offset = max(0, $this->maxPatternLength - $bodyStart);

            throw ResourceLimitException::withContext(
                \sprintf('Regex pattern exceeds maximum length of %d characters.', $this->maxPatternLength),
                ErrorCode::PatternTooLong,
                $offset,
                $trimmed,
                null,
                $offset + 1,
            );
        }
    }

    /**
     * Parse a regex from scratch without using cache.
     *
     * @param string $regex The regex to parse
     *
     * @return RegexNode The parsed AST
     */
    private function parseFromScratch(string $regex): RegexNode
    {
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->target);
        $tokenStream = (new Lexer($this->target))->tokenize($pattern, $flags);
        $parser = new TokenParser($this->maxRecursionDepth, $this->target);

        return $parser->parse($tokenStream, $flags, $delimiter, \strlen($pattern));
    }
}

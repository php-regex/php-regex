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
use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Cache\PsrCacheAdapter;
use PHPRegex\Parser\Cache\PsrSimpleCacheAdapter;
use PHPRegex\Parser\Cache\RemovableCacheInterface;
use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Exception\VisualContextInterface;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\Syntax\TokenParser;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Validation\FirstErrorLocator;
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
 *
 * @phpstan-import-type CacheStats from RemovableCacheInterface
 * @phpstan-import-type OptionsArray from ParserOptions
 */
final class RegexParser
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
    public const CACHE_VERSION = 'ast-0a7a941f5787fc54439766797e8f096f';

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

    /**
     * How many validate() results a parser keeps, the latest read first.
     */
    private const MAX_KEPT_RESULTS = 1024;

    /**
     * Past this length a pattern's result is kept under a hash of it, so
     * the results kept hold no more than a bounded copy of the patterns.
     */
    private const MAX_LITERAL_KEY_LENGTH = 256;

    // Cache seed patterns
    private const CACHE_VERSION_PREFIX = '#cache=';
    private const TARGET_PREFIX = '#target=';

    /**
     * The trees a persistent cache gave or took in this process.
     */
    private readonly ?ArrayCache $front;

    /**
     * What validate() answered, by pattern: a result depends only on the
     * pattern and on this parser's options and target, all fixed. The
     * least recently read goes first past MAX_KEPT_RESULTS.
     *
     * @var array<string, ValidationResult>
     */
    private array $results = [];

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
        private readonly int $maxPatternLength,
        private readonly int $maxLookbehindLength,
        private readonly CacheInterface $cache,
        private readonly bool $runtimePcreValidation,
        private readonly int $maxRecursionDepth,
        private readonly PcreTarget $target,
        private readonly PcreEngine $engine = new PcreEngine(),
    ) {
        // A directory or a PSR pool answers a tree it fetches and
        // unserializes: the trees read or written in this process stay in
        // memory in front of it, under the same key. A cache the application
        // writes keeps its own policy.
        $this->front = $cache instanceof FilesystemCache || $cache instanceof PsrCacheAdapter || $cache instanceof PsrSimpleCacheAdapter
            ? new ArrayCache()
            : null;
    }

    /**
     * @param OptionsArray $options The options Regex::create() takes
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
        } catch (LimitException $limit) {
            // The library gave up where it stopped: no earlier error is
            // looked for, the pattern was not read further.
            return new TolerantParseResult($this->buildFallbackAstFromException($limit, $regex), [$limit]);
        } catch (LexerException|ParserException $parseException) {
            $fallbackAst = $this->buildFallbackAstFromException($parseException, $regex);

            // The first error is the one validate() reports first.
            return new TolerantParseResult($fallbackAst, [$this->firstParseError($regex, $parseException)]);
        }
    }

    /**
     * Validate a regular expression and return detailed validation results.
     *
     * The parser keeps the results of the last 1 024 patterns it judged: a
     * pattern judged again gets the same result object, at the cost of a
     * lookup, without reading the tree cache again. clearCaches() empties
     * them.
     *
     * @param string $regex The regular expression to validate
     *
     * @return ValidationResult Detailed validation result
     */
    public function validate(string $regex): ValidationResult
    {
        $key = \strlen($regex) <= self::MAX_LITERAL_KEY_LENGTH ? 'p'.$regex : 'h'.hash('sha256', $regex);
        if (isset($this->results[$key])) {
            $result = $this->results[$key];
            // Read again, it is the latest.
            unset($this->results[$key]);

            return $this->results[$key] = $result;
        }

        $result = $this->judge($regex);
        $this->results[$key] = $result;
        if (\count($this->results) > self::MAX_KEPT_RESULTS) {
            unset($this->results[array_key_first($this->results)]);
        }

        return $result;
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
     * The tokens of a regex as the parser reads them for this target, in
     * source order and covering the pattern body, each with its byte
     * offsets, the end-of-pattern token last. How many characters one token
     * covers is not promised.
     *
     * @return list<Token>
     */
    public function tokenize(string $regex): array
    {
        return LibraryPcre::run(function () use ($regex): array {
            [$pattern, $flags] = PatternParser::extractPatternAndFlags($regex, $this->target);

            return (new Lexer($this->target))->tokenize($pattern, $flags)->getTokens();
        });
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
     * @return CacheStats Cache hits and misses (zeroed if unsupported)
     */
    public function getCacheStats(): array
    {
        $stats = $this->cache instanceof RemovableCacheInterface ? $this->cache->getStats() : ['hits' => 0, 'misses' => 0];
        if (null !== $this->front) {
            // A tree found in memory never reached the persistent cache.
            $stats['hits'] += $this->front->getStats()['hits'];
        }

        return $stats;
    }

    /**
     * Empty every process-wide cache the library keeps (useful for
     * long-running processes): every one a class filled so far (the
     * lexer's, the validator's, the compiler's, the scorer's, the sample
     * generator's, the automata's), and the validate() results this parser
     * kept.
     */
    public function clearCaches(): void
    {
        StaticCaches::clear();
        $this->front?->clear();
        $this->results = [];
    }

    /**
     * Keeps a tree this parser built for $regex, in another process or
     * earlier, where the next parse() of $regex looks first: in memory when
     * a persistent cache stands behind it, never written to disk again.
     *
     * @internal
     */
    public function remember(string $regex, RegexNode $ast): void
    {
        if ($this->cache instanceof NullCache) {
            return;
        }

        try {
            ($this->front ?? $this->cache)->write($this->cache->generateKey($this->getCacheSeed($regex)), $ast);
        } catch (\Throwable) {
            // A cache that cannot store leaves the next parse() to parse.
        }
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
     * The verdict on a pattern, worked out.
     */
    private function judge(string $regex): ValidationResult
    {
        try {
            $ast = $this->parse($regex);

            // The walk and the score run the library's regexes only: one
            // window of limits for all of them.
            [$extractedPattern, $complexityScore] = LibraryPcre::run(function () use ($regex, $ast): array {
                $extractedPattern = $this->extractPatternSafely($regex);
                $this->validateAst($ast, $extractedPattern);

                return [$extractedPattern, $this->calculateComplexity($ast)];
            });

            if ($this->runtimePcreValidation) {
                $runtimeResult = $this->checkRuntimeCompilation($regex, $extractedPattern, $complexityScore);
                if (null !== $runtimeResult) {
                    return $runtimeResult;
                }
            }

            return new ValidationResult(true, null, $complexityScore);
        } catch (LimitException $e) {
            // The library's own limits: the pattern is not read further,
            // and no verdict is given on it.
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
     * The error PCRE meets first in a pattern that failed to parse with
     * $error: one earlier in the pattern, or $error itself. A judgement the
     * parser had to pass on as a parse error keeps its code.
     */
    private function firstParseError(string $regex, LexerException|ParserException $error): RegexException
    {
        $cause = $error->getPrevious();
        $judged = $cause instanceof SemanticErrorException && $cause->position === $error->position ? $cause : $error;

        return (new FirstErrorLocator($this->maxRecursionDepth, $this->maxLookbehindLength, $this->target))->earlierError($regex, $error) ?? $judged;
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
            $cachedResult = $this->front?->load($cacheKey);
            if (null !== $cachedResult) {
                return [$cachedResult, $cacheKey];
            }

            $cachedResult = $this->cache->load($cacheKey);
        } catch (\Throwable) {
            return [null, null];
        }

        if (null !== $cachedResult) {
            $this->front?->write($cacheKey, $cachedResult);
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

        $this->front?->write($cacheKey, $ast);

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
        if ($exception instanceof VisualContextInterface) {
            $visualSnippet = $exception->getVisualSnippet();
        }
        $position = null;
        $errorCode = null;
        $hint = null;

        if ($exception instanceof RegexException) {
            $position = $exception->position;
            $errorCode = $exception->errorCode;
        }

        if ($exception instanceof SemanticErrorException) {
            $hint = $exception->hint;
        }

        if (null !== $errorCode) {
            $hint ??= self::commonHint($errorCode);
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

        if ($exception instanceof LimitException) {
            return new ValidationResult(
                false,
                $errorMessage,
                0,
                ValidationErrorCategory::Limit,
                $position,
                '' !== $visualSnippet ? $visualSnippet : null,
                \sprintf(
                    'A limit of this library, not of PCRE: raise the "%s" option to analyze the pattern.',
                    ErrorCode::PatternTooLong === $exception->errorCode ? 'max_pattern_length' : 'max_recursion_depth',
                ),
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
            $hint,
            $errorCode,
        );
    }

    /**
     * Build a fallback AST when parsing fails.
     *
     * @param LexerException|LimitException|ParserException $exception The parse exception
     * @param string                                        $regex     The original regex
     *
     * @return RegexNode Fallback AST
     */
    private function buildFallbackAstFromException(LexerException|LimitException|ParserException $exception, string $regex): RegexNode
    {
        [$pattern, $flags, $delimiter, $length] = $this->safeExtractPattern($regex);

        return $this->buildFallbackAst($pattern, $flags, $delimiter, $length, $exception->position);
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
        // Only the library's regexes run while a pattern is read, the cache
        // being read and written around it: one window of limits for all.
        return LibraryPcre::run(function () use ($regex): RegexNode {
            [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->target);
            $tokenStream = (new Lexer($this->target))->tokenize($pattern, $flags);
            $parser = new TokenParser($this->maxRecursionDepth, $this->target);

            return $parser->parse($tokenStream, $flags, $delimiter, \strlen($pattern));
        });
    }

    /**
     * The fix for the mistakes users make most, by error code, when the
     * error carries none of its own.
     */
    private static function commonHint(ErrorCode $errorCode): ?string
    {
        return match ($errorCode) {
            ErrorCode::CharclassUnclosed => 'Close the class with "]", or write "\\[" to match a literal "[".',
            ErrorCode::QuantifierNothingToRepeat => 'Put the quantifier after the item it repeats, or escape it with a backslash to match it literally.',
            ErrorCode::QuantifierInvalidRange => 'Write the smaller count first.',
            ErrorCode::QuantifierTooBig => 'PCRE counts at most 65535 repetitions: lower the count, or use "*" or "+" for an open-ended repeat.',
            ErrorCode::RangeReversed => 'Write the lower end of the range first, or escape the "-" as "\\-" to match it literally.',
            ErrorCode::DelimiterInvalid => 'Wrap the pattern in a pair of delimiters, such as "/.../" or "#...#".',
            ErrorCode::GroupNameInvalid => 'Start a group name with a letter or "_", then use letters, digits and "_" only.',
            ErrorCode::GroupDuplicateName => 'Rename one of the groups, or allow duplicate names with (?J).',
            ErrorCode::SubroutineMissingNamedGroup => 'Name the group the call reaches, or call a group the pattern names.',
            ErrorCode::UnicodePropertyInvalid => 'Use a property PCRE2 knows, such as \\p{L}, \\p{Lu}, \\p{N} or a script such as \\p{Greek}.',
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser;

use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Automata\Solver\RegexSolver;
use RegexParser\Cache\CacheInterface;
use RegexParser\Cache\CachePayloadDecoder;
use RegexParser\Cache\NullCache;
use RegexParser\Cache\RemovableCacheInterface;
use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Exception\RecursionLimitException;
use RegexParser\Exception\RegexException;
use RegexParser\Exception\RegexParserExceptionInterface;
use RegexParser\Exception\ResourceLimitException;
use RegexParser\Exception\SampleGenerationException;
use RegexParser\Exception\SemanticErrorException;
use RegexParser\Internal\PatternParser;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\SequenceNode;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\NodeVisitor\ComplexityScoreNodeVisitor;
use RegexParser\NodeVisitor\ConsoleHighlighterVisitor;
use RegexParser\NodeVisitor\ExplainNodeVisitor;
use RegexParser\NodeVisitor\HtmlExplainNodeVisitor;
use RegexParser\NodeVisitor\HtmlHighlighterVisitor;
use RegexParser\NodeVisitor\LinterNodeVisitor;
use RegexParser\NodeVisitor\LiteralExtractorNodeVisitor;
use RegexParser\NodeVisitor\OptimizerNodeVisitor;
use RegexParser\NodeVisitor\SampleGeneratorNodeVisitor;
use RegexParser\NodeVisitor\ValidatorNodeVisitor;
use RegexParser\ReDoS\ReDoSAnalysis;
use RegexParser\ReDoS\ReDoSAnalyzer;
use RegexParser\ReDoS\ReDoSConfirmOptions;
use RegexParser\ReDoS\ReDoSMode;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\Transpiler\RegexTranspiler;
use RegexParser\Transpiler\TranspileOptions;
use RegexParser\Transpiler\TranspileResult;

/**
 * Entry point for the RegexParser library.
 *
 * Provides methods for parsing, validating, optimizing, and analyzing
 * regular expressions. Supports caching and runtime PCRE validation.
 */
final readonly class Regex
{
    public const VERSION = '1.3.0';
    public const VERSION_ID = 10300;

    /**
     * Cache version for AST serialization.
     *
     * A cached tree is only worth restoring while the current code would
     * build the same one, so this is not a number anybody raises by hand: it
     * is a fingerprint of the code that decides what a pattern parses into —
     * the lexer, the parser, the nodes and the readers they use.
     *
     * "task cache-version" writes it, "task lint" runs that, and the test
     * suite fails while the constant and the code disagree.
     */
    public const CACHE_VERSION = 'ast-bc97c7f88a84bcfef0926581a0ccfff9';

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

    // Visual snippet constants
    private const MAX_CONTEXT_WIDTH = 80;
    private const ELLIPSIS_LENGTH = 3;

    // Cache seed patterns
    private const CACHE_VERSION_PREFIX = '#cache=';
    private const TARGET_PREFIX = '#target=';

    /**
     * Create a new Regex instance with specified configuration.
     *
     * @param int            $maxPatternLength      Maximum allowed pattern length
     * @param int            $maxLookbehindLength   Maximum length of a variable-length lookbehind
     * @param CacheInterface $cache                 Cache implementation for parsed patterns
     * @param array<string>  $redosIgnoredPatterns  Patterns to ignore in ReDoS analysis
     * @param bool           $runtimePcreValidation Whether to validate against PCRE runtime
     * @param int            $maxRecursionDepth     Maximum recursion depth during parsing
     * @param PcreTarget     $target                The PHP and PCRE2 judged
     */
    private function __construct(
        private int $maxPatternLength,
        private int $maxLookbehindLength,
        private CacheInterface $cache,
        private array $redosIgnoredPatterns,
        private bool $runtimePcreValidation,
        private int $maxRecursionDepth,
        private PcreTarget $target,
    ) {}

    /**
     * Create a new Regex instance with optional configuration.
     *
     * Every call returns a fresh instance; nothing is memoized.
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return self New Regex instance
     */
    public static function create(array $options = []): self
    {
        $configuration = RegexOptions::fromArray($options);

        return new self(
            $configuration->maxPatternLength,
            $configuration->maxLookbehindLength,
            $configuration->cache,
            $configuration->redosIgnoredPatterns,
            $configuration->runtimePcreValidation,
            $configuration->maxRecursionDepth,
            $configuration->target,
        );
    }

    /**
     * Parse a regular expression into an Abstract Syntax Tree (AST).
     *
     * @param string $regex    The regular expression to parse
     * @param bool   $tolerant Whether to return a tolerant result on parse errors
     *
     * @return ($tolerant is true ? TolerantParseResult : RegexNode) Parsed AST or tolerant result
     */
    public function parse(string $regex, bool $tolerant = false): RegexNode|TolerantParseResult
    {
        if ($tolerant) {
            return $this->parseTolerant($regex);
        }

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

            return new TolerantParseResult($fallbackAst, [$parseException]);
        }
    }

    /**
     * Perform comprehensive analysis of a regex pattern.
     *
     * @param string $regex The regular expression to analyze
     *
     * @return AnalysisReport Complete analysis report
     */
    public function analyze(string $regex): AnalysisReport
    {
        $errors = [];
        $isValid = true;

        $validation = $this->validate($regex);
        if (!$validation->isValid) {
            $isValid = false;
            if (null !== $validation->error && '' !== $validation->error) {
                $errors[] = $validation->error;
            }
        }

        $lintIssues = [];
        $highlighted = '';
        $explain = '';
        $optimizations = new OptimizationResult($regex, $regex, []);

        $redos = $this->redos($regex);

        if ($isValid) {
            // Only what the pattern itself can cause is reported as an error:
            // a failure of any other kind is a bug in the library, and a
            // report saying "invalid pattern" would bury it.
            try {
                $ast = $this->parse($regex, false);
                $linter = new LinterNodeVisitor();
                $ast->accept($linter);
                $lintIssues = $linter->getIssues();
            } catch (RegexParserExceptionInterface $e) {
                $errors[] = $e->getMessage();
                $isValid = false;
            }

            try {
                $optimizations = $this->optimize($regex);
            } catch (RegexParserExceptionInterface $e) {
                $errors[] = $e->getMessage();
                $isValid = false;
            }

            try {
                $explain = $this->explain($regex);
            } catch (RegexParserExceptionInterface $e) {
                $errors[] = $e->getMessage();
                $isValid = false;
            }

            try {
                $highlighted = $this->highlight($regex);
            } catch (RegexParserExceptionInterface $e) {
                $errors[] = $e->getMessage();
                $isValid = false;
            }
        }

        return new AnalysisReport(
            $isValid,
            $errors,
            $lintIssues,
            $redos,
            $optimizations,
            $explain,
            $highlighted,
        );
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
            $ast = $this->parse($regex, false);

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
            // A judgement the parser had to pass on as a parse error keeps its code.
            $cause = $e->getPrevious();
            $judged = $cause instanceof SemanticErrorException && $cause->getPosition() === $e->getPosition() ? $cause : $e;

            return $this->buildValidationFailure($this->earlierError($regex, $e) ?? $judged);
        } catch (\Throwable $e) {
            return $this->buildValidationFailure($e);
        }
    }

    /**
     * Analyze a regular expression for potential ReDoS (Regular Expression Denial of Service) vulnerabilities.
     *
     * @param string             $regex     The regular expression to analyze
     * @param ReDoSSeverity|null $threshold Minimum severity level to report
     *
     * @return ReDoSAnalysis Detailed ReDoS analysis results
     */
    public function redos(
        string $regex,
        ?ReDoSSeverity $threshold = null,
        ReDoSMode $mode = ReDoSMode::THEORETICAL,
        ?ReDoSConfirmOptions $confirmOptions = null,
    ): ReDoSAnalysis {
        $analyzer = new ReDoSAnalyzer($this, $this->redosIgnoredPatterns);

        return $analyzer->analyze($regex, $threshold, $mode, $confirmOptions);
    }

    /**
     * Optimize a regular expression for better performance.
     *
     * @param string                                                                                                                                                                                                  $regex   The regular expression to optimize
     * @param array{digits?: bool, word?: bool, ranges?: bool, canonicalizeCharClasses?: bool, autoPossessify?: bool, allowAlternationFactorization?: bool, minQuantifierCount?: int, verifyWithAutomata?: bool, ...} $options Optimization options (unknown keys are ignored)
     *
     * @return OptimizationResult Optimization results with changes applied
     */
    public function optimize(string $regex, array $options = []): OptimizationResult
    {
        $verifyWithAutomata = (bool) ($options['verifyWithAutomata'] ?? false);
        $optimizer = new OptimizerNodeVisitor(
            optimizeDigits: (bool) ($options['digits'] ?? true),
            optimizeWord: (bool) ($options['word'] ?? true),
            ranges: (bool) ($options['ranges'] ?? true),
            canonicalizeCharClasses: (bool) ($options['canonicalizeCharClasses'] ?? true),
            autoPossessify: (bool) ($options['autoPossessify'] ?? false),
            allowAlternationFactorization: (bool) ($options['allowAlternationFactorization'] ?? false),
            minQuantifierCount: (int) ($options['minQuantifierCount'] ?? 4),
        );

        $ast = $this->parse($regex, false);
        $optimizedAst = $ast->accept($optimizer);

        if (!$optimizedAst instanceof RegexNode) {
            throw new RegexException('Optimizer returned an unexpected AST root.');
        }

        if ($optimizedAst === $ast) {
            return new OptimizationResult($regex, $regex, []);
        }

        $pretty = str_contains($ast->flags, 'x');
        // Both sides are normalized so that a pattern only counts as optimized
        // when its structure changed, not when it merely spells an escape
        // differently.
        $originalCompiled = $ast->accept(new CompilerNodeVisitor($pretty, preserveSpelling: false));
        $optimizedCompiled = $optimizedAst->accept(new CompilerNodeVisitor($pretty, preserveSpelling: false));

        [$originalPattern] = PatternParser::extractPatternAndFlags($originalCompiled, $this->target);
        [$optimizedPatternPart] = PatternParser::extractPatternAndFlags($optimizedCompiled, $this->target);

        if ($originalPattern === $optimizedPatternPart) {
            [$pattern, , $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->target);
            $closingDelimiter = PatternParser::closingDelimiter($delimiter);
            $optimizedPattern = $delimiter.$pattern.$closingDelimiter.$optimizedAst->flags;
        } else {
            $optimizedPattern = $optimizedCompiled;
        }

        if ($optimizedPattern !== $regex && $verifyWithAutomata) {
            $isEquivalent = $this->verifyOptimizedPatternWithAutomata($regex, $optimizedPattern);
            if (false === $isEquivalent) {
                return new OptimizationResult($regex, $regex, []);
            }
        }

        $appliedChanges = $optimizedPattern === $regex ? [] : ['Optimized pattern.'];

        return new OptimizationResult($regex, $optimizedPattern, $appliedChanges);
    }

    /**
     * Transpile a PCRE regex literal to another regex dialect.
     */
    public function transpile(string $regex, string $target, ?TranspileOptions $options = null): TranspileResult
    {
        $transpiler = new RegexTranspiler($this);

        return $transpiler->transpile($regex, $target, $options);
    }

    /**
     * Generate a human-readable explanation of the regular expression.
     *
     * @param string              $regex  The regular expression to explain
     * @param string|OutputFormat $format Output format (OutputFormat::TEXT or OutputFormat::HTML)
     *
     * @return string Formatted explanation
     */
    public function explain(string $regex, string|OutputFormat $format = OutputFormat::TEXT): string
    {
        $format = \is_string($format) ? $format : $format->value;
        $explanationVisitor = $this->createExplanationVisitor($format);

        $ast = $this->parse($regex, false);

        return $ast->accept($explanationVisitor);
    }

    /**
     * Highlight a regex for console or HTML output.
     *
     * @param string              $regex  The regular expression to highlight
     * @param string|OutputFormat $format Output format (OutputFormat::CONSOLE or OutputFormat::HTML)
     */
    public function highlight(string $regex, string|OutputFormat $format = OutputFormat::CONSOLE): string
    {
        $format = \is_string($format) ? $format : $format->value;
        $ast = $this->parse($regex, false);

        $visitor = 'html' === $format
            ? new HtmlHighlighterVisitor()
            : new ConsoleHighlighterVisitor();

        return $ast->accept($visitor);
    }

    /**
     * Extract literal strings from a regular expression pattern.
     *
     * @param string $regex The regular expression to analyze
     *
     * @return LiteralExtractionResult Extracted literals and search patterns
     */
    public function literals(string $regex): LiteralExtractionResult
    {
        $ast = $this->parse($regex, false);

        $literalSet = $ast->accept(new LiteralExtractorNodeVisitor());

        $uniqueLiterals = $this->extractUniqueLiterals($literalSet);
        $searchPatterns = $this->buildSearchPatterns($literalSet);
        $confidenceLevel = $this->determineConfidenceLevel($literalSet);

        return new LiteralExtractionResult($uniqueLiterals, $searchPatterns, $confidenceLevel, $literalSet);
    }

    /**
     * Generate a sample string that matches the regular expression.
     *
     * @param string $regex The regular expression to generate a sample for
     *
     * @throws SampleGenerationException when no sample the running engine matches was found
     *
     * @return string Generated sample string
     */
    public function generate(string $regex): string
    {
        $ast = $this->parse($regex, false);
        $generator = new SampleGeneratorNodeVisitor();

        // Generation is best-effort (lookaround hints, negated classes, ...):
        // verify the sample against the real engine and retry a few times
        // before settling for the last attempt.
        $sample = '';
        $attempts = [];
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $sample = $ast->accept($generator);

            $matches = @preg_match($regex, $sample);
            if (false === $matches || 1 === $matches) {
                // Either verified, or the pattern cannot be evaluated by
                // this PCRE runtime — return what we have.
                return $sample;
            }

            $attempts[$sample] = true;
        }

        // An assertion on what surrounds the match, as "\b" or "(?!^)", may
        // hold once the sample has text around it.
        foreach (array_keys($attempts) as $attempt) {
            foreach (['a', ' ', "\n"] as $padding) {
                foreach ([$padding.$attempt, $attempt.$padding, $padding.$attempt.$padding] as $padded) {
                    if (1 === @preg_match($regex, $padded)) {
                        return $padded;
                    }
                }
            }
        }

        throw new SampleGenerationException(\sprintf('No sample matching %s was found: the pattern may match nothing, or its assertions ask for more than the samples give.', $regex));
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

        return $this->parse($regex, false);
    }

    /**
     * Tokenize a regex into a token stream with positions.
     *
     * This exposes the same lexer the parser uses internally, including all
     * literal characters, whitespace, and comment markers, each tagged with
     * its byte offset in the pattern body. Combined with the delimiter and
     * flags extracted via PatternParser, this allows reconstructing the
     * original pattern and mapping nodes back to their exact locations.
     *
     * @param PcreTarget|null $target the PHP and PCRE2 judged; the running ones when null
     */
    public static function tokenize(string $regex, ?PcreTarget $target = null): TokenStream
    {
        [$pattern, $flags] = PatternParser::extractPatternAndFlags($regex, $target);

        return (new Lexer($target))->tokenize($pattern, $flags);
    }

    /**
     * The PHP version and the PCRE2 release this instance judges patterns
     * for.
     */
    public function target(): PcreTarget
    {
        return $this->target;
    }

    /**
     * Create a new Regex instance.
     *
     * @deprecated use Regex::create() instead; both behave identically
     *
     * @param array<string, mixed> $options Configuration options
     *
     * @return self New Regex instance
     */
    public static function new(array $options = []): self
    {
        return self::create($options);
    }

    /**
     * Get the cache instance.
     */
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
     * Clear static validator caches (useful for long-running processes).
     */
    public function clearValidatorCaches(): void
    {
        ValidatorNodeVisitor::clearCaches();
    }

    /**
     * The seed a pattern's cache key is hashed from, spelled out so callers
     * that need to predict where an entry lands share one implementation
     * with the cache itself.
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
     * PCRE reads the pattern in one pass, left to right. This library
     * tokenizes it whole, then parses it, then judges its escapes, so the
     * error it stops on may lie after one PCRE meets first: an escape PCRE
     * refuses, a class holding an unknown POSIX name or a reversed range,
     * or, when tokenizing failed, a syntax error in what was read before.
     * The earliest of those before the error found is PCRE's.
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
        $earlier = (new ValidatorNodeVisitor($this->maxLookbehindLength, $pattern, $this->target))
            ->firstEscapeErrorBefore($tokens, $pattern, $flags, $position);

        $classErrors = [
            $this->firstClassErrorBefore($tokens, $pattern, $flags, $delimiter, $position),
            $this->firstExtendedClassErrorBefore($tokens, $pattern, $flags, $delimiter, $position),
        ];
        foreach ($classErrors as $classError) {
            if (null !== $classError && (null === $earlier || ($classError->getPosition() ?? $position) < ($earlier->getPosition() ?? $position))) {
                $earlier = $classError;
            }
        }

        if ($error instanceof LexerException) {
            // The pattern read as if it ended where tokenizing stopped: an
            // error that ending causes lies there, and is not taken.
            $stream = new TokenStream([...$tokens, new Token(TokenType::T_EOF, '', $position)], $pattern);

            try {
                (new Parser($this->maxRecursionDepth, $this->target))->parse($stream, $flags, $delimiter, $position);
            } catch (LexerException|ParserException $syntaxError) {
                $at = $syntaxError->getPosition() ?? $position;
                if ($at < $position && (null === $earlier || $at < ($earlier->getPosition() ?? $position))) {
                    return $syntaxError;
                }
            }
        }

        return $earlier;
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
            if (TokenType::T_CHAR_CLASS_OPEN === $token->type && 0 === $depth++) {
                $opening = $index;
            }

            if (TokenType::T_CHAR_CLASS_CLOSE !== $token->type || 0 !== --$depth) {
                continue;
            }

            $class = \array_slice($tokens, $opening, $index - $opening + 1);

            try {
                $ast = (new Parser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([...$class, new Token(TokenType::T_EOF, '', $token->end())], $pattern), $flags, $delimiter, \strlen($pattern));
            } catch (LexerException|ParserException) {
                continue;
            }

            $error = (new ValidatorNodeVisitor($this->maxLookbehindLength, $pattern, $this->target))
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
            if (TokenType::T_EXTENDED_CLASS !== $token->type || $token->end() > $position) {
                continue;
            }

            try {
                $ast = (new Parser($this->maxRecursionDepth, $this->target))
                    ->parse(new TokenStream([$token, new Token(TokenType::T_EOF, '', $token->end())], $pattern), $flags, $delimiter, \strlen($pattern));
                $ast->accept(new ValidatorNodeVisitor($this->maxLookbehindLength, $pattern, $this->target));
            } catch (RegexException $error) {
                if (($error->getPosition() ?? $position) < $position) {
                    return $error;
                }
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
     * Checks runtime compilation by attempting to use the pattern with preg_match and capturing warnings.
     */
    private function checkRuntimeCompilation(
        string $regex,
        ?string $pattern,
        int $complexityScore,
    ): ?ValidationResult {
        $warning = null;

        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            if (\E_WARNING === $errno) {
                $warning = $errstr;
            }

            return true;
        });

        try {
            $result = preg_match($regex, '');
        } finally {
            restore_error_handler();
        }

        if (false !== $result && null === $warning) {
            return null;
        }

        $message = $this->normalizeRuntimeErrorMessage((string) ($warning ?? preg_last_error_msg()));
        if ('' === $message || 'No error' === $message) {
            $message = 'PCRE runtime error.';
        }

        $offset = $this->extractOffsetFromMessage($message);
        $snippet = $this->buildVisualSnippet($pattern, $offset);
        $fullMessage = 'PCRE runtime error: '.$message;
        if ('' !== $snippet) {
            $fullMessage .= "\n".$snippet;
        }

        return new ValidationResult(
            false,
            $fullMessage,
            $complexityScore,
            ValidationErrorCategory::PCRE_RUNTIME,
            $offset,
            '' !== $snippet ? $snippet : null,
            null,
            'regex.pcre.runtime',
        );
    }

    private function normalizeRuntimeErrorMessage(string $message): string
    {
        $normalized = preg_replace('/^preg_[a-z_]+\\(\\):\\s*/i', '', $message) ?? $message;

        return trim($normalized);
    }

    private function extractOffsetFromMessage(string $message): ?int
    {
        // @regex-ignore-next-line
        if (preg_match('/\\b(?:at offset|offset)\\s+(\\d+)/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
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

            if (($displayEnd - $displayStart) > $maxContextWidth) {
                $displayStart = $displayEnd - $maxContextWidth;
            }
        }

        $prefixEllipsis = $displayStart > $lineStart ? '...' : '';
        $suffixEllipsis = $displayEnd < $lineEnd ? '...' : '';

        $excerpt = $prefixEllipsis
            .substr($pattern, $displayStart, $displayEnd - $displayStart)
            .$suffixEllipsis;

        $caretOffset = ('' === $prefixEllipsis ? 0 : self::ELLIPSIS_LENGTH) + ($caretIndex - $displayStart);
        if ($caretOffset < 0) {
            $caretOffset = 0;
        }

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

        return [$cachedResult instanceof RegexNode ? $cachedResult : null, $cacheKey];
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
            $this->cache->write($cacheKey, self::prepareCachePayload($ast));
        } catch (\Throwable) {
            // Cache failures are silently ignored
        }
    }

    /**
     * Prepare AST for cache storage by serializing it.
     *
     * @param RegexNode $ast The AST to serialize
     *
     * @return string Serialized PHP code
     */
    private static function prepareCachePayload(RegexNode $ast): string
    {
        $serializedAst = serialize($ast);
        $exportedAst = var_export($serializedAst, true);
        $allowedClasses = CachePayloadDecoder::NODE_CLASSES;
        $exportedAllowedClasses = var_export($allowedClasses, true);
        $version = var_export(self::CACHE_VERSION, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            if (\RegexParser\Regex::CACHE_VERSION !== $version) {
                return null;
            }

            return unserialize($exportedAst, ['allowed_classes' => $exportedAllowedClasses]);

            PHP;
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
        $validator = new ValidatorNodeVisitor($this->maxLookbehindLength, $pattern, $this->target);
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
        $scorer = new ComplexityScoreNodeVisitor();

        return $ast->accept($scorer);
    }

    /**
     * Build a validation failure result from an exception.
     *
     * @param \Throwable $exception The parse exception
     *
     * @return ValidationResult Validation failure result
     */
    private function buildValidationFailure(\Throwable $exception): ValidationResult
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

        if ('' !== $visualSnippet) {
            $errorMessage .= "\n".$visualSnippet;
        }

        if ($exception instanceof SemanticErrorException) {
            return new ValidationResult(
                false,
                $errorMessage,
                0,
                ValidationErrorCategory::SEMANTIC,
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
            ValidationErrorCategory::SYNTAX,
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
     * Extract unique literals from a literal set.
     *
     * @param mixed $literalSet The literal set from extraction
     *
     * @return array<string> Unique literals
     */
    private function extractUniqueLiterals(mixed $literalSet): array
    {
        if (!\is_object($literalSet)) {
            return [];
        }

        /** @var array<string> $prefixes */
        $prefixes = property_exists($literalSet, 'prefixes') ? $literalSet->prefixes : [];

        /** @var array<string> $suffixes */
        $suffixes = property_exists($literalSet, 'suffixes') ? $literalSet->suffixes : [];

        return array_values(array_unique(array_merge($prefixes, $suffixes)));
    }

    /**
     * @param callable(string): string $patternBuilder
     *
     * @return array<string>
     */
    private function processLiteralPatterns(mixed $literalSet, string $property, callable $patternBuilder): array
    {
        if (!\is_object($literalSet) || !property_exists($literalSet, $property)) {
            return [];
        }

        /** @var iterable<string> $items */
        $items = $literalSet->$property;
        $patterns = [];

        foreach ($items as $item) {
            if (\is_string($item) && '' !== $item) {
                $patterns[] = $patternBuilder($item);
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Build search patterns from prefixes and suffixes.
     *
     * @param mixed $literalSet The literal set containing prefixes/suffixes
     *
     * @return array<string> Search patterns
     */
    private function buildSearchPatterns(mixed $literalSet): array
    {
        $prefixPatterns = $this->processLiteralPatterns(
            $literalSet,
            'prefixes',
            static fn (string $prefix): string => '^'.preg_quote($prefix, '/'),
        );

        $suffixPatterns = $this->processLiteralPatterns(
            $literalSet,
            'suffixes',
            static fn (string $suffix): string => preg_quote($suffix, '/').'$',
        );

        return array_values(array_unique(array_merge($prefixPatterns, $suffixPatterns)));
    }

    /**
     * Determine confidence level for literal extraction.
     *
     * @param mixed $literalSet The literal set to evaluate
     *
     * @return string Confidence level ('high', 'medium', or 'low')
     */
    private function determineConfidenceLevel(mixed $literalSet): string
    {
        if (!\is_object($literalSet)) {
            return 'low';
        }

        $isComplete = property_exists($literalSet, 'complete') ? $literalSet->complete : false;
        $isVoid = (method_exists($literalSet, 'isVoid') && $literalSet->isVoid()) ? true : false;

        if ($isVoid) {
            return 'low';
        }

        return $isComplete ? 'high' : 'medium';
    }

    /**
     * Create appropriate explanation visitor based on format.
     *
     * @param string $format The desired output format
     *
     * @return NodeVisitor\ExplainNodeVisitor|NodeVisitor\HtmlExplainNodeVisitor The explanation visitor
     */
    private function createExplanationVisitor(string $format): ExplainNodeVisitor|HtmlExplainNodeVisitor
    {
        return match ($format) {
            'text' => new ExplainNodeVisitor(),
            'html' => new HtmlExplainNodeVisitor(),
            default => throw new \InvalidArgumentException("Invalid format: $format"),
        };
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
     * @return bool|null true when equivalent, false when not, null when unsupported
     */
    private function verifyOptimizedPatternWithAutomata(string $original, string $optimized): ?bool
    {
        try {
            $solver = new RegexSolver($this);
            $result = $solver->equivalent($original, $optimized, new SolverOptions());

            return $result->isEquivalent;
        } catch (\Throwable) {
            return null;
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
     * @return Node\RegexNode Fallback AST
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
            throw ResourceLimitException::withContext(
                \sprintf('Regex pattern exceeds maximum length of %d characters.', $this->maxPatternLength),
                $this->maxPatternLength,
                $regex,
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
        $parser = new Parser($this->maxRecursionDepth, $this->target);

        return $parser->parse($tokenStream, $flags, $delimiter, \strlen($pattern));
    }
}

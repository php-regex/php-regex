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
use RegexParser\Cache\NullCache;
use RegexParser\Cache\RemovableCacheInterface;
use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Exception\RecursionLimitException;
use RegexParser\Exception\RegexException;
use RegexParser\Exception\RegexParserExceptionInterface;
use RegexParser\Exception\ResourceLimitException;
use RegexParser\Exception\SemanticErrorException;
use RegexParser\Internal\PatternParser;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;
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
    public const CACHE_VERSION = 'ast-ea97e5f87dc343f6e68c737c0917151d';

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
    private const PHP_VERSION_PREFIX = '#php_version=';

    /**
     * Create a new Regex instance with specified configuration.
     *
     * @param int            $maxPatternLength      Maximum allowed pattern length
     * @param int            $maxLookbehindLength   Maximum length of a variable-length lookbehind
     * @param CacheInterface $cache                 Cache implementation for parsed patterns
     * @param array<string>  $redosIgnoredPatterns  Patterns to ignore in ReDoS analysis
     * @param bool           $runtimePcreValidation Whether to validate against PCRE runtime
     * @param int            $maxRecursionDepth     Maximum recursion depth during parsing
     * @param int            $phpVersionId          Target PHP_VERSION_ID for feature validation
     */
    private function __construct(
        private int $maxPatternLength,
        private int $maxLookbehindLength,
        private CacheInterface $cache,
        private array $redosIgnoredPatterns,
        private bool $runtimePcreValidation,
        private int $maxRecursionDepth,
        private int $phpVersionId,
        private bool $phpVersionExplicit,
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
            $configuration->phpVersionId,
            $configuration->phpVersionExplicit,
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
            return $this->buildValidationFailure($this->earlierError($regex, $e) ?? $e);
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

        [$originalPattern] = PatternParser::extractPatternAndFlags($originalCompiled, $this->getParserPhpVersionId());
        [$optimizedPatternPart] = PatternParser::extractPatternAndFlags($optimizedCompiled, $this->getParserPhpVersionId());

        if ($originalPattern === $optimizedPatternPart) {
            [$pattern, , $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->getParserPhpVersionId());
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
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $sample = $ast->accept($generator);

            $matches = @preg_match($regex, $sample);
            if (false === $matches || 1 === $matches) {
                // Either verified, or the pattern cannot be evaluated by
                // this PCRE runtime — return what we have.
                return $sample;
            }
        }

        return $sample;
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
     * @param int|null $phpVersionId Target PHP_VERSION_ID for feature validation
     */
    public static function tokenize(string $regex, ?int $phpVersionId = null): TokenStream
    {
        $versionId = $phpVersionId ?? \PHP_VERSION_ID;
        [$pattern, $flags] = PatternParser::extractPatternAndFlags($regex, $phpVersionId);

        return (new Lexer(Lexer::readsWideRepeatCounts($phpVersionId)))->tokenize($pattern, $flags);
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
     * @param string $regex             The regex as written, delimiters included
     * @param int    $phpVersionId      The effective PHP_VERSION_ID
     * @param int    $maxRecursionDepth The parse recursion limit in force
     */
    public static function cacheSeed(string $regex, int $phpVersionId, int $maxRecursionDepth): string
    {
        // The effective PHP version always influences parsing, so it must
        // always be part of the key — a shared cache directory must not serve
        // ASTs parsed under a different PHP version. The recursion limit does
        // too: a pattern cached under a high limit may be one a lower limit
        // refuses to parse at all, and the exception must still be thrown.
        return $regex
            ."\n".self::CACHE_VERSION_PREFIX.self::CACHE_VERSION
            ."\n".self::PHP_VERSION_PREFIX.$phpVersionId
            ."\n#depth=".$maxRecursionDepth;
    }

    /**
     * PCRE reads the pattern in one pass, left to right. This library
     * tokenizes it whole, then parses it, then judges its escapes, so the
     * error it stops on may lie after one PCRE meets first: an escape PCRE
     * refuses, or, when tokenizing failed, a syntax error in what was read
     * before. The earliest of those before the error found is PCRE's.
     */
    private function earlierError(string $regex, LexerException|ParserException $error): ?RegexException
    {
        $position = $error->getPosition() ?? 0;

        try {
            [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->getParserPhpVersionId());
        } catch (ParserException) {
            return null;
        }

        $lexer = new Lexer(Lexer::readsWideRepeatCounts($this->getParserPhpVersionId()));

        try {
            $lexer->tokenize($pattern, $flags);
        } catch (LexerException) {
            // The tokens read before the error are what is judged.
        }

        $tokens = $lexer->tokensRead();
        $earlier = (new ValidatorNodeVisitor($this->maxLookbehindLength, $pattern, $this->phpVersionId))
            ->firstEscapeErrorBefore($tokens, $pattern, $flags, $position);

        if ($error instanceof LexerException) {
            // The pattern read as if it ended where tokenizing stopped: an
            // error that ending causes lies there, and is not taken.
            $stream = new TokenStream([...$tokens, new Token(TokenType::T_EOF, '', $position)], $pattern);

            try {
                (new Parser($this->maxRecursionDepth, $this->getParserPhpVersionId()))->parse($stream, $flags, $delimiter, $position);
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
     * Get the list of allowed classes for unserialization.
     *
     * @return array<class-string>
     */
    private static function getAllowedClasses(): array
    {
        return [
            // Node classes
            RegexNode::class,
            AlternationNode::class,
            AnchorNode::class,
            AssertionNode::class,
            BackrefNode::class,
            CalloutNode::class,
            CharClassNode::class,
            CharLiteralNode::class,
            CharTypeNode::class,
            ClassOperationNode::class,
            CommentNode::class,
            ConditionalNode::class,
            ControlCharNode::class,
            DefineNode::class,
            DotNode::class,
            GroupNode::class,
            KeepNode::class,
            LimitMatchNode::class,
            LiteralNode::class,
            PcreVerbNode::class,
            PosixClassNode::class,
            QuantifierNode::class,
            RangeNode::class,
            ScriptRunNode::class,
            SequenceNode::class,
            SubroutineNode::class,
            UnicodePropNode::class,
            VersionConditionNode::class,
        ];
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
        return self::cacheSeed($regex, $this->phpVersionId, $this->maxRecursionDepth);
    }

    private function getParserPhpVersionId(): ?int
    {
        return $this->phpVersionExplicit ? $this->phpVersionId : null;
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
        $allowedClasses = self::getAllowedClasses();
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
            [$pattern] = PatternParser::extractPatternAndFlags($regex, $this->getParserPhpVersionId());

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
        $validator = new ValidatorNodeVisitor($this->maxLookbehindLength, $pattern, $this->phpVersionId);
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
            [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->getParserPhpVersionId());
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
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->getParserPhpVersionId());
        $tokenStream = (new Lexer(Lexer::readsWideRepeatCounts($this->getParserPhpVersionId())))->tokenize($pattern, $flags);
        $parser = new Parser($this->maxRecursionDepth, $this->getParserPhpVersionId());

        return $parser->parse($tokenStream, $flags, $delimiter, \strlen($pattern));
    }
}

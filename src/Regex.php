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

use RegexParser\Cache\CacheInterface;
use RegexParser\Engine\PcreEngine;
use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\Exception\RegexParserExceptionInterface;
use RegexParser\Exception\SampleGenerationException;
use RegexParser\Internal\PatternParser;
use RegexParser\Node\RegexNode;
use RegexParser\NodeVisitor\ConsoleHighlighterVisitor;
use RegexParser\NodeVisitor\ExplainNodeVisitor;
use RegexParser\NodeVisitor\HtmlExplainNodeVisitor;
use RegexParser\NodeVisitor\HtmlHighlighterVisitor;
use RegexParser\NodeVisitor\LinterNodeVisitor;
use RegexParser\NodeVisitor\LiteralExtractorNodeVisitor;
use RegexParser\NodeVisitor\SampleGeneratorNodeVisitor;
use RegexParser\Optimizer\Optimizer;
use RegexParser\Optimizer\OptimizerOptions;
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
    public const VERSION = '2.0.0-DEV';
    public const VERSION_ID = 20000;

    /**
     * Cache version for AST serialization; see RegexParser::CACHE_VERSION.
     */
    public const CACHE_VERSION = RegexParser::CACHE_VERSION;

    /**
     * Default maximum allowed regex pattern length.
     */
    public const DEFAULT_MAX_PATTERN_LENGTH = RegexParser::DEFAULT_MAX_PATTERN_LENGTH;

    /**
     * Default maximum length of a variable-length lookbehind.
     */
    public const DEFAULT_MAX_LOOKBEHIND_LENGTH = RegexParser::DEFAULT_MAX_LOOKBEHIND_LENGTH;

    /**
     * How deep the parser may nest groups before it stops.
     */
    public const DEFAULT_MAX_RECURSION_DEPTH = RegexParser::DEFAULT_MAX_RECURSION_DEPTH;

    /**
     * @param RegexParser   $parser               Reads and judges every pattern
     * @param array<string> $redosIgnoredPatterns Patterns to ignore in ReDoS analysis
     * @param PcreEngine    $engine               Runs the pattern to check the samples
     */
    private function __construct(
        private RegexParser $parser,
        private array $redosIgnoredPatterns,
        private PcreEngine $engine = new PcreEngine(),
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

        return new self(RegexParser::fromOptions($configuration), $configuration->redosIgnoredPatterns);
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
        return $tolerant ? $this->parser->parseTolerant($regex) : $this->parser->parse($regex);
    }

    /**
     * The parser this facade reads and judges patterns with, to hand to
     * whatever else reads patterns under the same options.
     */
    public function parser(): RegexParser
    {
        return $this->parser;
    }

    /**
     * Parse a regular expression, returning a best-effort AST plus the parse
     * errors instead of throwing on invalid input.
     */
    public function parseTolerant(string $regex): TolerantParseResult
    {
        return $this->parser->parseTolerant($regex);
    }

    /**
     * Validate a regular expression and return detailed validation results.
     */
    public function validate(string $regex): ValidationResult
    {
        return $this->parser->validate($regex);
    }

    /**
     * Parse a regular expression pattern with separate flags and delimiter.
     */
    public function parsePattern(string $pattern, string $flags = '', string $delimiter = '/'): RegexNode
    {
        return $this->parser->parsePattern($pattern, $flags, $delimiter);
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
        $analyzer = new ReDoSAnalyzer($this->parser, $this->redosIgnoredPatterns);

        return $analyzer->analyze($regex, $threshold, $mode, $confirmOptions);
    }

    /**
     * Optimize a regular expression for better performance.
     *
     * @param string                                   $regex   The regular expression to optimize
     * @param OptimizerOptions|array<array-key, mixed> $options What may be rewritten, as a value or as the array OptimizerOptions::fromArray() reads
     *
     * @return OptimizationResult Optimization results with changes applied
     */
    public function optimize(string $regex, OptimizerOptions|array $options = []): OptimizationResult
    {
        return (new Optimizer($this->parser))->optimize($regex, $options);
    }

    /**
     * Transpile a PCRE regex literal to another regex dialect.
     */
    public function transpile(string $regex, string $target, ?TranspileOptions $options = null): TranspileResult
    {
        $transpiler = new RegexTranspiler($this->parser);

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
     * @throws SampleGenerationException when the pattern is invalid, or no sample the running engine matches was found
     *
     * @return string Generated sample string
     */
    public function generate(string $regex): string
    {
        $ast = $this->parse($regex, false);
        // A pattern PCRE refuses, as a call to a group that does not exist,
        // has no sample to draw.
        $validation = $this->parser->validate($regex);
        if (!$validation->isValid) {
            throw new SampleGenerationException(\sprintf('No sample matching %s can be drawn: %s', $regex, (string) $validation->error), null, $validation->errorCode ?? ErrorCode::GenerateNoMatch);
        }

        $generator = new SampleGeneratorNodeVisitor();

        // Generation is best-effort (lookaround hints, negated classes, ...):
        // verify the sample against the real engine and retry a few times
        // before settling for the last attempt. The engine checks it without
        // the JIT, which crashes PHP on some pattern and subject pairs.
        // Only a pattern this PHP cannot compile gets a sample nothing checks.
        $compiles = null === $this->engine->compile($regex);
        $sample = '';
        $attempts = [];
        $gaveUp = 0;
        $engineError = '';
        // A condition with one valid branch in two misses once in 2^32 calls.
        for ($attempt = 0; $attempt < 32; $attempt++) {
            $sample = $ast->accept($generator);

            $match = $this->engine->match($regex, $sample);
            // Verified, or a pattern this PCRE runtime cannot compile: what
            // we have. A limit or an error it met checks nothing: another try.
            if (true === $match->matched || !$compiles) {
                return $sample;
            }

            if (false === $match->matched) {
                $attempts[$sample] = true;
            } else {
                $gaveUp++;
                $engineError = (string) $match->error;
                // A sample that matches is found quickly: the samples the
                // engine gives up on are costly misses, and eight end it.
                if (8 === $gaveUp) {
                    break;
                }
            }
        }

        // An assertion on what surrounds the match, as "\b" or "(?!^)", may
        // hold once the sample has text around it. A sample the engine gave
        // up on is not tried again: with text around it, it gives up again.
        foreach (array_keys($attempts) as $attempt) {
            foreach (['a', ' ', "\n"] as $padding) {
                foreach ([$padding.$attempt, $attempt.$padding, $padding.$attempt.$padding] as $padded) {
                    if (true === $this->engine->match($regex, $padded)->matched) {
                        return $padded;
                    }
                }
            }
        }

        if ($gaveUp > 0) {
            throw new SampleGenerationException(\sprintf('No sample matching %s was found: the engine gave up checking %d of the samples (%s).', $regex, $gaveUp, $engineError));
        }

        throw new SampleGenerationException(\sprintf('No sample matching %s was found: the pattern may match nothing, or its assertions ask for more than the samples give.', $regex));
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
        return $this->parser->target();
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
        return $this->parser->getCache();
    }

    /**
     * @return array{hits: int, misses: int} Cache hits and misses (zeroed if unsupported)
     */
    public function getCacheStats(): array
    {
        return $this->parser->getCacheStats();
    }

    /**
     * Empty the library's process-wide caches (useful for long-running
     * processes); see RegexParser::clearCaches().
     */
    public function clearCaches(): void
    {
        $this->parser->clearCaches();
    }

    /**
     * The seed a pattern's cache key is hashed from; see RegexParser::cacheSeed().
     */
    public static function cacheSeed(string $regex, PcreTarget $target, int $maxRecursionDepth): string
    {
        return RegexParser::cacheSeed($regex, $target, $maxRecursionDepth);
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
            default => throw new InvalidRegexOptionException("Invalid format: $format"),
        };
    }
}

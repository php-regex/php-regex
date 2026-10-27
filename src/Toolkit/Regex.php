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

namespace PHPRegex\Toolkit;

use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\Highlighter\HtmlHighlighter;
use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\LiteralConfidence;
use PHPRegex\Parser\Analysis\LiteralExtractionResult;
use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Parser\Analysis\PatternInfo;
use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\FormattingException;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\FormatResult;
use PHPRegex\Parser\Printer\PatternFormatter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\TolerantParseResult;
use PHPRegex\Parser\Validation\CompatibilityChecker;
use PHPRegex\Parser\Validation\PatternCompatibility;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Transpiler\TranspileOptions;
use PHPRegex\Transpiler\Transpiler;
use PHPRegex\Transpiler\TranspileResult;

/**
 * Entry point for the PHPRegex library.
 *
 * Provides methods for parsing, validating, optimizing, and analyzing
 * regular expressions. Supports caching and runtime PCRE validation.
 *
 * @phpstan-import-type OptionsArray from ParserOptions
 */
final class Regex
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
    public const DEFAULT_MAX_PATTERN_LENGTH = ParserOptions::DEFAULT_MAX_PATTERN_LENGTH;

    /**
     * Default maximum length of a variable-length lookbehind.
     */
    public const DEFAULT_MAX_LOOKBEHIND_LENGTH = ParserOptions::DEFAULT_MAX_LOOKBEHIND_LENGTH;

    /**
     * How deep the parser may nest groups before it stops.
     */
    public const DEFAULT_MAX_RECURSION_DEPTH = ParserOptions::DEFAULT_MAX_RECURSION_DEPTH;

    /**
     * @param RegexParser   $parser               Reads and judges every pattern
     * @param array<string> $redosIgnoredPatterns Patterns to ignore in ReDoS analysis
     * @param PcreEngine    $engine               Runs the pattern to check the samples
     */
    private function __construct(
        private readonly RegexParser $parser,
        private readonly array $redosIgnoredPatterns,
        private readonly PcreEngine $engine = new PcreEngine(),
    ) {}

    /**
     * Create a new Regex instance with optional configuration.
     *
     * Every call returns a fresh instance; nothing is memoized.
     *
     * @param OptionsArray $options Configuration options
     *
     * @return self New Regex instance
     */
    public static function create(array $options = []): self
    {
        return self::fromOptions(ParserOptions::fromArray($options));
    }

    /**
     * A facade configured by options already read, as from a configuration
     * file through ParserOptions::fromArray().
     */
    public static function fromOptions(ParserOptions $options): self
    {
        return new self(RegexParser::fromOptions($options), $options->redosIgnoredPatterns);
    }

    /**
     * Parse a regular expression into an Abstract Syntax Tree (AST); see
     * parseTolerant() for a result that holds the errors instead.
     *
     * @param string $regex The regular expression to parse
     */
    public function parse(string $regex): RegexNode
    {
        return $this->parser->parse($regex);
    }

    /**
     * A facade judging for another target with every other option of this
     * one: this one for its own target (see RegexParser::withTarget()).
     */
    public function withTarget(PcreTarget $target): self
    {
        $parser = $this->parser->withTarget($target);

        return $parser === $this->parser ? $this : new self($parser, $this->redosIgnoredPatterns, $this->engine);
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
     * The ReDoS part takes the options redos() takes, and gives what
     * redos() gives with them.
     *
     * @param string             $regex     The regular expression to analyze
     * @param RedosSeverity|null $threshold Minimum severity level to report
     *
     * @return AnalysisReport Complete analysis report
     */
    public function analyze(
        string $regex,
        ?RedosSeverity $threshold = null,
        RedosMode $mode = RedosMode::Theoretical,
        ?ConfirmationOptions $confirmOptions = null,
    ): AnalysisReport {
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

        $redos = $this->redos($regex, $threshold, $mode, $confirmOptions);

        if ($isValid) {
            // Only what the pattern itself can cause is reported as an error:
            // a failure of any other kind is a bug in the library, and a
            // report saying "invalid pattern" would bury it.
            try {
                $lintIssues = $this->lint($regex);
            } catch (ExceptionInterface $e) {
                $errors[] = self::patternError($e);
                $isValid = false;
            }

            try {
                $optimizations = $this->optimize($regex);
            } catch (ExceptionInterface $e) {
                $errors[] = self::patternError($e);
                $isValid = false;
            }

            try {
                $explain = $this->explain($regex);
            } catch (ExceptionInterface $e) {
                $errors[] = self::patternError($e);
                $isValid = false;
            }

            try {
                $highlighted = $this->highlight($regex);
            } catch (ExceptionInterface $e) {
                $errors[] = self::patternError($e);
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
     * Run the lint rules on a pattern, read for this instance's target.
     *
     * The rules alone: no validation, ReDoS analysis, optimization, inline
     * ignores or baseline, so not what the "regex lint" command reports.
     *
     * @param string $regex The regular expression to lint
     *
     * @throws ParserException when the pattern does not parse, as parse() throws
     * @throws LexerException  when the pattern does not tokenize
     *
     * @return list<RuleViolation> The violations, in the order the rules report them
     */
    public function lint(string $regex): array
    {
        $linter = new PatternLinter([], $this->parser->target());
        $this->parser->parse($regex)->accept($linter);

        return $linter->getIssues();
    }

    /**
     * Analyze a regular expression for potential ReDoS (Regular Expression Denial of Service) vulnerabilities.
     *
     * @param string             $regex     The regular expression to analyze
     * @param RedosSeverity|null $threshold Minimum severity level to report
     *
     * @return RedosAnalysis Detailed ReDoS analysis results
     */
    public function redos(
        string $regex,
        ?RedosSeverity $threshold = null,
        RedosMode $mode = RedosMode::Theoretical,
        ?ConfirmationOptions $confirmOptions = null,
    ): RedosAnalysis {
        $analyzer = new RedosAnalyzer($this->parser, $this->redosIgnoredPatterns);

        return $analyzer->analyze($regex, $threshold, $mode, $confirmOptions);
    }

    /**
     * Optimize a regular expression for better performance.
     *
     * @param string                $regex   The regular expression to optimize
     * @param OptimizerOptions|null $options What may be rewritten; null reads the defaults. A config array becomes one
     *                                       through OptimizerOptions::fromArray()
     *
     * @return OptimizationResult Optimization results with changes applied
     */
    public function optimize(string $regex, ?OptimizerOptions $options = null): OptimizationResult
    {
        $this->parse($regex);
        $this->refuseWhatTheEngineRefuses($regex);

        return (new Optimizer($this->parser))->optimize($regex, $options);
    }

    /**
     * Lay a pattern out over lines under x when it does not fit the width,
     * the way a code formatter does; a pattern that fits comes back as written,
     * unless it holds a raw line break, which is respelled as an escape.
     *
     * @throws InvalidRegexOptionException when the width is below 1
     * @throws FormattingException         when the result would not read back as the same pattern
     */
    public function format(string $regex, int $width = 80): FormatResult
    {
        $formatter = new PatternFormatter($width, $this->parser->target());

        return $formatter->format($this->parser->parse($regex));
    }

    /**
     * Write a pattern on one line, without x, layout or comments.
     *
     * @throws FormattingException when the result would not read back as the same pattern
     */
    public function compact(string $regex): FormatResult
    {
        return (new PatternFormatter(target: $this->parser->target()))->compact($this->parser->parse($regex));
    }

    /**
     * Transpile a PCRE regex literal to another regex dialect.
     */
    public function transpile(string $regex, string $target, ?TranspileOptions $options = null): TranspileResult
    {
        $transpiler = new Transpiler($this->parser);

        return $transpiler->transpile($regex, $target, $options);
    }

    /**
     * Generate a human-readable explanation of the regular expression.
     *
     * @param string               $regex  The regular expression to explain
     * @param string|ExplainFormat $format ExplainFormat::Text or ExplainFormat::Html, or its value, "text" or "html"
     *
     * @throws InvalidRegexOptionException when the format is a string other than text or html
     *
     * @return string Formatted explanation
     */
    public function explain(string $regex, string|ExplainFormat $format = ExplainFormat::Text): string
    {
        if (\is_string($format)) {
            $format = ExplainFormat::tryFrom($format) ?? throw new InvalidRegexOptionException(\sprintf('Invalid format for explain(): %s; expected text or html.', $format));
        }

        $explanationVisitor = match ($format) {
            ExplainFormat::Text => new TextExplainer($this->parser->target()),
            ExplainFormat::Html => new HtmlExplainer($this->parser->target()),
        };

        return $this->parse($regex)->accept($explanationVisitor);
    }

    /**
     * Highlight a regex for console or HTML output.
     *
     * @param string                 $regex  The regular expression to highlight
     * @param string|HighlightFormat $format HighlightFormat::Console or HighlightFormat::Html, or its value, "console" or "html"
     *
     * @throws InvalidRegexOptionException when the format is a string other than console or html
     */
    public function highlight(string $regex, string|HighlightFormat $format = HighlightFormat::Console): string
    {
        if (\is_string($format)) {
            $format = HighlightFormat::tryFrom($format) ?? throw new InvalidRegexOptionException(\sprintf('Invalid format for highlight(): %s; expected console or html.', $format));
        }

        $visitor = match ($format) {
            HighlightFormat::Console => new ConsoleHighlighter(),
            HighlightFormat::Html => new HtmlHighlighter(),
        };

        return $this->parse($regex)->accept($visitor);
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
        $ast = $this->parse($regex);
        $this->refuseWhatTheEngineRefuses($regex);

        $literalSet = $ast->accept(new LiteralExtractor());

        $uniqueLiterals = $this->extractUniqueLiterals($literalSet);
        $searchPatterns = $this->buildSearchPatterns($uniqueLiterals);
        $confidenceLevel = [] === $uniqueLiterals ? LiteralConfidence::Low : $this->determineConfidenceLevel($literalSet);

        return new LiteralExtractionResult($uniqueLiterals, $searchPatterns, $confidenceLevel, $literalSet);
    }

    /**
     * Describe the array preg_match() fills for the pattern: its keys, which are optional, and the strings each can hold.
     *
     * @param string $regex The regular expression to analyze
     *
     * @throws ExceptionInterface when the pattern does not parse, as parse() throws
     */
    public function captureShape(string $regex): CaptureShape
    {
        $ast = $this->parse($regex);
        $this->refuseWhatTheEngineRefuses($regex);

        return (new CaptureShapeAnalyzer($this->target()))->analyze($ast);
    }

    /**
     * The facts PCRE2 computes on the compiled pattern: capture count, group names, lengths, anchoring, limits.
     *
     * @param string $regex The regular expression to analyze
     *
     * @throws ExceptionInterface when validate() refuses the pattern at this instance's target: the exception parse()
     *                            throws, or a SemanticErrorException carrying the validation error
     */
    public function info(string $regex): PatternInfo
    {
        return PatternInfoAnalyzer::analyzePattern($this->parser, $regex);
    }

    /**
     * On which PHP versions and PCRE2 releases the pattern is valid, judged at every target the library knows a rule
     * boundary for, whatever target this instance judges for, each under this instance's locale and limits.
     *
     * A pattern is never a reason to throw: one no target accepts, a missing delimiter included, gets a verdict
     * refusing it at every target.
     *
     * @param string $regex The regular expression to judge
     */
    public function compatibility(string $regex): PatternCompatibility
    {
        return (new CompatibilityChecker($this->parser))->check($regex);
    }

    /**
     * Generate a sample string that matches the regular expression.
     *
     * @param string $regex The regular expression to generate a sample for
     *
     * @throws ParserException           when the pattern does not parse (a delimiter, flag or syntax fault)
     * @throws LexerException            when the pattern does not tokenize
     * @throws SampleGenerationException when the pattern parses but is invalid, as a call to a group that does not
     *                                   exist, or no sample the running engine matches was found
     *
     * @return string Generated sample string
     */
    public function generate(string $regex): string
    {
        // The generator checks each sample with this instance's engine and
        // judges the pattern with its parser.
        return (new SampleGenerator(engine: $this->engine))->generate($regex, $this->parser);
    }

    /**
     * The tokens of a regex as the parser reads them for this instance's
     * target: the same lexer the parser uses, whitespace and comment
     * markers included. They come in source order and cover the pattern
     * body, each tagged with its byte offsets, the end-of-pattern token
     * last. How many characters one token covers is not promised: a run of
     * literal characters may become one token in a later release.
     *
     * @return list<Token>
     */
    public function tokenize(string $regex): array
    {
        return $this->parser->tokenize($regex);
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
     * The message of an error the pattern causes. A failure of the library,
     * which says nothing of the pattern, goes on as it is.
     *
     * @throws InternalFailureException
     */
    private static function patternError(ExceptionInterface $error): string
    {
        if ($error instanceof InternalFailureException) {
            throw $error;
        }

        return $error->getMessage();
    }

    /**
     * A pattern the engine refuses has no matches to describe: the methods
     * that state what a pattern matches refuse it, as info() does, with
     * what validate() says.
     *
     * @throws SemanticErrorException when the target refuses the pattern
     */
    private function refuseWhatTheEngineRefuses(string $regex): void
    {
        $validation = $this->parser->validate($regex);
        if ($validation->isValid) {
            return;
        }

        throw new SemanticErrorException((string) $validation->error, $validation->errorCode ?? ErrorCode::InternalUnexpectedState, $validation->offset, DelimitedPattern::fromDelimited($regex, $this->parser->target())->pattern, hint: $validation->hint);
    }

    /**
     * Extract unique literals from a literal set.
     *
     * @param LiteralSet $literalSet The literal set from extraction
     *
     * @return list<string> Unique literals
     */
    private function extractUniqueLiterals(LiteralSet $literalSet): array
    {
        // An empty literal is held by every subject: it says nothing.
        $literals = array_filter(array_merge($literalSet->prefixes, $literalSet->suffixes), static fn (string $literal): bool => '' !== $literal);

        return array_values(array_unique($literals));
    }

    /**
     * The literals as patterns a subject the pattern matches holds one of.
     * Never anchored: the literals describe the match, not where it sits in
     * the subject, and "m", "D", "\z" or "\G" move where it may sit.
     *
     * @param list<string> $literals
     *
     * @return list<string>
     */
    private function buildSearchPatterns(array $literals): array
    {
        return array_map(static fn (string $literal): string => preg_quote($literal, '/'), $literals);
    }

    /**
     * @param LiteralSet $literalSet what LiteralExtractor read from the pattern
     */
    private function determineConfidenceLevel(LiteralSet $literalSet): LiteralConfidence
    {
        if ($literalSet->isVoid()) {
            return LiteralConfidence::Low;
        }

        return $literalSet->complete ? LiteralConfidence::High : LiteralConfidence::Medium;
    }
}

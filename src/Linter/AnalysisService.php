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

namespace PHPRegex\Linter;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Extraction\LiteralSpelling;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\Internal\AnalyzedPatterns;
use PHPRegex\Linter\Internal\ForkedWorkerPool;
use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Linter\Rule\InvisibleCharacterRule;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Optimizer\Internal\LayoutSplicer;
use PHPRegex\Optimizer\Internal\RewriteCheck;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Cache\AstSerializer;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;

/**
 * Handles regex-related analysis and transformations.
 *
 * @internal
 *
 * @phpstan-import-type LintIssue from LintReport
 * @phpstan-import-type OptimizationEntry from LintReport
 */
final class AnalysisService
{
    private const PATTERN_DELIMITERS = ['/', '#', '~', '%'];
    private const ISSUE_ID_COMPLEXITY = 'regex.lint.complexity';

    private const ISSUE_ID_MEANING_CHANGES = 'regex.lint.compat.meaningChanges';

    private readonly RedosSeverity $redosSeverityThreshold;

    /**
     * @var array<string>
     */
    private readonly array $ignoredPatterns;

    private readonly RedosMode $redosMode;

    /**
     * @param string                 $redosThreshold       the lowest severity reported: low, medium, high or
     *                                                     critical, in any case
     * @param array<string>          $ignoredPatterns
     * @param array<string>          $redosIgnoredPatterns
     * @param array<string, bool>    $lintRules
     * @param list<RegexParser>      $range                the parsers of the PHP versions after the floor a
     *                                                     pattern is validated at too (ProjectTarget::rangeParsers())
     * @param DfaCacheInterface|null $dfaCache             keeps the DFAs the lint rules and the optimizer's checks
     *                                                     build, for the whole run; the process's cache when null
     *
     * @throws InvalidRegexOptionException when the threshold names no severity
     */
    public function __construct(
        private readonly RegexParser $regex,
        private readonly ?PatternExtractor $extractor = null,
        private readonly int $warningThreshold = 50,
        string $redosThreshold = 'high',
        array $ignoredPatterns = [],
        array $redosIgnoredPatterns = [],
        private readonly bool $ignoreParseErrors = false,
        RedosMode|string $redosMode = RedosMode::Theoretical,
        private readonly ?ConfirmationOptions $redosConfirmOptions = null,
        bool $redosEnabled = false,
        private readonly bool $lintEnabled = true,
        private readonly array $lintRules = [],
        private readonly array $range = [],
        private readonly ?DfaCacheInterface $dfaCache = null,
    ) {
        $this->redosSeverityThreshold = RedosSeverity::fromConfig($redosThreshold);
        $this->ignoredPatterns = $this->buildIgnoredPatterns($ignoredPatterns, $redosIgnoredPatterns);

        // When redosEnabled is false, force mode to OFF
        if (!$redosEnabled) {
            $this->redosMode = RedosMode::Off;
        } else {
            $this->redosMode = $redosMode instanceof RedosMode
                ? $redosMode
                : (RedosMode::tryFrom(Ascii::lower((string) $redosMode)) ?? RedosMode::Theoretical);
        }
    }

    /**
     * The parser patterns are read and judged with.
     */
    public function getParser(): RegexParser
    {
        return $this->regex;
    }

    /**
     * The same analysis, judging patterns with another parser: how a lint
     * command judges for the project's target with the settings of the
     * application's service.
     *
     * @param list<RegexParser> $range the parsers of the PHP versions after the floor a pattern is
     *                                 validated at too (ProjectTarget::rangeParsers())
     */
    public function withParser(RegexParser $parser, array $range = []): self
    {
        return new self(
            $parser,
            $this->extractor,
            $this->warningThreshold,
            $this->redosSeverityThreshold->value,
            $this->ignoredPatterns,
            [],
            $this->ignoreParseErrors,
            $this->redosMode,
            $this->redosConfirmOptions,
            RedosMode::Off !== $this->redosMode,
            $this->lintEnabled,
            $this->lintRules,
            $range,
            $this->dfaCache,
        );
    }

    /**
     * @param array<string> $paths
     * @param array<string> $excludePaths
     *
     * @return array<PatternOccurrence>
     */
    public function scan(array $paths, array $excludePaths): array
    {
        $extractor = $this->extractor ?? new PatternExtractor(
            new TokenBasedExtractionStrategy(),
        );

        return $extractor->extract($paths, $excludePaths);
    }

    /**
     * @param array<PatternOccurrence> $patterns
     * @param LintProgress|null        $progress advanced once per pattern, the ignored and the invalid
     *                                           ones included
     *
     * @throws LintException when a worker fails
     *
     * @return array<LintIssue>
     */
    public function lint(array $patterns, ?LintProgress $progress = null, int $workers = 1): array
    {
        return $this->runOnWorkers(
            $patterns,
            $workers,
            fn (array $chunk, ?\Closure $done): array => $this->lintChunk($chunk, $done),
            $progress,
        );
    }

    /**
     * The lint, and what it read of each pattern for suggestOptimizations()
     * to take instead of parsing and validating it again: the workers send
     * it back with their findings.
     *
     * @param array<PatternOccurrence> $patterns
     * @param LintProgress|null        $progress advanced once per pattern, the ignored and the invalid
     *                                           ones included
     *
     * @throws LintException when a worker fails
     *
     * @return array{0: list<LintIssue>, 1: AnalyzedPatterns}
     */
    public function lintForSuggestions(array $patterns, ?LintProgress $progress = null, int $workers = 1): array
    {
        $results = $this->runOnWorkers(
            $patterns,
            $workers,
            function (array $chunk, ?\Closure $done): array {
                $analyzed = [];
                $results = $this->lintChunk($chunk, $done, $analyzed);
                $results[] = new AnalyzedPatterns($analyzed);

                return $results;
            },
            $progress,
        );

        $issues = [];
        $analyzed = [];
        foreach ($results as $result) {
            if ($result instanceof AnalyzedPatterns) {
                $analyzed += $result->byPattern;
            } else {
                $issues[] = $result;
            }
        }

        return [$issues, new AnalyzedPatterns($analyzed)];
    }

    /**
     * @param array<PatternOccurrence> $patterns
     *
     * @throws LintException when a worker fails
     *
     * @return array<array{file: string, line: int, column?: int|null, fileOffset?: int|null, analysis: RedosAnalysis}>
     */
    public function analyzeRedos(array $patterns, RedosSeverity $threshold, int $workers = 1): array
    {
        return $this->runOnWorkers(
            $patterns,
            $workers,
            fn (array $chunk): array => $this->analyzeRedosChunk($chunk, $threshold),
            null,
        );
    }

    /**
     * @param array<PatternOccurrence> $patterns
     * @param OptimizerOptions|null    $options  what an optimization may rewrite; by default, what lint
     *                                           allows, every rewrite checked with the automata
     * @param LintProgress|null        $progress advanced once per pattern, the ignored and the invalid
     *                                           ones included
     * @param AnalyzedPatterns|null    $analyzed what lintForSuggestions() read of the same patterns,
     *                                           taken instead of parsing and validating them again
     *
     * @throws LintException when a worker fails
     *
     * @return array<OptimizationEntry>
     */
    public function suggestOptimizations(array $patterns, int $minSavings, ?OptimizerOptions $options = null, int $workers = 1, ?LintProgress $progress = null, ?AnalyzedPatterns $analyzed = null): array
    {
        $options ??= new OptimizerOptions(verifyWithAutomata: true);
        $byPattern = $analyzed->byPattern ?? [];

        return $this->runOnWorkers(
            $patterns,
            $workers,
            fn (array $chunk, ?\Closure $done): array => $this->suggestOptimizationsChunk($chunk, $minSavings, $options, $byPattern, $done),
            $progress,
        );
    }

    public function highlight(string $pattern): string
    {
        $ast = $this->regex->parse($pattern);

        return $ast->accept(new ConsoleHighlighter());
    }

    public function highlightBody(string $body, string $flags = '', string $delimiter = '/'): string
    {
        $ast = $this->regex->parsePattern($body, $flags, $delimiter);

        return $ast->accept(new ConsoleHighlighter());
    }

    /**
     * @param array<PatternOccurrence>                                       $patterns
     * @param (\Closure(int): void)|null                                     $done     told of each pattern analysed
     * @param array<string, array{0: ValidationResult, 1: string|null}>|null $analyzed given, what was read of each
     *                                                                                 pattern: its validation, the
     *                                                                                 serialized tree of a valid one
     *
     * @param-out ($analyzed is null ? null : array<string, array{0: ValidationResult, 1: string|null}>) $analyzed
     *
     * @phpstan-return list<LintIssue>
     */
    private function lintChunk(array $patterns, ?\Closure $done = null, ?array &$analyzed = null): array
    {
        $issues = [];

        foreach ($patterns as $occurrence) {
            // A file read with the tokenizer instead of the PHP parser is
            // counted in the stats, never reported.
            if ($occurrence->isIgnored || null !== $occurrence->parserFallback) {
                if (null !== $done) {
                    $done(1);
                }

                continue;
            }

            // A file the extractor could not read holds patterns no one linted.
            if (null !== $occurrence->unread) {
                $issues[] = [
                    'type' => 'error',
                    'file' => $occurrence->file,
                    'line' => 1,
                    'column' => 1,
                    'message' => $occurrence->unread,
                    'issueId' => 'regex.lint.source.unreadable',
                    'source' => $occurrence->source,
                ];

                if (null !== $done) {
                    $done(1);
                }

                continue;
            }

            $validation = $this->regex->validate($occurrence->pattern);
            if (null !== $analyzed && !$validation->isValid) {
                $analyzed[$occurrence->pattern] = [$validation, null];
            }
            $source = $occurrence->source;
            if (!$validation->isValid && ValidationErrorCategory::Limit === $validation->category) {
                // The library gave up: a warning that the pattern was not
                // analyzed, no verdict on a pattern PCRE may compile.
                $issues[] = [
                    'type' => 'warning',
                    'file' => $occurrence->file,
                    'line' => $occurrence->line,
                    'column' => $this->resolveColumn($occurrence),
                    'fileOffset' => $occurrence->fileOffset,
                    'position' => $validation->offset,
                    'message' => 'Not analyzed: '.($validation->error ?? 'a limit of the analysis was reached.'),
                    'source' => $source,
                    'validation' => $validation,
                    'tip' => $validation->hint,
                ];

                if (null !== $done) {
                    $done(1);
                }

                continue;
            }

            if (!$validation->isValid) {
                $message = $validation->error ?? 'Invalid regex.';
                if ($this->ignoreParseErrors && $this->isLikelyPartialRegexError($message)) {
                    if (null !== $done) {
                        $done(1);
                    }

                    continue;
                }

                $issues[] = [
                    'type' => 'error',
                    'file' => $occurrence->file,
                    'line' => $occurrence->line,
                    'column' => $this->resolveColumn($occurrence),
                    'fileOffset' => $occurrence->fileOffset,
                    'position' => $validation->offset,
                    'message' => $message,
                    'source' => $source,
                    'validation' => $validation,
                    'tip' => $this->getTipForValidationError($message, $occurrence->pattern, $validation),
                ];

                if (null !== $done) {
                    $done(1);
                }

                continue;
            }

            // Valid on the floor: a later PHP of the range may refuse it.
            // The lint rules and the risk analysis still judge the floor.
            $rangeError = $this->rangeError($occurrence);
            if (null !== $rangeError) {
                [$target, $refusedOn, $rangeValidation] = $rangeError;
                $message = \sprintf('On %s: %s', $refusedOn, $rangeValidation->error ?? 'Invalid regex.');
                $issues[] = [
                    'type' => 'error',
                    'file' => $occurrence->file,
                    'line' => $occurrence->line,
                    'column' => $this->resolveColumn($occurrence),
                    'fileOffset' => $occurrence->fileOffset,
                    'position' => $rangeValidation->offset,
                    'message' => $message,
                    'source' => $source,
                    'validation' => $rangeValidation,
                    'tip' => $this->getTipForValidationError($message, $occurrence->pattern, $rangeValidation),
                    'target' => ProjectTarget::describe($target),
                ];
            }

            $ast = $this->regex->parse($occurrence->pattern);
            if (null !== $analyzed) {
                $analyzed[$occurrence->pattern] = [$validation, AstSerializer::serialize($ast)];
            }

            // Accepted everywhere, the pattern may still read otherwise on a
            // later PHP: the same text, another tree.
            $readsOtherwise = null === $rangeError ? $this->readsOtherwise($occurrence, $ast) : null;
            if (null !== $readsOtherwise) {
                $issues[] = [
                    'type' => 'warning',
                    'file' => $occurrence->file,
                    'line' => $occurrence->line,
                    'column' => $this->resolveColumn($occurrence),
                    'fileOffset' => $occurrence->fileOffset,
                    'issueId' => self::ISSUE_ID_MEANING_CHANGES,
                    'message' => \sprintf('From PHP %s (PCRE2 %s) the pattern parses differently: the same text means something else there.', ProjectTarget::phpLabel($readsOtherwise->phpVersionId), $readsOtherwise->pcreVersion),
                    'hint' => 'Write the part that differs in a form every PHP of the range reads alike: "a{0,3}" for a quantifier, "a\{,3}" for the text.',
                    'source' => $source,
                    'target' => ProjectTarget::describe($readsOtherwise),
                ];
            }

            $skipRiskAnalysis = $this->shouldSkipRiskAnalysis($occurrence);
            $lintIssues = [];

            // Run linter if enabled
            if ($this->lintEnabled) {
                $linter = PatternLinter::withDfaCache($this->lintRules, $this->dfas(), $this->regex->target());
                $ast->accept($linter);
                $code = null;

                foreach ($linter->getIssues() as $issue) {
                    if ($skipRiskAnalysis && isset(RedosVerdict::HEURISTIC_ISSUE_IDS[$issue->id])) {
                        continue;
                    }

                    // A raw character the PHP literal spells with an escape,
                    // as a double-quoted "\t", is no raw character in the
                    // source; without the source, the spelling is unknown.
                    if (InvisibleCharacterRule::ID === $issue->id) {
                        $code ??= null === $occurrence->fileOffset ? '' : (string) @file_get_contents($occurrence->file);
                        if ('' === $code || !LiteralSpelling::holdsRaw($code, (int) $occurrence->fileOffset, InvisibleCharacterRule::reportedCharacter($ast->source ?? '', (int) $issue->offset))) {
                            continue;
                        }
                    }

                    // No automatic rewrite for the risk rules: wrapping the
                    // operand in an atomic group while leaving the outer
                    // quantifier in place removes the cross-iteration
                    // backtracking the engine may need, so any rewrite the
                    // user applies has to be verified by hand.
                    $issueEntry = [
                        'type' => self::issueType($issue->severity),
                        'file' => $occurrence->file,
                        'line' => $occurrence->line,
                        'column' => $this->resolveColumn($occurrence),
                        'fileOffset' => $occurrence->fileOffset,
                        'position' => $issue->offset,
                        'issueId' => $issue->id,
                        'message' => $issue->message,
                        'hint' => $issue->hint,
                        'source' => $source,
                    ];

                    $lintIssues[] = $issueEntry;
                }
            }

            $riskIssues = [];
            if (!$skipRiskAnalysis) {
                if ($validation->complexityScore >= $this->warningThreshold) {
                    $riskIssues[] = [
                        'type' => 'warning',
                        'file' => $occurrence->file,
                        'line' => $occurrence->line,
                        'column' => $this->resolveColumn($occurrence),
                        'fileOffset' => $occurrence->fileOffset,
                        'issueId' => self::ISSUE_ID_COMPLEXITY,
                        'message' => \sprintf('Pattern is complex (score: %d).', $validation->complexityScore),
                        'source' => $source,
                    ];
                }

                $redos = (new RedosAnalyzer($this->regex))->analyze(
                    $occurrence->pattern,
                    $this->redosSeverityThreshold,
                    $this->redosMode,
                    $this->redosConfirmOptions,
                );

                // The heuristic rules guess what the analysis just proved:
                // once it shows the pattern linear, their warnings go.
                if ($redos->isProvenSafe()) {
                    $lintIssues = array_values(array_filter(
                        $lintIssues,
                        static fn (array $issue): bool => !isset(RedosVerdict::HEURISTIC_ISSUE_IDS[$issue['issueId']]),
                    ));
                }

                if (RedosVerdict::reported($redos, $this->redosSeverityThreshold, $this->redosMode)) {
                    $riskIssues[] = [
                        'type' => RedosVerdict::isError($redos) ? 'error' : 'warning',
                        'file' => $occurrence->file,
                        'line' => $occurrence->line,
                        'column' => $this->resolveColumn($occurrence),
                        'fileOffset' => $occurrence->fileOffset,
                        'issueId' => RedosVerdict::ISSUE_ID,
                        'message' => RedosVerdict::message($redos),
                        'hint' => $this->getReDoSHint($redos, $occurrence->pattern),
                        'source' => $source,
                        'analysis' => $redos,
                    ];
                }

                // One attempt proven linear, the search retrying it is not.
                $searchCost = $redos->searchCost;
                if (null !== $searchCost && $this->shouldReportSearchCost($searchCost)) {
                    $riskIssues[] = [
                        'type' => 'warning',
                        'file' => $occurrence->file,
                        'line' => $occurrence->line,
                        'column' => $this->resolveColumn($occurrence),
                        'fileOffset' => $occurrence->fileOffset,
                        'issueId' => RedosVerdict::SEARCH_ISSUE_ID,
                        'message' => RedosVerdict::searchMessage($searchCost),
                        'hint' => implode(' ', RedosVerdict::searchEvidence($searchCost, $redos->pcreVersion)),
                        'source' => $source,
                        'analysis' => $redos,
                    ];
                }
            }

            array_push($issues, ...$lintIssues, ...$riskIssues);

            if (null !== $done) {
                $done(1);
            }
        }

        return $issues;
    }

    /**
     * What the PHP versions of the range after the floor refuse in a
     * pattern the floor accepts: the lowest target that refuses it, the
     * versions that do ("8.5 and later"), and its verdict. Null when every
     * one accepts it.
     *
     * @return array{PcreTarget, string, ValidationResult}|null
     */
    private function rangeError(PatternOccurrence $occurrence): ?array
    {
        $lowest = null;
        $runs = [];
        $from = null;
        foreach ($this->range as $parser) {
            $validation = $parser->validate($occurrence->pattern);
            if ($validation->isValid) {
                // Unreachable with today's rules: none stops refusing a
                // pattern at a later PHP. It names the stretch the day one does.
                if (null !== $from) {
                    $runs[] = \sprintf('PHP %s and later, before %s', $from, ProjectTarget::phpLabel($parser->target()->phpVersionId));
                    $from = null;
                }

                continue;
            }

            $lowest ??= [$parser->target(), $validation];
            $from ??= ProjectTarget::phpLabel($parser->target()->phpVersionId);
        }

        if (null === $lowest) {
            return null;
        }
        if (null !== $from) {
            $runs[] = \sprintf('PHP %s and later', $from);
        }

        return [$lowest[0], implode('; ', $runs), $lowest[1]];
    }

    /**
     * The lowest PHP of the range after the floor that accepts the pattern
     * but parses it into another tree, as "a{,3}", text before PCRE2 10.43
     * and a quantifier from it; null when every one reads it as the floor
     * does, or when the rules map turns the check off.
     */
    private function readsOtherwise(PatternOccurrence $occurrence, NodeInterface $floor): ?PcreTarget
    {
        if ([] === $this->range
            || false === ($this->lintRules[self::ISSUE_ID_MEANING_CHANGES] ?? null)
            || false === ($this->lintRules[substr(self::ISSUE_ID_MEANING_CHANGES, \strlen('regex.lint.'))] ?? null)) {
            return null;
        }

        $tree = $floor->accept(new NodeDumper());
        foreach ($this->range as $parser) {
            if ($parser->parse($occurrence->pattern)->accept(new NodeDumper()) !== $tree) {
                return $parser->target();
            }
        }

        return null;
    }

    /**
     * The 1-based column of the pattern in its file, null when the source
     * does not know it: none is invented.
     */
    private function resolveColumn(PatternOccurrence $occurrence): ?int
    {
        return $occurrence->column;
    }

    /**
     * @param array<PatternOccurrence> $patterns
     *
     * @return list<array{file: string, line: int, column?: int|null, fileOffset?: int|null, analysis: RedosAnalysis}>
     */
    private function analyzeRedosChunk(array $patterns, RedosSeverity $threshold): array
    {
        $issues = [];

        foreach ($patterns as $occurrence) {
            if ($occurrence->isIgnored || null !== $occurrence->unread || null !== $occurrence->parserFallback) {
                continue;
            }

            $validation = $this->regex->validate($occurrence->pattern);
            if (!$validation->isValid) {
                continue;
            }

            $analysis = (new RedosAnalyzer($this->regex))->analyze(
                $occurrence->pattern,
                $threshold,
                $this->redosMode,
                $this->redosConfirmOptions,
            );

            if (!RedosVerdict::reported($analysis, $threshold, $this->redosMode)) {
                continue;
            }

            $issues[] = [
                'file' => $occurrence->file,
                'line' => $occurrence->line,
                'column' => $this->resolveColumn($occurrence),
                'fileOffset' => $occurrence->fileOffset,
                'analysis' => $analysis,
            ];
        }

        return $issues;
    }

    /**
     * @param array<PatternOccurrence>                                  $patterns
     * @param array<string, array{0: ValidationResult, 1: string|null}> $analyzed what the analysis read of each pattern
     * @param (\Closure(int): void)|null                                $done     told of each pattern looked at
     *
     * @return list<OptimizationEntry>
     */
    private function suggestOptimizationsChunk(array $patterns, int $minSavings, OptimizerOptions $options, array $analyzed, ?\Closure $done = null): array
    {
        $suggestions = [];

        foreach ($patterns as $occurrence) {
            $suggestion = $this->suggestOptimization($occurrence, $minSavings, $options, $analyzed[$occurrence->pattern] ?? null);
            if (null !== $suggestion) {
                $suggestions[] = $suggestion;
            }

            if (null !== $done) {
                $done(1);
            }
        }

        return $suggestions;
    }

    /**
     * @param array{0: ValidationResult, 1: string|null}|null $analyzed what the analysis read of the pattern
     *
     * @return OptimizationEntry|null
     */
    private function suggestOptimization(PatternOccurrence $occurrence, int $minSavings, OptimizerOptions $options, ?array $analyzed = null): ?array
    {
        $verifyWithAutomata = $options->verifyWithAutomata;

        if ($occurrence->isIgnored || null !== $occurrence->unread || null !== $occurrence->parserFallback) {
            return null;
        }

        [$validation, $tree] = $analyzed ?? [$this->regex->validate($occurrence->pattern), null];
        $source = $occurrence->source;
        if (!$validation->isValid) {
            return null;
        }

        // The tree the analysis built, where every parse of the pattern
        // below looks first: the rewrite and its checks parse it again.
        $ast = null === $tree ? null : AstSerializer::unserialize($tree);
        if (null !== $ast) {
            $this->regex->remember($occurrence->pattern, $ast);
        }

        $isExtended = $this->usesExtendedMode($occurrence->pattern);

        try {
            if ($isExtended) {
                // Under /x, factorizing alternatives would reflow what the
                // author laid out: it stays off whatever the options say.
                $optimizer = new Rewriter(
                    optimizeDigits: $options->digits,
                    optimizeWord: $options->word,
                    ranges: $options->ranges,
                    canonicalizeCharClasses: $options->canonicalizeCharClasses,
                    possessive: $options->possessive,
                    factorize: false,
                    minQuantifierCount: $options->minQuantifierCount,
                );

                $ast = $this->regex->parse($occurrence->pattern);
                $optimizedAst = $ast->accept($optimizer);

                // The rewrite is written into the pattern as laid out,
                // only its changed parts printed again; a tree without
                // that text is pretty-printed on both sides instead.
                $splicer = new LayoutSplicer($this->regex);
                $baseline = $splicer->splice($ast, $ast);
                $optimizedPattern = $optimizedAst instanceof RegexNode ? $splicer->splice($ast, $optimizedAst) : null;
                if (null === $baseline || null === $optimizedPattern) {
                    $pretty = str_contains($ast->flags, 'x');
                    $baseline = $ast->accept(new PatternPrinter($pretty));
                    $optimizedPattern = $optimizedAst->accept(new PatternPrinter($pretty));
                }

                if ($baseline === $optimizedPattern) {
                    return null;
                }

                if ($verifyWithAutomata) {
                    $isEquivalent = $this->verifyOptimizationWithAutomata($baseline, $optimizedPattern);
                    if (false === $isEquivalent) {
                        return null;
                    }
                }

                $optimization = new OptimizationResult($baseline, $optimizedPattern, ['Optimized pattern.']);
            } else {
                $optimization = (new Optimizer($this->regex, $this->dfas()))->optimize($occurrence->pattern, $options);
            }
        } catch (\Throwable) {
            // A suggestion is optional: whatever stops one pattern's rewrite,
            // the run goes on without it.
            return null;
        }

        if (!$optimization->isChanged()) {
            return null;
        }

        $savings = \strlen($optimization->original) - \strlen($optimization->optimized);
        if ($savings < $minSavings) {
            return null;
        }

        return [
            'file' => $occurrence->file,
            'line' => $occurrence->line,
            'column' => $this->resolveColumn($occurrence),
            'fileOffset' => $occurrence->fileOffset,
            'optimization' => $optimization,
            'savings' => $savings,
            'source' => $source,
        ];
    }

    /**
     * The work run on the patterns, on the workers when there are several;
     * in the parent, it advances the progress itself.
     *
     * @template R
     *
     * @param array<PatternOccurrence>                                               $patterns
     * @param \Closure(list<PatternOccurrence>, (\Closure(int): void)|null): list<R> $work
     *
     * @throws LintException when a worker fails
     *
     * @return list<R>
     */
    private function runOnWorkers(array $patterns, int $workers, \Closure $work, ?LintProgress $progress): array
    {
        $patterns = array_values($patterns);

        if ($workers > 1 && \count($patterns) > 1 && ForkedWorkerPool::canFork()) {
            $results = (new ForkedWorkerPool())->run(
                $patterns,
                $workers,
                $work,
                $progress,
                'Parallel analysis failed',
                self::allowedWorkerClasses(),
                static fn (PatternOccurrence $occurrence): string => $occurrence->file,
            );
            if (null !== $results) {
                return $results;
            }
        }

        return $work($patterns, $progress instanceof LintProgress ? $progress->advance(...) : null);
    }

    /**
     * @return list<class-string>
     */
    private static function allowedWorkerClasses(): array
    {
        /** @var list<class-string>|null $allowed */
        static $allowed = null;
        if (null !== $allowed) {
            return $allowed;
        }

        $allowed = [
            AnalyzedPatterns::class,
            ValidationResult::class,
            ValidationErrorCategory::class,
            OptimizationResult::class,
            RedosAnalysis::class,
        ];

        // Every class of the AST and of the ReDoS result, found next to a class
        // of each: installed on its own, a sibling package is not at ../.
        $allowed = array_merge(
            $allowed,
            self::classNamesBeside(NodeInterface::class),
            self::classNamesBeside(RedosAnalysis::class),
        );

        $allowed = array_values(array_unique($allowed));

        return $allowed;
    }

    /**
     * The classes under the directory and namespace of $class, subdirectories
     * included; loaded in the parent, before any worker is forked.
     *
     * @param class-string $class
     *
     * @return list<class-string>
     */
    private static function classNamesBeside(string $class): array
    {
        $dir = \dirname((string) (new \ReflectionClass($class))->getFileName());
        $namespace = substr($class, 0, (int) strrpos($class, '\\') + 1);
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $path) {
            \assert($path instanceof \SplFileInfo);
            if ('php' !== $path->getExtension()) {
                continue;
            }

            $relative = substr($path->getPathname(), \strlen($dir) + 1, -4);
            $name = $namespace.str_replace(\DIRECTORY_SEPARATOR, '\\', $relative);
            // An interface is never unserialized.
            if (class_exists($name)) {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    private function shouldSkipRiskAnalysis(PatternOccurrence $occurrence): bool
    {
        $rawPattern = $occurrence->displayPattern ?? $occurrence->pattern;
        $fragment = $this->extractFragment($rawPattern);
        $body = $this->trimPatternBody($occurrence->pattern);

        // Patterns, fragments or full regexes, as the ignore list is documented.
        return $this->isIgnored($occurrence->pattern)
            || $this->isIgnored($fragment)
            || $this->isIgnored($body)
            || $this->isTriviallySafe($fragment)
            || $this->isTriviallySafe($body);
    }

    private function extractFragment(string $pattern): string
    {
        if ('' === $pattern) {
            return '';
        }

        $first = $pattern[0];
        $last = $pattern[-1];

        if ($first === $last && \in_array($first, self::PATTERN_DELIMITERS, true)) {
            $pattern = substr($pattern, 1, -1);
        }

        if (str_starts_with($pattern, '^')) {
            $pattern = substr($pattern, 1);
        }

        if (str_ends_with($pattern, '$')) {
            $pattern = substr($pattern, 0, -1);
        }

        return $pattern;
    }

    private function trimPatternBody(string $pattern): string
    {
        if ('' === $pattern) {
            return '';
        }

        $first = $pattern[0];
        $last = $pattern[-1];

        if ($first === $last) {
            $pattern = substr($pattern, 1, -1);
        }

        if (str_starts_with($pattern, '^')) {
            $pattern = substr($pattern, 1);
        }

        if (str_ends_with($pattern, '$')) {
            $pattern = substr($pattern, 0, -1);
        }

        return $pattern;
    }

    private function isIgnored(string $body): bool
    {
        if ('' === $body) {
            return false;
        }

        return \in_array($body, $this->ignoredPatterns, true);
    }

    private function isTriviallySafe(string $body): bool
    {
        if ('' === $body) {
            return false;
        }

        $parts = explode('|', $body);
        if (\count($parts) < 2) {
            return false;
        }

        foreach ($parts as $part) {
            if (!LibraryPcre::match('#^[A-Za-z0-9._-]+$#', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string> $userIgnored
     * @param array<string> $redosIgnored
     *
     * @return array<string>
     */
    private function buildIgnoredPatterns(array $userIgnored, array $redosIgnored): array
    {
        return array_values(array_unique([...$redosIgnored, ...$userIgnored]));
    }

    /**
     * Detect whether a pattern uses extended (/x) mode, where whitespace and
     * inline comments are significant for readability. We treat these patterns
     * specially to preserve comments and pretty formatting when suggesting
     * optimizations.
     */
    private function usesExtendedMode(string $pattern): bool
    {
        // Fast path: if there is no trailing flag block, there's no /x.
        $pattern = Ascii::trimLeadingSpaces($pattern);
        if ('' === $pattern) {
            return false;
        }

        try {
            /** @var array{0: string, 1: string, 2: string} $parts */
            $parts = PatternParser::extractPatternAndFlags($pattern, $this->regex->target());
        } catch (\Throwable) {
            // If we cannot reliably extract flags, fall back to not treating it
            // as extended mode to avoid false positives.
            return false;
        }

        $flags = $parts[1] ?? '';

        return \is_string($flags) && str_contains($flags, 'x');
    }

    private function getTipForValidationError(string $message, string $pattern, ValidationResult $validation): ?string
    {
        // Try to provide intelligent, pattern-specific tips
        $intelligentTip = $this->generateIntelligentTip($message, $pattern, $validation);
        if (null !== $intelligentTip) {
            return $intelligentTip;
        }

        // Fallback to generic tips
        return $this->getGenericTipForValidationError($message);
    }

    private function generateIntelligentTip(string $message, string $pattern, ValidationResult $validation): ?string
    {
        if (str_contains($message, 'No closing delimiter')) {
            return $this->suggestDelimiterFix($pattern);
        }

        if (str_contains($message, 'Unclosed character class')) {
            return $this->suggestCharacterClassFix($pattern);
        }

        if (str_contains($message, 'Invalid quantifier range')) {
            return $this->suggestQuantifierRangeFix($pattern);
        }

        if (str_contains($message, 'Backreference to non-existent group')) {
            return $this->suggestBackreferenceFix($pattern);
        }

        if (str_contains($message, 'Lookbehind is unbounded')) {
            return $this->suggestLookbehindFix($pattern, $validation);
        }

        return null;
    }

    private function suggestDelimiterFix(string $pattern): string
    {
        // Find the delimiter used
        if (!LibraryPcre::match('/^([#~\-%@!])(.*)$/', $pattern, $matches)) {
            $matches = ['', '/', $pattern];
        }

        $delimiter = $matches[1];
        $content = $matches[2];

        // Check if delimiter appears in content
        if (str_contains($content, $delimiter)) {
            return "Your pattern contains the delimiter '$delimiter' inside. Either escape it as \\$delimiter or use a different delimiter like #pattern#.";
        }

        // Missing closing delimiter
        $suggested = $pattern.$delimiter;

        return "Add the missing closing delimiter: $suggested";
    }

    private function suggestCharacterClassFix(string $pattern): ?string
    {
        // For patterns like /[a-z/ we need to add ] before the final delimiter
        if (str_contains($pattern, '[') && !str_contains($pattern, ']')) {
            // Find the last delimiter
            $lastDelimiterPos = strrpos($pattern, '/');
            if (false !== $lastDelimiterPos) {
                $suggested = substr_replace($pattern, ']', $lastDelimiterPos, 0);

                return "Add missing closing bracket: $suggested";
            }
        }

        return null;
    }

    private function suggestQuantifierRangeFix(string $pattern): ?string
    {
        // Look for quantifier ranges in the pattern
        if (LibraryPcre::match('/\{(\d+),(\d+)\}/', $pattern, $matches)) {
            $min = (int) $matches[1];
            $max = (int) $matches[2];

            if ($min > $max) {
                $fixed = '{'.$max.','.$min.'}';
                $suggested = str_replace($matches[0], $fixed, $pattern);

                return "Swap min and max values: $suggested";
            }
        }

        return null;
    }

    private function suggestBackreferenceFix(string $pattern): ?string
    {
        // Find all backreferences in the pattern
        if (LibraryPcre::matchAll('/\\\\(\d+)/', $pattern, $matches)) {
            // Count opening parentheses (capturing groups)
            $openCount = substr_count($pattern, '(');

            foreach ($matches[1] as $match) {
                $refNum = (int) $match;
                if ($refNum > $openCount) {
                    return "Backreference \\$refNum refers to group $refNum, but only $openCount capturing groups exist in the pattern. Valid backreferences are \\1 through \\$openCount.";
                }
            }
        }

        return null;
    }

    private function suggestLookbehindFix(string $pattern, ValidationResult $validation): ?string
    {
        $offset = $validation->offset ?? 0;

        // Find the lookbehind content
        $before = substr($pattern, 0, $offset);
        $lookbehindStart = strrpos($before, '(?<=');

        if (false === $lookbehindStart) {
            $lookbehindStart = strrpos($before, '(?<!');
        }

        if (false === $lookbehindStart) {
            return null;
        }

        $lookbehindContent = substr($pattern, $lookbehindStart, $offset - $lookbehindStart);

        // Check for unbounded quantifiers in lookbehind
        if (LibraryPcre::match('/[+*][?]?/', $lookbehindContent)) {
            return "Replace unbounded quantifiers in lookbehind with fixed-length alternatives. For example, change (?<=\w*) to (?<=\w{0,10}) with an appropriate maximum length.";
        }

        return null;
    }

    private function getGenericTipForValidationError(string $message): ?string
    {
        if (str_contains($message, 'No closing delimiter')) {
            return 'Escape "/" inside the pattern (\/) or use a different delimiter, e.g. #pattern#.';
        }

        if (str_contains($message, 'Unclosed character class')) {
            return 'Character classes must be closed with "]". Check for missing or extra "[".';
        }

        if (str_contains($message, 'Invalid quantifier range')) {
            return 'Quantifier ranges must have min <= max. For example, {3,2} is invalid; use {2,3} or {2} instead.';
        }

        if (str_contains($message, 'Unknown regex flag')) {
            return 'Only valid PCRE flags are: i (case-insensitive), m (multiline), s (dot matches newline), x (extended), U (ungreedy), J (duplicate names).';
        }

        if (str_contains($message, 'Backreference to non-existent group')) {
            return 'Backreferences like \\1 refer to capturing groups. Make sure the group number exists.';
        }

        if (str_contains($message, 'Lookbehind is unbounded')) {
            return 'Variable-length lookbehinds are not allowed in PCRE. Use fixed-length alternatives like (?<=\w{3}) instead of (?<=\w*).';
        }

        if (str_contains($message, 'Invalid conditional construct')) {
            return 'Conditionals need a valid condition: group reference (?(1)...), lookaround (?(?=...)...), or (?(DEFINE)...).';
        }

        return null;
    }

    private function getReDoSHint(RedosAnalysis $analysis, string $pattern): string
    {
        // The attack and its replay first: a hint cut short still shows them.
        $hints = RedosVerdict::evidence($analysis);
        $evidenceCount = \count($hints);

        if (!empty($analysis->recommendations)) {
            $hints = array_merge($hints, $analysis->recommendations);
        }

        if (null !== $analysis->vulnerablePart) {
            $hints[] = \sprintf('The risky part is: %s', $analysis->vulnerablePart);
        }

        $hotspot = $analysis->primaryHotspot();
        if (null !== $hotspot) {
            $hints[] = \sprintf('Hotspot offsets: %d-%d', $hotspot->start, $hotspot->end);
        }

        // Try to suggest specific fixes based on the pattern
        $patternHints = $this->suggestReDoSFixes($pattern, $analysis);
        if (!empty($patternHints)) {
            $hints = array_merge($hints, $patternHints);
        }

        if (\count($hints) === $evidenceCount) {
            $hints[] = 'Use possessive quantifiers (*+ instead of *, ++ instead of +, or {m,n}+ instead of {m,n}) to prevent ReDoS.';
        }

        // A replayed witness already says what the engine did, in its own line.
        // Any other reported confirmation that did not confirm is a skipped
        // one, which ran no check: its message gives the cause.
        if (RedosMode::Confirmed === $analysis->mode && null === $analysis->replayed && true === $analysis->confirmation?->confirmed) {
            $hints[] = 'Confirmation: bounded runtime checks observed evidence of excessive backtracking.';
        }

        $hints[] = 'Test with adversarial inputs like repeated strings followed by a non-matching character.';

        return implode(' ', $hints);
    }

    /**
     * The DFAs kept for the run: the one given, else the process's.
     */
    private function dfas(): DfaCacheInterface
    {
        return $this->dfaCache ?? LanguageQuestions::dfas();
    }

    /**
     * The optimizer's verdict on a rewrite: the $matches first, then the
     * strings matched.
     *
     * @return bool|null true when proven, false when disproven or when the
     *                   check gave up on its budget, null when the automata
     *                   do not read the pattern
     */
    private function verifyOptimizationWithAutomata(string $original, string $optimized): ?bool
    {
        try {
            return (new RewriteCheck(new LanguageSolver($this->regex, $this->dfas())))->verify($original, $optimized);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /**
     * @return array<string>
     */
    private function suggestReDoSFixes(string $pattern, RedosAnalysis $analysis): array
    {
        $hints = [];

        // Look for common vulnerable patterns and suggest fixes
        if (LibraryPcre::match('/\((?:(?:[^()][^)]*)?\)\+|\([^)]*(?:\+\)\)|\)\+))/', $pattern)) {
            $hints[] = 'Suggested (verify behavior): replace nested quantifiers like (a+)+ with atomic groups (?>a+) or possessive quantifiers a++.';
        }

        if (str_contains($pattern, '.*') && str_contains($pattern, '+')) {
            $hints[] = 'Suggested (verify behavior): use possessive quantifiers .*+ instead of .* to prevent backtracking.';
        }

        if (LibraryPcre::match('/\([^)]*\*\)/', $pattern)) {
            $hints[] = 'Suggested (verify behavior): replace * with *+ in groups to prevent backtracking or wrap in (?>...).';
        }

        // If we have a vulnerable subpattern, try to suggest a specific fix
        if (null !== $analysis->vulnerablePart) {
            $vulnerable = $analysis->vulnerablePart;
            if (LibraryPcre::match('/(\w+)\+(\)\+)/', $vulnerable, $matches)) {
                $char = $matches[1];
                $hints[] = "Suggested (verify behavior): replace ($char+)+ with atomic group (?>$char+) or possessive $char++.";
            }
        }

        return $hints;
    }

    /**
     * The one mapping from a rule severity to an issue type: Critical and
     * Error fail the run, Warning warns, Style, Perf and Info inform.
     */
    private static function issueType(LintSeverity $severity): string
    {
        return match ($severity) {
            LintSeverity::Critical, LintSeverity::Error => 'error',
            LintSeverity::Warning => 'warning',
            LintSeverity::Style, LintSeverity::Perf, LintSeverity::Info => 'info',
        };
    }

    /**
     * Whether a search cost is reported: at or above the threshold, unless
     * its rule id is turned off, written in full or without "regex.lint."
     * as the rules map of regex.json writes it. It is a warning in every
     * mode: the per-attempt verdict behind it is linear.
     */
    private function shouldReportSearchCost(RedosSearchCost $cost): bool
    {
        if (false === ($this->lintRules[RedosVerdict::SEARCH_ISSUE_ID] ?? null)
            || false === ($this->lintRules[substr(RedosVerdict::SEARCH_ISSUE_ID, \strlen('regex.lint.'))] ?? null)) {
            return false;
        }

        return RedosVerdict::searchSeverity($cost)->rank() >= $this->redosSeverityThreshold->rank();
    }

    private function isLikelyPartialRegexError(string $errorMessage): bool
    {
        $indicators = [
            'No closing delimiter',
            'Regex too short',
            'Unknown modifier',
            'Unexpected end',
        ];

        foreach ($indicators as $indicator) {
            if (str_contains(Ascii::lower($errorMessage), Ascii::lower((string) $indicator))) {
                return true;
            }
        }

        return false;
    }
}

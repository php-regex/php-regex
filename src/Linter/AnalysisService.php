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
use PHPRegex\Linter\Advice\RedosHints;
use PHPRegex\Linter\Advice\ValidationTips;
use PHPRegex\Linter\Config\JudgedRange;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\Exception\LintException;
use PHPRegex\Linter\Extraction\LiteralSpelling;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Linter\Rule\InvisibleCharacterRule;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Linter\Rule\RuleId;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Cache\AstSerializer;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\Validation\RangeDiff;
use PHPRegex\Parser\Validation\RangeVerdict;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\IgnoreList;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * Handles regex-related analysis and transformations.
 *
 * @internal
 *
 * @phpstan-import-type OptimizationEntry from LintReport
 */
final class AnalysisService
{
    private const ISSUE_ID_COMPLEXITY = RuleCatalog::COMPLEXITY;

    private const ISSUE_ID_MEANING_CHANGES = RuleCatalog::MEANING_CHANGES;

    private readonly RedosSeverity $redosSeverityThreshold;

    private readonly IgnoreList $redosIgnoreList;

    private readonly RedosMode $redosMode;

    /**
     * The analysis of each set of versions a guard or a marker keeps, by
     * the versions.
     *
     * @var array<string, self>
     */
    private array $narrowed = [];

    /**
     * @param string                 $redosThreshold       the lowest severity reported: low, medium, high or
     *                                                     critical, in any case
     * @param array<string>          $redosIgnoredPatterns the patterns the risk analysis skips: a whole regex, its
     *                                                     body, or its body without the anchors around it, under
     *                                                     any delimiters and modifiers; a regex written with
     *                                                     modifiers skips the same body under those modifiers only
     * @param array<string, bool>    $lintRules
     * @param list<RegexParser>      $range                the parsers of the PHP versions after the floor a
     *                                                     pattern is validated at too (ProjectTarget::rangeParsers())
     * @param DfaCacheInterface|null $dfaCache             keeps the DFAs the lint rules and the optimizer's checks
     *                                                     build, for the whole run; the process's cache when null
     * @param JudgedRange|null       $judged               the versions judged and those the project installs on,
     *                                                     for a guard or a "@regex-target" marker to narrow; a
     *                                                     pattern is judged for the whole range when null
     *
     * @throws InvalidRegexOptionException when the threshold names no severity
     */
    public function __construct(
        private readonly RegexParser $regex,
        private readonly ?PatternExtractor $extractor = null,
        private readonly int $warningThreshold = 50,
        string $redosThreshold = 'high',
        private readonly array $redosIgnoredPatterns = [],
        RedosMode $redosMode = RedosMode::Theoretical,
        private readonly ?ConfirmationOptions $redosConfirmOptions = null,
        bool $redosEnabled = false,
        private readonly bool $lintEnabled = true,
        private readonly array $lintRules = [],
        private readonly array $range = [],
        private readonly ?DfaCacheInterface $dfaCache = null,
        private readonly ?JudgedRange $judged = null,
    ) {
        $this->redosSeverityThreshold = RedosSeverity::fromConfig($redosThreshold);
        $this->redosIgnoreList = new IgnoreList($redosIgnoredPatterns, $regex->target());

        $this->redosMode = $redosEnabled ? $redosMode : RedosMode::Off;
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
     * @param list<RegexParser> $range  the parsers of the PHP versions after the floor a pattern is
     *                                  validated at too (ProjectTarget::rangeParsers())
     * @param JudgedRange|null  $judged the versions a guard or a marker narrows (ProjectTarget::judgedRange())
     */
    public function withParser(RegexParser $parser, array $range = [], ?JudgedRange $judged = null): self
    {
        return $this->rebuild($parser, $range, $judged);
    }

    /**
     * Whether the analysis leaves off the check an issue identifier names:
     * a lint rule turned off, or all of them; the ReDoS analysis and its
     * search cost when off; the change of meaning across the range when
     * the check is off or the range is one version. False for every other
     * identifier, one the library does not know included.
     */
    public function leavesOff(string $identifier): bool
    {
        return match (true) {
            RedosVerdict::ISSUE_ID === $identifier => RedosMode::Off === $this->redosMode,
            RedosVerdict::SEARCH_ISSUE_ID === $identifier => RedosMode::Off === $this->redosMode || !$this->shouldReportSearchCostRule(),
            self::ISSUE_ID_MEANING_CHANGES === $identifier => !$this->comparesTrees() || [] === $this->range,
            RuleId::isLintRule($identifier) => !$this->lintEnabled || !RuleCatalog::enables($this->lintRules, $identifier),
            default => false,
        };
    }

    /**
     * The versions a guard or a marker narrows, when they are known.
     */
    public function judgedRange(): ?JudgedRange
    {
        return $this->judged;
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
     * @return list<LintIssue>
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
     * Whether the rules map leaves the search cost of the ReDoS check on.
     */
    private function shouldReportSearchCostRule(): bool
    {
        return RuleCatalog::enables($this->lintRules, RedosVerdict::SEARCH_ISSUE_ID);
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
     * @return list<LintIssue>
     */
    private function lintChunk(array $patterns, ?\Closure $done = null, ?array &$analyzed = null): array
    {
        $issues = [];
        // The files read for the spelling of a raw character, once each.
        $files = [];

        foreach ($patterns as $occurrence) {
            array_push($issues, ...$this->judge($occurrence, $analyzed, $files));

            if (null !== $done) {
                $done(1);
            }
        }

        return $issues;
    }

    /**
     * The issues of one occurrence: none for an occurrence that holds no
     * pattern to judge, the issues of the versions a guard keeps for a
     * guarded one, and otherwise its validation at the floor, then at the
     * other versions of the range, then the lint rules and the risk
     * analysis of a pattern the floor accepts.
     *
     * @param array<string, array{0: ValidationResult, 1: string|null}>|null $analyzed
     * @param array<string, string>                                          $files    the files read, by name
     *
     * @param-out ($analyzed is null ? null : array<string, array{0: ValidationResult, 1: string|null}>) $analyzed
     *
     * @return list<LintIssue>
     */
    private function judge(PatternOccurrence $occurrence, ?array &$analyzed, array &$files): array
    {
        // A file read with the tokenizer instead of the PHP parser is
        // counted in the stats, never reported.
        if ($occurrence->isIgnored || null !== $occurrence->parserFallback || null !== $occurrence->ignores) {
            return [];
        }

        // Code that runs on some versions of the range only is judged for
        // those, by the analysis of those versions; for none, it is dead
        // code there, and not judged.
        [$narrowed, $key] = $this->narrowedFor($occurrence);
        if (false === $narrowed) {
            return [];
        }

        if (null !== $narrowed) {
            $found = null === $analyzed ? null : [];
            $issues = self::underGuard($narrowed->lintChunk([$occurrence], null, $found), $narrowed, $occurrence->guard);
            foreach ($found ?? [] as $pattern => $read) {
                $analyzed[$key."\0".$pattern] = $read;
            }

            return $issues;
        }

        // A file the extractor could not read holds patterns no one linted.
        if (null !== $occurrence->unread) {
            return [new LintIssue(LintSeverity::Error, $occurrence->unread, $occurrence->file, 1, 1, issueId: RuleCatalog::SOURCE_UNREADABLE, source: $occurrence->source)];
        }

        $validation = $this->regex->validate($occurrence->pattern);
        if (!$validation->isValid) {
            if (null !== $analyzed) {
                $analyzed[$occurrence->pattern] = [$validation, null];
            }

            return $this->validationIssues($occurrence, $validation);
        }

        // Valid on the floor: a later PHP of the range may refuse it. The
        // lint rules and the risk analysis still judge the floor.
        $ast = $this->regex->parse($occurrence->pattern);
        if (null !== $analyzed) {
            $analyzed[$occurrence->pattern] = [$validation, AstSerializer::serialize($ast)];
        }

        $issues = $this->rangeIssues($occurrence, $ast);
        $skipRiskAnalysis = $this->shouldSkipRiskAnalysis($occurrence);
        $lintIssues = $this->lintEnabled ? $this->lintIssues($occurrence, $ast, $skipRiskAnalysis, $files) : [];
        if ($skipRiskAnalysis) {
            return [...$issues, ...$lintIssues];
        }

        $redos = (new RedosAnalyzer($this->regex))->analyze(
            $occurrence->pattern,
            $this->redosSeverityThreshold,
            $this->redosMode,
            $this->redosConfirmOptions,
        );

        // The heuristic rules guess what the analysis just proved: once it
        // shows the pattern linear, their warnings go.
        if ($redos->isProvenSafe()) {
            $lintIssues = array_values(array_filter(
                $lintIssues,
                static fn (LintIssue $issue): bool => !isset(RedosVerdict::HEURISTIC_ISSUE_IDS[(string) $issue->issueId]),
            ));
        }

        return [...$issues, ...$lintIssues, ...$this->riskIssues($occurrence, $validation, $redos)];
    }

    /**
     * The issue of a pattern the floor refuses: a warning that it was not
     * analysed when the library gave up at one of its own limits, as PCRE
     * may compile it; an error otherwise.
     *
     * @return list<LintIssue>
     */
    private function validationIssues(PatternOccurrence $occurrence, ValidationResult $validation): array
    {
        if (ValidationErrorCategory::Limit === $validation->category) {
            return [LintIssue::at($occurrence, LintSeverity::Warning, 'Not analyzed: '.($validation->error ?? 'a limit of the analysis was reached.'), position: $validation->offset, tip: $validation->hint, validation: $validation)];
        }

        $message = $validation->error ?? 'Invalid regex.';

        return [LintIssue::at($occurrence, LintSeverity::Error, $message, position: $validation->offset, tip: ValidationTips::forError($message, $occurrence->pattern, $validation), validation: $validation)];
    }

    /**
     * The issues of a pattern the floor accepts at the other versions of
     * the range: an error when a later PHP refuses it, else a warning when
     * a later PHP reads the same text as another tree, or matches the same
     * tree otherwise.
     *
     * @return list<LintIssue>
     */
    private function rangeIssues(PatternOccurrence $occurrence, RegexNode $ast): array
    {
        if ([] === $this->range) {
            return [];
        }

        $issues = [];
        $diff = (new RangeDiff($this->regex, $this->range))->diff($occurrence->pattern, $this->comparesTrees());
        $rangeError = self::rangeError($diff);
        if (null !== $rangeError) {
            [$target, $refusedOn, $rangeValidation] = $rangeError;
            $message = \sprintf('On %s: %s', $refusedOn, $rangeValidation->error ?? 'Invalid regex.');
            $issues[] = LintIssue::at(
                $occurrence,
                LintSeverity::Error,
                $message,
                position: $rangeValidation->offset,
                tip: $this->badEscapeTip($occurrence->pattern) ?? ValidationTips::forError($message, $occurrence->pattern, $rangeValidation),
                validation: $rangeValidation,
                target: ProjectTarget::describe($target),
            );
        }

        $readsOtherwise = $diff->readsOtherwise;
        if (null !== $readsOtherwise) {
            $issues[] = LintIssue::at(
                $occurrence,
                LintSeverity::Warning,
                \sprintf('From PHP %s (PCRE2 %s) the pattern parses differently: the same text means something else there.', ProjectTarget::phpLabel($readsOtherwise->phpVersionId), $readsOtherwise->pcreVersion),
                self::ISSUE_ID_MEANING_CHANGES,
                hint: 'Write the part that differs in a form every PHP of the range reads alike: "a{0,3}" for a quantifier, "a\{,3}" for the text.',
                target: ProjectTarget::describe($readsOtherwise),
            );
        }

        if ([] !== $issues) {
            return $issues;
        }

        $silentChange = $this->comparesTrees()
            ? SilentMeaningChange::first($ast, $this->regex->target(), array_map(static fn (RegexParser $parser): PcreTarget => $parser->target(), $this->range))
            : null;
        if (null === $silentChange) {
            return [];
        }

        return [LintIssue::at(
            $occurrence,
            LintSeverity::Warning,
            \sprintf('From PHP %s (PCRE2 %s) %s', ProjectTarget::phpLabel($silentChange->target->phpVersionId), $silentChange->target->pcreVersion, $silentChange->message),
            self::ISSUE_ID_MEANING_CHANGES,
            hint: $silentChange->hint,
            target: ProjectTarget::describe($silentChange->target),
        )];
    }

    /**
     * The findings of the lint rules, but the heuristic risk rules on a
     * pattern the risk analysis skips. None carries a rewrite: wrapping the
     * operand of a risk rule in an atomic group while the outer quantifier
     * stays removes the backtracking across iterations the engine may need.
     *
     * @param array<string, string> $files the files read, by name
     *
     * @return list<LintIssue>
     */
    private function lintIssues(PatternOccurrence $occurrence, RegexNode $ast, bool $skipRiskAnalysis, array &$files): array
    {
        $linter = PatternLinter::withDfaCache($this->lintRules, $this->dfas(), $this->regex->target());
        $ast->accept($linter);

        $issues = [];
        foreach ($linter->getIssues() as $issue) {
            if ($skipRiskAnalysis && isset(RedosVerdict::HEURISTIC_ISSUE_IDS[$issue->id])) {
                continue;
            }

            // A raw character the PHP literal spells with an escape, as a
            // double-quoted "\t", is no raw character in the source; without
            // the source, the spelling is unknown.
            if (InvisibleCharacterRule::ID === $issue->id) {
                $code = null === $occurrence->fileOffset ? '' : ($files[$occurrence->file] ??= (string) @file_get_contents($occurrence->file));
                if ('' === $code || !LiteralSpelling::holdsRaw($code, $occurrence->fileOffset, InvisibleCharacterRule::reportedCharacter($ast->source ?? '', (int) $issue->offset))) {
                    continue;
                }
            }

            $issues[] = LintIssue::at($occurrence, $issue->severity, $issue->message, $issue->id, $issue->offset, $issue->hint);
        }

        return $issues;
    }

    /**
     * The risks of a pattern: its complexity past the threshold, its ReDoS
     * verdict at or above the threshold, and the cost of an unanchored
     * search when one attempt is linear and the search is not.
     *
     * @return list<LintIssue>
     */
    private function riskIssues(PatternOccurrence $occurrence, ValidationResult $validation, RedosAnalysis $redos): array
    {
        $issues = [];
        if ($validation->complexityScore >= $this->warningThreshold) {
            $issues[] = LintIssue::at($occurrence, LintSeverity::Warning, \sprintf('Pattern is complex (score: %d).', $validation->complexityScore), self::ISSUE_ID_COMPLEXITY);
        }

        if (RedosVerdict::reported($redos, $this->redosSeverityThreshold, $this->redosMode)) {
            $issues[] = LintIssue::at($occurrence, self::redosSeverity($redos), RedosVerdict::message($redos), RedosVerdict::ISSUE_ID, hint: RedosHints::forAnalysis($redos, $occurrence->pattern), analysis: $redos);
        }

        $searchCost = $redos->searchCost;
        if (null !== $searchCost && $this->shouldReportSearchCost()) {
            $issues[] = LintIssue::at($occurrence, LintSeverity::Warning, RedosVerdict::searchMessage($searchCost), RedosVerdict::SEARCH_ISSUE_ID, hint: implode(' ', RedosVerdict::searchEvidence($searchCost, $redos->pcreVersion)), analysis: $redos);
        }

        return $issues;
    }

    /**
     * The same settings, judging with another parser and range.
     *
     * @param list<RegexParser> $range
     */
    private function rebuild(RegexParser $parser, array $range, ?JudgedRange $judged): self
    {
        return new self(
            $parser,
            $this->extractor,
            $this->warningThreshold,
            $this->redosSeverityThreshold->value,
            $this->redosIgnoredPatterns,
            $this->redosMode,
            $this->redosConfirmOptions,
            RedosMode::Off !== $this->redosMode,
            $this->lintEnabled,
            $this->lintRules,
            $range,
            $this->dfaCache,
            $judged,
        );
    }

    /**
     * The analysis judging an occurrence a guard or a marker narrows: that
     * of the versions it keeps, with their key, or false when it keeps
     * none. Null when the occurrence is judged for the whole range: no
     * guard, no versions known to narrow, or a guard that keeps them all.
     *
     * @return array{self|false|null, string}
     */
    private function narrowedFor(PatternOccurrence $occurrence): array
    {
        if (null === $occurrence->guard || null === $this->judged) {
            return [null, ''];
        }

        $targets = $this->judged->narrow($occurrence->guard);
        if ([] === $targets) {
            return [false, ''];
        }

        $key = implode(',', array_map(static fn (PcreTarget $target): string => $target->phpVersionId.'/'.$target->pcreVersion, $targets));
        if ($key === implode(',', array_map(static fn (PcreTarget $target): string => $target->phpVersionId.'/'.$target->pcreVersion, $this->judged->points()))) {
            return [null, ''];
        }

        $this->narrowed[$key] ??= $this->rebuild($this->judged->parserFor($targets[0]), array_map($this->judged->parserFor(...), \array_slice($targets, 1)), null);

        return [$this->narrowed[$key], $key];
    }

    /**
     * The issues of a narrowed analysis, each naming the versions judged:
     * the floor of that analysis for one the floor reports, the refusing
     * version for one of the range, with the constraint they come from.
     *
     * @param list<LintIssue> $issues
     *
     * @return list<LintIssue>
     */
    private static function underGuard(array $issues, self $narrowed, ?TargetConstraint $guard): array
    {
        $constraint = [];
        $php = $guard?->phpConstraint();
        if (null !== $php) {
            $constraint['guard'] = $php;
        }
        $pcre = $guard?->pcreConstraint();
        if (null !== $pcre) {
            $constraint['guard_pcre'] = $pcre;
        }

        return array_map(static fn (LintIssue $issue): LintIssue => $issue->withTarget(($issue->target ?? ProjectTarget::describe($narrowed->regex->target())) + $constraint), $issues);
    }

    /**
     * What the PHP versions of the range after the floor refuse in a
     * pattern the floor accepts: the lowest target that refuses it, the
     * versions that do ("8.5 and later"), and its verdict. Null when every
     * one accepts it.
     *
     * @return array{PcreTarget, string, ValidationResult}|null
     */
    private static function rangeError(RangeVerdict $diff): ?array
    {
        $lowest = $diff->refusedBy();
        if (null === $lowest) {
            return null;
        }

        $runs = [];
        $from = null;
        foreach ($diff->later as $verdict) {
            if ($verdict->validation->isValid) {
                // Unreachable with today's rules: none stops refusing a
                // pattern at a later PHP. It names the stretch the day one does.
                if (null !== $from) {
                    $runs[] = \sprintf('PHP %s and later, before %s', $from, ProjectTarget::phpLabel($verdict->target->phpVersionId));
                    $from = null;
                }

                continue;
            }

            $from ??= ProjectTarget::phpLabel($verdict->target->phpVersionId);
        }
        if (null !== $from) {
            $runs[] = \sprintf('PHP %s and later', $from);
        }

        return [$lowest->target, implode('; ', $runs), $lowest->validation];
    }

    /**
     * The tip for a pattern a later PHP of the range refuses that the floor
     * accepts only because it reads an escape its PCRE2 refuses as the
     * character after the backslash, as PHP 7.3 and 7.4 do: the "X"
     * modifier, which tells them not to, makes the floor refuse it too.
     * Null otherwise.
     */
    private function badEscapeTip(string $pattern): ?string
    {
        if ($this->regex->validate($pattern.'X')->isValid) {
            return null;
        }

        return 'The lowest PHP of the range reads an escape its PCRE2 refuses as the character after the backslash, as PHP 7.3 and 7.4 do without the "X" modifier, and a later one refuses it: write that character without the backslash, or "\\\\" for a backslash before it.';
    }

    /**
     * Whether a pattern every PHP of the range accepts has its trees
     * compared, as "a{,3}", text before PCRE2 10.43 and a quantifier from
     * it: unless the rules map turns the check off.
     */
    private function comparesTrees(): bool
    {
        return RuleCatalog::enables($this->lintRules, self::ISSUE_ID_MEANING_CHANGES);
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
            [$narrowed, $key] = $this->narrowedFor($occurrence);
            $suggestion = match (true) {
                null === $narrowed => $this->suggestOptimization($occurrence, $minSavings, $options, $analyzed[$occurrence->pattern] ?? null),
                $narrowed instanceof self => $narrowed->suggestOptimization($occurrence, $minSavings, $options, $analyzed[$key."\0".$occurrence->pattern] ?? null),
                default => null,
            };
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
        if ($occurrence->isIgnored || null !== $occurrence->unread || null !== $occurrence->parserFallback || null !== $occurrence->ignores) {
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

        // Under /x, factorizing alternatives would reflow what the author
        // laid out: it stays off whatever the options say. The optimizer
        // keeps the layout of the rest.
        if ($options->factorize && $this->usesExtendedMode($occurrence->pattern)) {
            $options = new OptimizerOptions(
                digits: $options->digits,
                word: $options->word,
                ranges: $options->ranges,
                canonicalizeCharClasses: $options->canonicalizeCharClasses,
                possessive: $options->possessive,
                factorize: false,
                minQuantifierCount: $options->minQuantifierCount,
                verifyWithAutomata: $options->verifyWithAutomata,
            );
        }

        try {
            $optimization = (new Optimizer($this->regex))->optimize($occurrence->pattern, $options);

            // The router splices a requirement into the pattern it compiles,
            // after the text of the path and before the rest of it: a
            // rewrite proven on the requirement alone proves nothing there,
            // where "a|ab" and "ab|a" capture other text before a "b?". Only
            // a rewrite that reads as the requirement does runs alike
            // wherever it stands.
            if (str_starts_with($source, 'route:') && $optimization->isChanged()
                && !(new LanguageSolver($this->regex, $this->dfas()))->readAlike($optimization->original, $optimization->optimized)) {
                return null;
            }
        } catch (ExceptionInterface) {
            // A suggestion is optional: a failure of the library while one
            // pattern is rewritten or checked leaves it without one, and the
            // run goes on. Anything else is a bug, and surfaces.
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
            'column' => $occurrence->column,
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
            LintIssue::class,
            LintSeverity::class,
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

    /**
     * Whether the risk analysis skips a pattern: one the ignore list names,
     * or an alternation of plain words.
     */
    private function shouldSkipRiskAnalysis(PatternOccurrence $occurrence): bool
    {
        $forms = $this->ignorableForms($occurrence);
        if ($this->redosIgnoreList->names($occurrence->pattern, $forms)) {
            return true;
        }

        foreach ($forms as $form) {
            if (self::isTriviallySafe($form)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The forms of a pattern the ignore list may name, as it is documented
     * to take patterns, fragments of patterns or whole regexes: the regex,
     * its body between the delimiters, whatever they and the modifiers
     * after them are, and the body without a "^" before it and a "$" after
     * it; for a route requirement, the requirement as written, with and
     * without those anchors.
     *
     * @return list<string>
     */
    private function ignorableForms(PatternOccurrence $occurrence): array
    {
        $forms = [$occurrence->pattern];

        try {
            [$body] = PatternParser::extractPatternAndFlags($occurrence->pattern, $this->regex->target());
            $forms[] = $body;
            $forms[] = self::unanchored($body);
        } catch (ParserException) {
            // Unreachable for a pattern the analysis validated; the whole
            // regex is still matched.
        }

        if (null !== $occurrence->displayPattern) {
            $forms[] = $occurrence->displayPattern;
            $forms[] = self::unanchored($occurrence->displayPattern);
        }

        return array_values(array_filter($forms, static fn (string $form): bool => '' !== $form));
    }

    /**
     * The fragment without the "^" it starts with and the "$" it ends with.
     */
    private static function unanchored(string $fragment): string
    {
        if (str_starts_with($fragment, '^')) {
            $fragment = substr($fragment, 1);
        }

        return str_ends_with($fragment, '$') ? substr($fragment, 0, -1) : $fragment;
    }

    /**
     * Whether a fragment is two words or more, of letters, digits, ".", "_"
     * and "-", as alternatives.
     */
    private static function isTriviallySafe(string $fragment): bool
    {
        $parts = explode('|', $fragment);
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
     * Detect whether a pattern uses extended (/x) mode, where whitespace and
     * inline comments are significant for readability. We treat these patterns
     * specially to preserve comments and pretty formatting when suggesting
     * optimizations.
     */
    private function usesExtendedMode(string $pattern): bool
    {
        try {
            [, $flags] = PatternParser::extractPatternAndFlags($pattern, $this->regex->target());
        } catch (ParserException) {
            // Flags that cannot be read are not taken for /x, to avoid
            // false positives.
            return false;
        }

        return str_contains($flags, 'x');
    }

    /**
     * The DFAs kept for the run: the one given, else the process's.
     */
    private function dfas(): DfaCacheInterface
    {
        return $this->dfaCache ?? LanguageQuestions::dfas();
    }

    /**
     * The severity of a reported ReDoS verdict: an error when it stands
     * confirmed at high severity or above, critical at critical, a warning
     * otherwise.
     */
    private static function redosSeverity(RedosAnalysis $redos): LintSeverity
    {
        if (!RedosVerdict::isError($redos)) {
            return LintSeverity::Warning;
        }

        return RedosSeverity::Critical === $redos->severity ? LintSeverity::Critical : LintSeverity::Error;
    }

    /**
     * Whether a search cost is reported: at or above the threshold, unless
     * its rule id is turned off, written in full or without "regex.lint."
     * as the rules map of regex.json writes it. It is a warning in every
     * mode: the per-attempt verdict behind it is linear.
     */
    private function shouldReportSearchCost(): bool
    {
        if (!$this->shouldReportSearchCostRule()) {
            return false;
        }

        return RedosVerdict::searchSeverity()->rank() >= $this->redosSeverityThreshold->rank();
    }
}

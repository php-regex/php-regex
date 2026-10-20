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

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Extraction\IgnoreMarker;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\ErrorCode;

/**
 * Aggregates pattern sources and produces lint results.
 *
 * @internal
 *
 * @phpstan-import-type TargetDescription from ProjectTarget
 * @phpstan-import-type OptimizationEntry from LintReport
 * @phpstan-import-type LintResult from LintReport
 * @phpstan-import-type LintStats from LintReport
 */
final class LintService
{
    /**
     * An inline marker, or an identifier it names, that silences nothing.
     */
    public const ISSUE_ID_UNMATCHED_IGNORE = 'regex.ignore.unmatched';

    /**
     * A "@regex-target" marker whose constraint cannot be read.
     */
    public const ISSUE_ID_TARGET_INVALID = 'regex.target.invalid';

    /**
     * A "@regex-target" marker that keeps no version the project is judged
     * for.
     */
    public const ISSUE_ID_TARGET_EMPTY = 'regex.target.empty';

    /**
     * An optimization a pattern can take.
     */
    public const ISSUE_ID_OPTIMIZATION = 'regex.optimization';

    private const ISSUE_ID_COMPLEXITY = 'regex.lint.complexity';

    /**
     * A route constraint is a fragment that carries no flag of its own: the
     * s, D and i a route source judges it under are the router's, and
     * nobody can remove them.
     */
    private const ROUTE_IGNORED_ISSUE_IDS = [
        'regex.lint.quantifier.nested' => true,
        'regex.lint.dotstar.nested' => true,
        'regex.lint.overlap.charset' => true,
        // Symfony's RouteCompiler, which Laravel's router goes through too,
        // matches every requirement with "{^...$}sD" (and "i" on a host): the
        // requirement can drop none of those flags.
        'regex.lint.flag.useless.s' => true,
        'regex.lint.flag.useless.D' => true,
        'regex.lint.flag.useless.i' => true,
        // The same D keeps the router's final "$" from matching before a
        // trailing newline.
        'regex.lint.anchor.dollarNewline' => true,
    ];

    public function __construct(private readonly AnalysisService $analysis, private readonly PatternSourceCollection $sources) {}

    /**
     * The same sources, analysed by another analysis.
     */
    public function withAnalysis(AnalysisService $analysis): self
    {
        return new self($analysis, $this->sources);
    }

    /**
     * @throws LintException when a worker fails
     *
     * @return array<PatternOccurrence>
     */
    public function collectPatterns(LintRequest $request, ?LintProgress $progress = null): array
    {
        $context = new PatternSourceContext(
            $request->paths,
            $request->excludePaths,
            $request->getDisabledSources(),
            $progress,
            $request->analysisWorkers,
            $request->declarationPaths,
            $request->vendorPaths,
            $request->excludeBase,
        );

        return $this->sources->collect($context);
    }

    /**
     * The progress goes through the analysis step, then the optimizations
     * step when the request asks for them.
     *
     * @param array<PatternOccurrence> $patterns
     *
     * @throws LintException when a worker fails
     */
    public function analyze(array $patterns, LintRequest $request, ?LintProgress $progress = null): LintReport
    {
        [$patterns, $markers, $targets] = self::readScans($patterns);
        $progress?->step(LintProgress::STEP_ANALYSIS, \count($patterns));
        // The optimizations take what the analysis read: no pattern is
        // parsed or validated twice.
        [$issues, $analyzed] = $request->checkOptimizations
            ? $this->analysis->lintForSuggestions($patterns, $progress, $request->analysisWorkers)
            : [$this->analysis->lint($patterns, $progress, $request->analysisWorkers), null];
        $progress?->finish();
        $issues = $this->filterLintIssues($issues);
        $issues = $this->filterIssuesByRequest($issues, $request);
        $issues = $this->deduplicateIssues($issues);

        /** @var array<array{file: string, line: int, optimization: OptimizationResult, savings: int, source?: string}> $optimizations */
        $optimizations = [];
        if ($request->checkOptimizations) {
            $progress?->step(LintProgress::STEP_OPTIMIZATIONS, \count($patterns));
            $optimizations = $this->keepRouteFlags(array_values($this->analysis->suggestOptimizations($patterns, $request->minSavings, $request->optimizations, $request->analysisWorkers, $progress, $analyzed)), $request->minSavings);
            $progress?->finish();
        }

        array_push($issues, ...$this->targetMarkerIssues($targets));
        $used = [];
        $issues = self::silence(array_values($issues), $markers, $used, static fn (LintIssue $issue): array => [$issue->file, $issue->line, $issue->id()]);
        $optimizations = self::silence(array_values($optimizations), $markers, $used, static fn (array $optimization): array => [$optimization['file'], $optimization['line'], self::ISSUE_ID_OPTIMIZATION]);
        if ($request->reportUnmatchedIgnores) {
            array_push($issues, ...self::unmatchedIgnores($markers, $used, fn (string $identifier): bool => $this->leavesOff($identifier, $request)));
        }

        $results = $this->combineResults($issues, $optimizations, $patterns);

        $stats = LintStatsCounter::count($results, $patterns);

        return new LintReport($results, $stats);
    }

    /**
     * Whether a run has nothing to analyse: no pattern, and no inline
     * marker to report.
     *
     * @param array<PatternOccurrence> $patterns
     */
    public static function holdsNothing(array $patterns, bool $reportUnmatchedIgnores): bool
    {
        foreach ($patterns as $pattern) {
            if (null === $pattern->ignores || ($reportUnmatchedIgnores && [] !== $pattern->ignores)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The occurrences to analyse, and the inline markers of each file read:
     * an occurrence a marker silences whole is analysed all the same when
     * the markers of its file are known, its issues dropped afterwards.
     *
     * @param array<PatternOccurrence> $patterns
     *
     * @return array{list<PatternOccurrence>, array<string, list<IgnoreMarker>>, array<string, list<TargetMarker>>}
     */
    private static function readScans(array $patterns): array
    {
        $markers = [];
        $targets = [];
        foreach ($patterns as $pattern) {
            if (null !== $pattern->ignores) {
                $markers[$pattern->file] = $pattern->ignores;
                $targets[$pattern->file] = $pattern->targets;
            }
        }

        $occurrences = [];
        foreach ($patterns as $pattern) {
            if (null !== $pattern->ignores) {
                continue;
            }

            $occurrences[] = $pattern->isIgnored && isset($markers[$pattern->file]) ? $pattern->analysed() : $pattern;
        }

        return [$occurrences, $markers, $targets];
    }

    /**
     * An error for each "@regex-target" marker that cannot be read, and for
     * each that keeps no version the project is judged for, on its line.
     *
     * @param array<string, list<TargetMarker>> $targets
     *
     * @return list<LintIssue>
     */
    private function targetMarkerIssues(array $targets): array
    {
        $judged = $this->analysis->judgedRange();
        $issues = [];
        foreach ($targets as $file => $fileTargets) {
            foreach ($fileTargets as $marker) {
                [$issueId, $message] = match (true) {
                    null === $marker->constraint => [self::ISSUE_ID_TARGET_INVALID, (string) $marker->error],
                    null !== $judged && [] === $judged->narrow($marker->constraint) => [self::ISSUE_ID_TARGET_EMPTY, \sprintf('The %s marker "%s" keeps no version the project is judged for (PHP %s): the patterns it covers are not judged.', $marker->marker, $marker->text, $judged->describe())],
                    default => [null, ''],
                };
                if (null === $issueId) {
                    continue;
                }

                $issues[] = new LintIssue(
                    LintSeverity::Error,
                    $message,
                    (string) $file,
                    $marker->line,
                    $marker->column,
                    issueId: $issueId,
                    hint: self::ISSUE_ID_TARGET_INVALID === $issueId
                        ? 'Write the versions the code runs on as a Composer constraint after "php", "pcre", or both.'
                        : 'Widen the marker to versions of the range, or remove the code it covers if it never runs there.',
                    source: 'php',
                );
            }
        }

        return $issues;
    }

    /**
     * The entries no marker silences; each marker that silences one is
     * recorded, with the identifier it silenced it under ("" for a marker
     * that names none).
     *
     * @template T
     *
     * @param list<T>                                        $entries
     * @param array<string, list<IgnoreMarker>>              $markers
     * @param array<string, array<int, array<string, true>>> $used    by file, the index of the marker, then the identifier
     * @param \Closure(T): array{string, int, ?string}       $locate  the file, the line and the identifier of an entry
     *
     * @return list<T>
     */
    private static function silence(array $entries, array $markers, array &$used, \Closure $locate): array
    {
        if ([] === $markers) {
            return $entries;
        }

        $kept = [];
        foreach ($entries as $entry) {
            [$file, $line, $id] = $locate($entry);
            $silenced = false;
            foreach ($markers[$file] ?? [] as $index => $marker) {
                if ($marker->coveredLine !== $line) {
                    continue;
                }

                if ([] === $marker->identifiers) {
                    $used[$file][$index][''] = true;
                    $silenced = true;
                } elseif (null !== $id && $marker->silences($id)) {
                    $used[$file][$index][$id] = true;
                    $silenced = true;
                }
            }

            if (!$silenced) {
                $kept[] = $entry;
            }
        }

        return $kept;
    }

    /**
     * An error for each marker that silenced nothing, and for each
     * identifier of a marker that silenced nothing under it, on the line of
     * the marker.
     *
     * @param array<string, list<IgnoreMarker>>              $markers
     * @param array<string, array<int, array<string, true>>> $used
     * @param \Closure(string): bool                         $leftOff whether the run leaves off the check an identifier names
     *
     * @return list<LintIssue>
     */
    private static function unmatchedIgnores(array $markers, array $used, \Closure $leftOff): array
    {
        $issues = [];
        foreach ($markers as $file => $fileMarkers) {
            foreach ($fileMarkers as $index => $marker) {
                $messages = $marker->unmatched($used[$file][$index] ?? [], $leftOff);
                foreach ($messages as $message) {
                    $issues[] = new LintIssue(
                        LintSeverity::Error,
                        $message,
                        (string) $file,
                        $marker->line,
                        $marker->column,
                        issueId: self::ISSUE_ID_UNMATCHED_IGNORE,
                        hint: 'A marker left in place would silence the next issue of its line unseen.',
                        source: 'php',
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Whether the run leaves off the check an issue identifier names: the
     * validation, the ReDoS analysis, the optimizations, the complexity
     * report, or what the analysis leaves off itself. An identifier the
     * library does not know is no check left off.
     */
    private function leavesOff(string $identifier, LintRequest $request): bool
    {
        return match (true) {
            null !== ErrorCode::tryFrom($identifier) => !$request->checkValidation,
            RedosVerdict::ISSUE_ID === $identifier, RedosVerdict::SEARCH_ISSUE_ID === $identifier => !$request->checkRedos || $this->analysis->leavesOff($identifier),
            self::ISSUE_ID_OPTIMIZATION === $identifier => !$request->checkOptimizations,
            self::ISSUE_ID_COMPLEXITY === $identifier => !$request->checkComplexity,
            default => $this->analysis->leavesOff($identifier),
        };
    }

    /**
     * Apply high-level toggles from the lint request (validation, ReDoS,
     * complexity).
     *
     * @param array<LintIssue> $issues
     *
     * @return list<LintIssue>
     */
    private function filterIssuesByRequest(array $issues, LintRequest $request): array
    {
        return array_values(array_filter($issues, static function (LintIssue $issue) use ($request): bool {
            if (!$request->checkValidation && null !== $issue->validation) {
                return false;
            }

            if (!$request->checkRedos && null !== $issue->analysis) {
                return false;
            }

            // "Pattern is complex (score: N).": reported only on demand.
            if (!$request->checkComplexity && self::ISSUE_ID_COMPLEXITY === $issue->issueId) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<LintIssue> $issues
     *
     * @return list<LintIssue>
     */
    private function filterLintIssues(array $issues): array
    {
        return array_values(array_filter($issues, static function (LintIssue $issue): bool {
            $issueId = $issue->issueId;

            if (str_starts_with($issue->source ?? '', 'route:')) {
                if (null !== $issueId && isset(self::ROUTE_IGNORED_ISSUE_IDS[$issueId])) {
                    return false;
                }

                // The RouteCompiler turns an unnamed group of a requirement
                // into a non-capturing one; a named group, reported as a
                // warning, still captures.
                if ('regex.lint.group.quantifiedCapture' === $issueId && 'info' === $issue->severity->level()) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * A route requirement is matched under the flags the router adds: its
     * rewrite keeps them, and one that only drops them is no rewrite. The
     * body was rewritten under those flags, and only a flag it no longer
     * reads was dropped, so putting them back changes nothing.
     *
     * @phpstan-param list<OptimizationEntry> $optimizations
     *
     * @phpstan-return list<OptimizationEntry>
     */
    private function keepRouteFlags(array $optimizations, int $minSavings): array
    {
        $kept = [];

        foreach ($optimizations as $entry) {
            if (!str_starts_with($entry['source'] ?? '', 'route:')) {
                $kept[] = $entry;

                continue;
            }

            $original = DelimitedPattern::fromDelimited($entry['optimization']->original);
            $optimized = DelimitedPattern::fromDelimited($entry['optimization']->optimized);
            $rewritten = (string) new DelimitedPattern($optimized->pattern, $original->flags, $optimized->delimiter);

            $savings = \strlen($entry['optimization']->original) - \strlen($rewritten);
            if ($savings < max(1, $minSavings)) {
                continue;
            }

            $entry['optimization'] = new OptimizationResult($entry['optimization']->original, $rewritten, $entry['optimization']->changes);
            $entry['savings'] = $savings;
            $kept[] = $entry;
        }

        return $kept;
    }

    /**
     * The issues with each one reported twice dropped: a file a run is
     * given twice is read twice. Two findings of one pattern that share
     * their message, as two useless quantifiers, stand apart by their
     * position in the pattern.
     *
     * @param list<LintIssue> $issues
     *
     * @return list<LintIssue>
     */
    private function deduplicateIssues(array $issues): array
    {
        $seen = [];
        $unique = [];

        foreach ($issues as $issue) {
            $key = implode("\0", [$issue->file, $issue->line, $issue->column, $issue->fileOffset, $issue->position, $issue->issueId, $issue->message]);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $issue;
        }

        return $unique;
    }

    /**
     * @param list<LintIssue>          $issues
     * @param array<PatternOccurrence> $originalPatterns
     *
     * @phpstan-param array<OptimizationEntry> $optimizations
     *
     * @phpstan-return array<LintResult>
     */
    private function combineResults(array $issues, array $optimizations, array $originalPatterns): array
    {
        $workingDirectory = getcwd();
        $workingDirectory = false === $workingDirectory ? null : $workingDirectory;
        $patternMap = $this->createPatternMap($originalPatterns, $workingDirectory);
        /** @var array<string, LintResult> $results */
        $results = [];

        $issues = array_map(static fn (LintIssue $issue): LintIssue => $issue->withFile(RelativePathHelper::relativize($issue->file, $workingDirectory)), $issues);
        // Grouping keeps the order it is given: issues sorted here come out
        // sorted inside each result, whatever order the workers returned.
        usort($issues, self::compareIssues(...));
        $optimizations = array_map(static function (array $optimization) use ($workingDirectory): array {
            $optimization['file'] = RelativePathHelper::relativize($optimization['file'], $workingDirectory);

            return $optimization;
        }, $optimizations);
        usort($optimizations, self::compareLocations(...));

        $this->addIssuesToResults($issues, $patternMap, $results);
        $this->addOptimizationsToResults($optimizations, $patternMap, $results);

        $results = array_values($results);
        usort($results, self::compareLocations(...));

        return $results;
    }

    /**
     * The order of the results: file (bytewise), line, column, offset in the
     * file, then source; an unknown value sorts first.
     *
     * @param array{file: string, line: int, column?: int|null, fileOffset?: int|null, source?: string|null, ...} $left
     * @param array{file: string, line: int, column?: int|null, fileOffset?: int|null, source?: string|null, ...} $right
     */
    private static function compareLocations(array $left, array $right): int
    {
        return [strcmp($left['file'], $right['file']) <=> 0, $left['line'], self::nullFirst($left['column'] ?? null), self::nullFirst($left['fileOffset'] ?? null), self::nullFirst($left['source'] ?? null)]
            <=> [0, $right['line'], self::nullFirst($right['column'] ?? null), self::nullFirst($right['fileOffset'] ?? null), self::nullFirst($right['source'] ?? null)];
    }

    /**
     * Inside one result: by position in the pattern, then identifier; an
     * unknown value sorts first.
     */
    private static function compareIssues(LintIssue $left, LintIssue $right): int
    {
        return [self::nullFirst($left->position), self::nullFirst($left->id())]
            <=> [self::nullFirst($right->position), self::nullFirst($right->id())];
    }

    /**
     * A sort key where null comes before any value: arrays compare element
     * by element, and a string against a string compares bytewise.
     *
     * @return array{int, int|string}
     */
    private static function nullFirst(int|string|null $value): array
    {
        return null === $value ? [0, 0] : [1, $value];
    }

    /**
     * @param array<PatternOccurrence> $originalPatterns
     *
     * @return array<string, array{pattern: string, location: string|null}>
     */
    private function createPatternMap(array $originalPatterns, ?string $workingDirectory): array
    {
        $map = [];
        foreach ($originalPatterns as $pattern) {
            $key = $this->createPatternKey(RelativePathHelper::relativize($pattern->file, $workingDirectory), $pattern->line, $pattern->source, $pattern->fileOffset);
            $map[$key] = [
                'pattern' => $pattern->displayPattern ?? $pattern->pattern,
                'location' => $pattern->location,
            ];
        }

        return $map;
    }

    private function createPatternKey(string $file, int $line, ?string $source = null, ?int $fileOffset = null): string
    {
        $offsetKey = null !== $fileOffset ? (string) $fileOffset : '';

        return $file.':'.$line.':'.$offsetKey.':'.($source ?? '');
    }

    /**
     * @param list<LintIssue> $issues
     *
     * @phpstan-param array<string, array{pattern: string, location: string|null}> $patternMap
     * @phpstan-param array<string, LintResult> $results
     */
    private function addIssuesToResults(array $issues, array $patternMap, array &$results): void
    {
        foreach ($issues as $issue) {
            $key = $this->createPatternKey($issue->file, $issue->line, $issue->source, $issue->fileOffset);

            $patternData = $patternMap[$key] ?? null;
            $results[$key] ??= $this->createResultStructure(
                ['file' => $issue->file, 'line' => $issue->line, 'column' => $issue->column, 'fileOffset' => $issue->fileOffset, 'source' => $issue->source],
                $patternData['pattern'] ?? null,
                $patternData['location'] ?? null,
            );
            $results[$key]['issues'][] = $issue;
        }
    }

    /**
     * @phpstan-param array<OptimizationEntry> $optimizations
     * @phpstan-param array<string, array{pattern: string, location: string|null}> $patternMap
     * @phpstan-param array<string, LintResult> $results
     */
    private function addOptimizationsToResults(array $optimizations, array $patternMap, array &$results): void
    {
        foreach ($optimizations as $opt) {
            $key = $this->createPatternKey(
                $opt['file'],
                $opt['line'],
                $opt['source'] ?? null,
                $opt['fileOffset'] ?? null,
            );
            $patternData = $patternMap[$key] ?? null;
            $pattern = \is_array($patternData) ? $patternData['pattern'] : null;
            $pattern ??= $opt['optimization']->original;
            $location = \is_array($patternData) ? $patternData['location'] : null;

            $results[$key] ??= $this->createResultStructure(
                $opt,
                $pattern,
                $location,
            );
            $results[$key]['optimizations'][] = $opt;
        }
    }

    /**
     * @param array{file: string, line: int, column?: int|null, fileOffset?: int|null, source?: string|null, ...} $item
     *
     * @return LintResult
     */
    private function createResultStructure(array $item, ?string $pattern, ?string $location = null): array
    {
        return [
            'file' => $item['file'],
            'line' => $item['line'],
            'column' => $item['column'] ?? null,
            'fileOffset' => $item['fileOffset'] ?? null,
            'source' => $item['source'] ?? null,
            'pattern' => $pattern,
            'location' => $location,
            'issues' => [],
            'optimizations' => [],
        ];
    }
}

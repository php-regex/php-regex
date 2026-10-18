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
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;

/**
 * Aggregates pattern sources and produces lint results.
 *
 * @internal
 *
 * @phpstan-import-type TargetDescription from ProjectTarget
 * @phpstan-import-type LintIssue from LintReport
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

    private const ISSUE_ID_COMPLEXITY = 'regex.lint.complexity';

    private const ISSUE_ID_OPTIMIZATION = 'regex.optimization';

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
        $issues = self::silence(array_values($issues), $markers, $used, self::issueIdOf(...));
        $optimizations = self::silence(array_values($optimizations), $markers, $used, static fn (array $optimization): string => self::ISSUE_ID_OPTIMIZATION);
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
     * Relative to the working directory, with "/" separators, when the file
     * lies under it; absolute otherwise. "./src", "src/", "../project/src"
     * and the absolute path all give the same value. A name that is not a
     * path (a stream URL, a route collection) is left as it is.
     */
    public static function displayPath(string $file, ?string $workingDirectory): string
    {
        if ('' === $file || null === $workingDirectory || str_contains($file, '://')) {
            return $file;
        }

        $path = self::slashes($file);
        $base = self::collapse(self::slashes($workingDirectory));
        $absolute = self::collapse('' !== self::root($path) ? $path : $base.'/'.$path);

        $relative = self::under($absolute, $base);
        if (null !== $relative) {
            return $relative;
        }

        // Through a symbolic link ($PWD may name the working directory by a
        // link, getcwd() never does): compare the resolved paths.
        $resolvedFile = realpath($file);
        $resolvedBase = realpath($workingDirectory);
        if (false !== $resolvedFile && false !== $resolvedBase) {
            $relative = self::under(self::slashes($resolvedFile), self::slashes($resolvedBase));
            if (null !== $relative) {
                return $relative;
            }
        }

        return $absolute;
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
     * @phpstan-return list<LintIssue>
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

                $issues[] = [
                    'type' => 'error',
                    'file' => (string) $file,
                    'line' => $marker->line,
                    'column' => $marker->column,
                    'issueId' => $issueId,
                    'message' => $message,
                    'hint' => self::ISSUE_ID_TARGET_INVALID === $issueId
                        ? 'Write the versions the code runs on as a Composer constraint after "php", "pcre", or both.'
                        : 'Widen the marker to versions of the range, or remove the code it covers if it never runs there.',
                    'source' => 'php',
                ];
            }
        }

        return $issues;
    }

    /**
     * The entries no marker silences; each marker that silences one is
     * recorded, with the identifier it silenced it under ("" for a marker
     * that names none).
     *
     * @template T of array{file: string, line: int, ...}
     *
     * @param list<T>                                        $entries
     * @param array<string, list<IgnoreMarker>>              $markers
     * @param array<string, array<int, array<string, true>>> $used    by file, the index of the marker, then the identifier
     * @param \Closure(T): ?string                           $idOf
     *
     * @return list<T>
     */
    private static function silence(array $entries, array $markers, array &$used, \Closure $idOf): array
    {
        if ([] === $markers) {
            return $entries;
        }

        $kept = [];
        foreach ($entries as $entry) {
            $id = $idOf($entry);
            $silenced = false;
            foreach ($markers[$entry['file']] ?? [] as $index => $marker) {
                if ($marker->coveredLine !== $entry['line']) {
                    continue;
                }

                if ([] === $marker->identifiers) {
                    $used[$entry['file']][$index][''] = true;
                    $silenced = true;
                } elseif (null !== $id && $marker->silences($id)) {
                    $used[$entry['file']][$index][$id] = true;
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
     * @phpstan-return list<LintIssue>
     */
    private static function unmatchedIgnores(array $markers, array $used, \Closure $leftOff): array
    {
        $issues = [];
        foreach ($markers as $file => $fileMarkers) {
            foreach ($fileMarkers as $index => $marker) {
                $messages = $marker->unmatched($used[$file][$index] ?? [], $leftOff);
                foreach ($messages as $message) {
                    $issues[] = [
                        'type' => 'error',
                        'file' => (string) $file,
                        'line' => $marker->line,
                        'column' => $marker->column,
                        'issueId' => self::ISSUE_ID_UNMATCHED_IGNORE,
                        'message' => $message,
                        'hint' => 'A marker left in place would silence the next issue of its line unseen.',
                        'source' => 'php',
                    ];
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
     * The identifier an issue is silenced under: its own, else the error
     * code of a pattern that does not compile.
     *
     * @phpstan-param LintIssue $issue
     */
    private static function issueIdOf(array $issue): ?string
    {
        return $issue['issueId'] ?? ($issue['validation'] ?? null)?->errorCode?->value;
    }

    /**
     * Apply high-level toggles from the lint request (validation, ReDoS,
     * complexity).
     *
     * @phpstan-param array<LintIssue> $issues
     *
     * @phpstan-return array<LintIssue>
     */
    private function filterIssuesByRequest(array $issues, LintRequest $request): array
    {
        return array_values(array_filter($issues, static function (array $issue) use ($request): bool {
            if (!$request->checkValidation && isset($issue['validation'])) {
                return false;
            }

            if (!$request->checkRedos && isset($issue['analysis'])) {
                return false;
            }

            // "Pattern is complex (score: N).": reported only on demand.
            if (!$request->checkComplexity && self::ISSUE_ID_COMPLEXITY === ($issue['issueId'] ?? null)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @phpstan-param array<LintIssue> $issues
     *
     * @phpstan-return array<LintIssue>
     */
    private function filterLintIssues(array $issues): array
    {
        return array_values(array_filter($issues, static function (array $issue): bool {
            $source = $issue['source'] ?? '';
            $issueId = $issue['issueId'] ?? null;

            if (str_starts_with($source, 'route:')) {
                if (\is_string($issueId) && isset(self::ROUTE_IGNORED_ISSUE_IDS[$issueId])) {
                    return false;
                }

                // The RouteCompiler turns an unnamed group of a requirement
                // into a non-capturing one; a named group, reported as a
                // warning, still captures.
                if ('regex.lint.group.quantifiedCapture' === $issueId && 'info' === $issue['type']) {
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
     * @phpstan-param array<LintIssue> $issues
     *
     * @phpstan-return array<LintIssue>
     */
    private function deduplicateIssues(array $issues): array
    {
        $seen = [];
        $unique = [];

        foreach ($issues as $issue) {
            $key = ($issue['file'] ?? '')
                .':'.($issue['line'] ?? 0)
                .':'.($issue['column'] ?? 0)
                .':'.($issue['fileOffset'] ?? '')
                .':'.($issue['message'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $issue;
        }

        return $unique;
    }

    /**
     * @param array<PatternOccurrence> $originalPatterns
     *
     * @phpstan-param array<LintIssue> $issues
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

        $issues = array_map(static fn (array $issue): array => self::normalizeIssue($issue, $workingDirectory), $issues);
        // Grouping keeps the order it is given: issues sorted here come out
        // sorted inside each result, whatever order the workers returned.
        usort($issues, self::compareIssues(...));
        $optimizations = array_map(static function (array $optimization) use ($workingDirectory): array {
            $optimization['file'] = self::displayPath($optimization['file'], $workingDirectory);

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
     * The file as every report shows it, and the identifier an invalid
     * pattern is known by: its error code.
     *
     * @phpstan-param LintIssue $issue
     *
     * @phpstan-return LintIssue
     */
    private static function normalizeIssue(array $issue, ?string $workingDirectory): array
    {
        $issue['file'] = self::displayPath($issue['file'], $workingDirectory);

        $errorCode = ($issue['validation'] ?? null)?->errorCode;
        if (!isset($issue['issueId']) && null !== $errorCode) {
            $issue['issueId'] = $errorCode->value;
        }

        return $issue;
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
     *
     * @phpstan-param LintIssue $left
     * @phpstan-param LintIssue $right
     */
    private static function compareIssues(array $left, array $right): int
    {
        return [self::nullFirst($left['position'] ?? null), self::nullFirst($left['issueId'] ?? null)]
            <=> [self::nullFirst($right['position'] ?? null), self::nullFirst($right['issueId'] ?? null)];
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

    private static function slashes(string $path): string
    {
        return '\\' === \DIRECTORY_SEPARATOR ? str_replace('\\', '/', $path) : $path;
    }

    /**
     * The root an absolute path starts with, "/" or a drive ("C:/"); empty
     * for a relative path.
     */
    private static function root(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return '/';
        }

        return \strlen($path) >= 3 && Ascii::isAlpha($path[0]) && ':/' === substr($path, 1, 2) ? substr($path, 0, 3) : '';
    }

    /**
     * "." and empty segments dropped, ".." resolved, without touching the
     * filesystem.
     */
    private static function collapse(string $path): string
    {
        $root = self::root($path);

        $segments = [];
        foreach (explode('/', substr($path, \strlen($root))) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $root.implode('/', $segments);
    }

    private static function under(string $path, string $base): ?string
    {
        $prefix = rtrim($base, '/').'/';

        return str_starts_with($path, $prefix) && \strlen($path) > \strlen($prefix) ? substr($path, \strlen($prefix)) : null;
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
            $key = $this->createPatternKey(self::displayPath($pattern->file, $workingDirectory), $pattern->line, $pattern->source, $pattern->fileOffset);
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
     * @phpstan-param array<LintIssue> $issues
     * @phpstan-param array<string, array{pattern: string, location: string|null}> $patternMap
     * @phpstan-param array<string, LintResult> $results
     */
    private function addIssuesToResults(array $issues, array $patternMap, array &$results): void
    {
        foreach ($issues as $issue) {
            $key = $this->createPatternKey(
                $issue['file'],
                $issue['line'],
                $issue['source'] ?? null,
                $issue['fileOffset'] ?? null,
            );

            $patternData = $patternMap[$key] ?? null;
            $pattern = \is_array($patternData) ? $patternData['pattern'] : null;
            $location = \is_array($patternData) ? $patternData['location'] : null;
            $results[$key] ??= $this->createResultStructure(
                $issue,
                $pattern,
                $location,
            );
            $results[$key]['issues'][] = $issue;
            $results[$key]['problems'][] = $this->createProblemFromIssue($issue);
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
            $results[$key]['problems'][] = $this->createProblemFromOptimization($opt);
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
            'problems' => [],
        ];
    }

    /**
     * @phpstan-param LintIssue $issue
     */
    private function createProblemFromIssue(array $issue): Diagnostic
    {
        $validation = $issue['validation'] ?? null;
        if ($validation instanceof ValidationResult) {
            $message = $issue['message'] ?? ($validation->error ?? 'Invalid regex.');
            $type = match ($validation->category) {
                ValidationErrorCategory::Semantic => DiagnosticType::Semantic,
                ValidationErrorCategory::Limit => DiagnosticType::Limit,
                default => DiagnosticType::Syntax,
            };

            return new Diagnostic(
                $type,
                DiagnosticType::Limit === $type ? LintSeverity::Warning : LintSeverity::Error,
                $message,
                $validation->errorCode?->value,
                $validation->offset,
                $validation->caretSnippet,
                $validation->hint,
            );
        }

        $analysis = $issue['analysis'] ?? null;
        // A search cost stands beside a linear attempt: a warning, with its
        // own attack and advice, never the per-attempt ones.
        if ($analysis instanceof RedosAnalysis && null !== $analysis->searchCost && RedosVerdict::SEARCH_ISSUE_ID === ($issue['issueId'] ?? null)) {
            return new Diagnostic(
                DiagnosticType::Security,
                $this->mapIssueSeverity($issue['type']),
                $issue['message'],
                $issue['issueId'],
                null,
                implode("\n", RedosVerdict::searchEvidence($analysis->searchCost, $analysis->pcreVersion)),
                null,
            );
        }

        if ($analysis instanceof RedosAnalysis) {
            $suggestion = $analysis->recommendations[0] ?? null;
            // The attack, already escaped, and its replay: the reports that
            // only keep the message and the suggestion still show them.
            $evidence = RedosVerdict::evidence($analysis);

            return new Diagnostic(
                DiagnosticType::Security,
                $this->mapRedosSeverity($analysis),
                $issue['message'],
                $issue['issueId'] ?? null,
                null,
                [] !== $evidence ? implode("\n", $evidence) : null,
                $suggestion,
            );
        }

        $position = isset($issue['position']) && \is_int($issue['position']) ? $issue['position'] : null;

        return new Diagnostic(
            DiagnosticType::Lint,
            $this->mapIssueSeverity($issue['type'] ?? 'info'),
            $issue['message'],
            $issue['issueId'] ?? null,
            $position,
            null,
            $issue['hint'] ?? null,
        );
    }

    /**
     * @phpstan-param OptimizationEntry $optimization
     */
    private function createProblemFromOptimization(array $optimization): Diagnostic
    {
        $message = \sprintf('Optimization available (saves %d chars).', $optimization['savings']);
        $suggestion = $optimization['optimization']->optimized;

        return new Diagnostic(
            DiagnosticType::Optimization,
            LintSeverity::Info,
            $message,
            'regex.optimization',
            null,
            null,
            $suggestion,
        );
    }

    private function mapIssueSeverity(string $type): LintSeverity
    {
        return match ($type) {
            'error' => LintSeverity::Error,
            'warning' => LintSeverity::Warning,
            default => LintSeverity::Info,
        };
    }

    private function mapRedosSeverity(RedosAnalysis $analysis): LintSeverity
    {
        if (!RedosVerdict::standsConfirmed($analysis)) {
            return match ($analysis->severity) {
                RedosSeverity::Low, RedosSeverity::Safe => LintSeverity::Info,
                default => LintSeverity::Warning,
            };
        }

        return match ($analysis->severity) {
            RedosSeverity::Critical => LintSeverity::Critical,
            RedosSeverity::High => LintSeverity::Error,
            RedosSeverity::Medium => LintSeverity::Warning,
            RedosSeverity::Unknown => LintSeverity::Warning,
            RedosSeverity::Low, RedosSeverity::Safe => LintSeverity::Info,
        };
    }
}

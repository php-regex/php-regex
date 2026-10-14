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
use PHPRegex\Linter\Internal\LintStatsCounter;
use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Internal\Ascii;
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
final readonly class LintService
{
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

    public function __construct(private AnalysisService $analysis, private PatternSourceCollection $sources) {}

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
        $progress?->step(LintProgress::STEP_ANALYSIS, \count($patterns));
        $issues = $this->analysis->lint($patterns, $progress, $request->analysisWorkers);
        $progress?->finish();
        $issues = $this->filterLintIssues($issues);
        $issues = $this->filterIssuesByRequest($issues, $request);
        $issues = $this->deduplicateIssues($issues);

        /** @var array<array{file: string, line: int, optimization: OptimizationResult, savings: int, source?: string}> $optimizations */
        $optimizations = [];
        if ($request->checkOptimizations) {
            $progress?->step(LintProgress::STEP_OPTIMIZATIONS, \count($patterns));
            $optimizations = $this->keepRouteFlags(array_values($this->analysis->suggestOptimizations($patterns, $request->minSavings, $request->optimizations, $request->analysisWorkers, $progress)), $request->minSavings);
            $progress?->finish();
        }

        $results = $this->combineResults($issues, $optimizations, $patterns);

        $stats = LintStatsCounter::count($results, $patterns);

        return new LintReport($results, $stats);
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
        // Each file is read once for its ignore comments, whatever the
        // number of its issues.
        $ignoredLines = [];
        foreach ($issues as $issue) {
            if ($this->shouldIgnoreIssue($issue, $ignoredLines)) {
                continue;
            }

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
     * @param array<string, array<int, true>> $ignoredLines by file, the lines a comment on the line above
     *                                                      ignores, filled as the files are read
     *
     * @phpstan-param LintIssue $issue
     */
    private function shouldIgnoreIssue(array $issue, array &$ignoredLines): bool
    {
        $source = $issue['source'] ?? '';
        if (str_starts_with($source, 'route:') || str_starts_with($source, 'validator:')) {
            return false;
        }

        $file = $issue['file'];
        if ('' === $file || !is_file($file)) {
            return false;
        }

        $line = $issue['line'];
        if ($line < 2) {
            return false;
        }

        if (!isset($ignoredLines[$file])) {
            $ignoredLines[$file] = [];
            $content = @file_get_contents($file);
            if (false !== $content) {
                foreach (explode("\n", $content) as $index => $text) {
                    if (str_contains($text, '// @regex-lint-ignore')) {
                        // A comment ignores the line below its own.
                        $ignoredLines[$file][$index + 2] = true;
                    }
                }
            }
        }

        return isset($ignoredLines[$file][$line]);
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

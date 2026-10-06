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

use PHPRegex\Linter\Internal\LintStatsCounter;
use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Optimizer\OptimizationResult;
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
 * @phpstan-type LintIssue array{type: string, message: string, file: string, line: int, column?: int|null, fileOffset?: int|null, position?: int|null, issueId?: string, hint?: string|null, tip?: string|null, source?: string, pattern?: string, regex?: string, analysis?: RedosAnalysis, validation?: ValidationResult}
 * @phpstan-type OptimizationEntry array{
 *     file: string,
 *     line: int,
 *     column?: int|null,
 *     fileOffset?: int|null,
 *     optimization: OptimizationResult,
 *     savings: int,
 *     source?: string
 * }
 * @phpstan-type LintResult array{file: string, line: int, column?: int|null, fileOffset?: int|null, source?: string|null, pattern: string|null, location?: string|null, issues: array<LintIssue>, optimizations: array<OptimizationEntry>, problems: array<Diagnostic>}
 * @phpstan-type LintStats array{errors: int, warnings: int, optimizations: int, redos?: int, infos?: int, lintErrors?: int}
 */
final readonly class LintService
{
    private const ROUTE_IGNORED_ISSUE_IDS = [
        'regex.lint.quantifier.nested' => true,
        'regex.lint.dotstar.nested' => true,
        'regex.lint.overlap.charset' => true,
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
     * @param callable(int, int): void|null $progress
     *
     * @return array<PatternOccurrence>
     */
    public function collectPatterns(LintRequest $request, ?callable $progress = null): array
    {
        $context = new PatternSourceContext(
            $request->paths,
            $request->excludePaths,
            $request->getDisabledSources(),
            $progress,
            $request->analysisWorkers,
        );

        return $this->sources->collect($context);
    }

    /**
     * @param array<PatternOccurrence> $patterns
     */
    public function analyze(array $patterns, LintRequest $request, ?callable $progress = null): LintReport
    {
        $issues = $this->analysis->lint($patterns, $progress, $request->analysisWorkers);
        $issues = $this->filterLintIssues($issues);
        $issues = $this->filterIssuesByRequest($issues, $request);
        $issues = $this->deduplicateIssues($issues);

        /** @var array<array{file: string, line: int, optimization: OptimizationResult, savings: int, source?: string}> $optimizations */
        $optimizations = $request->checkOptimizations
            ? array_values($this->analysis->suggestOptimizations($patterns, $request->minSavings, $request->optimizations, $request->analysisWorkers))
            : [];

        $results = $this->combineResults($issues, $optimizations, $patterns);

        $stats = LintStatsCounter::count($results);

        return new LintReport($results, $stats);
    }

    /**
     * Apply high-level toggles from the lint request (validation / ReDoS).
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

            // Hide complexity-based warnings ("Pattern is complex (score: N).")
            if (\is_string($issueId) && 'regex.lint.complexity' === $issueId) {
                return false;
            }

            if (str_starts_with($source, 'route:')) {
                if (\is_string($issueId) && isset(self::ROUTE_IGNORED_ISSUE_IDS[$issueId])) {
                    return false;
                }
            }

            return true;
        }));
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

    /**
     * Relative to the working directory, with "/" separators, when the file
     * lies under it; absolute otherwise. "./src", "src/", "../project/src"
     * and the absolute path all give the same value. A name that is not a
     * path (a stream URL, a route collection) is left as it is.
     */
    private static function displayPath(string $file, ?string $workingDirectory): string
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
            if ($this->shouldIgnoreIssue($issue)) {
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
     * @phpstan-param LintIssue $issue
     */
    private function shouldIgnoreIssue(array $issue): bool
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

        $content = @file_get_contents($file);
        if (false === $content) {
            return false;
        }

        $lines = explode("\n", $content);
        $prevLineIndex = $line - 2;

        return isset($lines[$prevLineIndex])
            && str_contains($lines[$prevLineIndex], '// @regex-lint-ignore');
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
            $type = ValidationErrorCategory::Semantic === $validation->category ? DiagnosticType::Semantic : DiagnosticType::Syntax;

            return new Diagnostic(
                $type,
                LintSeverity::Error,
                $message,
                $validation->errorCode?->value,
                $validation->offset,
                $validation->caretSnippet,
                $validation->hint,
            );
        }

        $analysis = $issue['analysis'] ?? null;
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

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

use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Json\JsonDocument;

/**
 * The steps of a lint run every front end takes alike, `regex lint` and
 * the bridges' regex:lint: the paths checked, the target resolved, the
 * patterns collected, then analysed into a report sorted by file and line.
 * Each step that cannot go on stops the run with a LintRunFailure naming
 * its stage and its exit code; what a front end writes of it is its own.
 *
 * @phpstan-import-type LintResult from LintReport
 *
 * @internal
 */
final class LintRunner
{
    public function __construct(private readonly LintService $lint) {}

    /**
     * Every path the run lints exists: a path that does not is a usage
     * error when the command line names it, a configuration error when a
     * setting does, never a run that finds nothing and passes.
     *
     * @param array<string> $paths
     * @param string|null   $setting what names the paths when they come from the configuration
     *
     * @throws LintRunFailure
     */
    public static function checkPaths(array $paths, ?string $setting = null): void
    {
        foreach ($paths as $path) {
            if ('' === $path || file_exists($path)) {
                continue;
            }

            throw null === $setting
                ? new LintRunFailure('Path not found: '.$path, JsonDocument::STAGE_USAGE, LintRunFailure::EXIT_INVALID)
                : new LintRunFailure(\sprintf('Path not found: %s (from %s)', $path, $setting), JsonDocument::STAGE_CONFIG, LintRunFailure::EXIT_INVALID);
        }
    }

    /**
     * What the front end's settings resolve to, the target first: a target
     * the run cannot judge for stops it at the target stage, a setting it
     * cannot read at the config stage, its message after the prefix that
     * names where the settings live.
     *
     * @param \Closure(): LintSetup $resolve
     *
     * @throws LintRunFailure
     */
    public static function resolve(\Closure $resolve, string $invalidOptionPrefix = ''): LintSetup
    {
        try {
            return $resolve();
        } catch (UnsupportedTargetException $e) {
            throw new LintRunFailure($e->getMessage(), JsonDocument::STAGE_TARGET, LintRunFailure::EXIT_INVALID, $e);
        } catch (InvalidRegexOptionException $e) {
            throw new LintRunFailure($invalidOptionPrefix.$e->getMessage(), JsonDocument::STAGE_CONFIG, LintRunFailure::EXIT_INVALID, $e);
        }
    }

    /**
     * The patterns of every source of the lint.
     *
     * @throws LintRunFailure
     *
     * @return array<PatternOccurrence>
     */
    public function collect(LintRequest $request, ?LintProgress $progress = null): array
    {
        try {
            return $this->lint->collectPatterns($request, $progress);
        } catch (\Throwable $e) {
            // A source runs the application's own code, its router and its
            // validator metadata: whatever fails there stops the run with
            // the stage named, not with a trace.
            throw new LintRunFailure('Failed to collect patterns: '.$e->getMessage(), JsonDocument::STAGE_COLLECT, LintRunFailure::EXIT_FAILURE, $e);
        }
    }

    /**
     * The patterns a run collected, without the files it read: a file holds
     * no pattern of its own, read for its markers or with the tokenizer.
     *
     * @param array<PatternOccurrence> $patterns
     */
    public static function patternCount(array $patterns): int
    {
        return \count(array_filter($patterns, static fn (PatternOccurrence $pattern): bool => null === $pattern->parserFallback && null === $pattern->ignores));
    }

    /**
     * The report of the patterns, with the results the front end adds (a
     * file it could not read) counted in, all sorted by file and line.
     *
     * @param array<PatternOccurrence> $patterns
     *
     * @phpstan-param list<LintResult> $extra
     *
     * @throws LintRunFailure when a worker fails
     */
    public function analyze(array $patterns, LintRequest $request, ?LintProgress $progress = null, array $extra = []): LintReport
    {
        try {
            $report = $this->lint->analyze($patterns, $request, $progress);
        } catch (LintException $e) {
            throw new LintRunFailure($e->getMessage(), JsonDocument::STAGE_ANALYZE, LintRunFailure::EXIT_FAILURE, $e);
        }

        if ([] === $extra) {
            return new LintReport(self::sorted($report->results), $report->stats);
        }

        $results = [...$report->results, ...$extra];

        return new LintReport(self::sorted($results), LintStatsCounter::count($results, $patterns));
    }

    /**
     * By file, bytewise, then line; the order the analysis gave stays
     * between equal ones.
     *
     * @phpstan-param array<LintResult> $results
     *
     * @phpstan-return list<LintResult>
     */
    private static function sorted(array $results): array
    {
        usort($results, static fn (array $a, array $b): int => [strcmp($a['file'], $b['file']) <=> 0, $a['line']] <=> [0, $b['line']]);

        return $results;
    }
}

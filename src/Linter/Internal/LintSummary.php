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

namespace PHPRegex\Linter\Internal;

use PHPRegex\Linter\LintReport;

/**
 * The errors of a lint summary, each under its own label: a pattern PCRE
 * refuses is an invalid pattern, a ReDoS verdict that fails the run is a
 * ReDoS error and a lint rule at Error a lint error, never an invalid
 * pattern (the pattern compiles).
 *
 * @phpstan-import-type LintStats from LintReport
 *
 * @internal
 */
final class LintSummary
{
    /**
     * "2 invalid patterns, 1 ReDoS errors, 1 lint errors"; a count of zero
     * is left out.
     *
     * @phpstan-param LintStats $stats
     */
    public static function errors(array $stats): string
    {
        $redos = $stats['redos'] ?? 0;
        $lintErrors = $stats['lintErrors'] ?? 0;
        $invalid = max(0, $stats['errors'] - $redos - $lintErrors);

        $labels = [];
        if ($invalid > 0 || (0 === $redos && 0 === $lintErrors)) {
            $labels[] = \sprintf('%d invalid patterns', $invalid);
        }

        if ($redos > 0) {
            $labels[] = \sprintf('%d ReDoS errors', $redos);
        }

        if ($lintErrors > 0) {
            $labels[] = \sprintf('%d lint errors', $lintErrors);
        }

        return implode(', ', $labels);
    }

    /**
     * What a failing run counts after its errors: "1 warnings, 0
     * optimizations", the infos between them once there are some ("1
     * warnings, 2 infos found, 0 optimizations").
     *
     * @phpstan-param LintStats $stats
     */
    public static function failureCounts(array $stats): string
    {
        $infos = $stats['infos'] ?? 0;
        if ($infos > 0) {
            return \sprintf('%d warnings, %d infos found, %d optimizations', $stats['warnings'], $infos, $stats['optimizations']);
        }

        return \sprintf('%d warnings, %d optimizations', $stats['warnings'], $stats['optimizations']);
    }

    /**
     * The lead of a passing run: "No issues found", "1 warnings found", or
     * once there are infos "0 warnings, 2 infos found", so a run printed
     * under info lines never claims no issues.
     *
     * @phpstan-param LintStats $stats
     */
    public static function passCounts(array $stats): string
    {
        $infos = $stats['infos'] ?? 0;
        if ($infos > 0) {
            return \sprintf('%d warnings, %d infos found', $stats['warnings'], $infos);
        }

        if ($stats['warnings'] > 0) {
            return \sprintf('%d warnings found', $stats['warnings']);
        }

        return 'No issues found';
    }
}

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
 * ReDoS error, never an invalid pattern (the pattern compiles).
 *
 * @phpstan-import-type LintStats from LintReport
 *
 * @internal
 */
final class LintSummary
{
    /**
     * "2 invalid patterns, 1 ReDoS errors"; a count of zero is left out.
     *
     * @phpstan-param LintStats $stats
     */
    public static function errors(array $stats): string
    {
        $redos = $stats['redos'] ?? 0;
        $invalid = max(0, $stats['errors'] - $redos);

        $labels = [];
        if ($invalid > 0 || 0 === $redos) {
            $labels[] = \sprintf('%d invalid patterns', $invalid);
        }

        if ($redos > 0) {
            $labels[] = \sprintf('%d ReDoS errors', $redos);
        }

        return implode(', ', $labels);
    }
}

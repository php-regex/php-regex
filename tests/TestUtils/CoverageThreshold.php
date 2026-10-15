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

namespace PHPRegex\Tests\TestUtils;

/**
 * The line coverage of a Clover report and the threshold it is held to.
 *
 * The coverage is the share of executable lines the run reached, read from
 * the project totals PHPUnit writes last in the report ("statements" and
 * "coveredstatements"), not summed from the files. The threshold is one
 * percentage, from 0 to 100, alone in its file.
 */
final class CoverageThreshold
{
    /**
     * The line coverage in percent, or null when the report holds no project
     * totals PHPUnit would write: not XML, no statement measured, or more
     * statements covered than measured.
     */
    public static function lineCoverage(string $cloverXml): ?float
    {
        if ('' === trim($cloverXml)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $clover = simplexml_load_string($cloverXml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (false === $clover) {
            return null;
        }

        $metrics = $clover->project->metrics ?? null;
        if (null === $metrics) {
            return null;
        }

        $statements = (int) $metrics['statements'];
        $covered = (int) $metrics['coveredstatements'];
        if ($statements <= 0 || $covered < 0 || $covered > $statements) {
            return null;
        }

        return 100 * $covered / $statements;
    }

    /**
     * The percentage the file holds, or null when it holds anything else.
     */
    public static function threshold(string $contents): ?float
    {
        $value = trim($contents);
        if (1 !== preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            return null;
        }

        $threshold = (float) $value;

        return $threshold <= 100.0 ? $threshold : null;
    }
}

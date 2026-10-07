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

namespace PHPRegex\Tests\Support;

/**
 * Twice the text must cost about twice the time: a reading that goes back
 * over the rest of the pattern for each unit takes four times as long.
 */
trait LinearTimeAssertions
{
    /**
     * Reads $size units then twice as many, and asserts each read takes
     * less than the budget and the larger one less than $maxRatio times the
     * smaller. Each size is timed at its best of three runs, so a pause of
     * the machine does not count.
     *
     * @param \Closure(int): void $read
     */
    private function assertLinearTime(\Closure $read, int $size, string $what, float $maxRatio = 3.0): void
    {
        $budget = 1.0;

        $small = self::bestTime($read, $size);
        $this->assertLessThan($budget, $small, \sprintf('%s x %d: %.3f s.', $what, $size, $small));

        $large = self::bestTime($read, 2 * $size);
        $this->assertLessThan($budget, $large, \sprintf('%s x %d: %.3f s, %.3f s for half as many.', $what, 2 * $size, $large, $small));

        // Below a few milliseconds the clock says more than the reading.
        if ($large >= 0.02) {
            $this->assertLessThan($maxRatio, $large / $small, \sprintf('%s: %.3f s for %d units, %.3f s for %d.', $what, $small, $size, $large, 2 * $size));
        }
    }

    /**
     * The best of three readings: a busy machine or a coverage driver slows
     * one reading down, rarely all three.
     *
     * @param \Closure(int): void $read
     */
    private static function bestTime(\Closure $read, int $size): float
    {
        $best = \INF;
        for ($run = 0; $run < 3; $run++) {
            $start = hrtime(true);
            $read($size);
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    }
}

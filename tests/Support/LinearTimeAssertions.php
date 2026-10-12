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
     * smaller. Each size is timed at its best of four runs, so a pause of
     * the machine does not count, and the ratio gets up to three
     * measurements.
     *
     * @param \Closure(int): void $read
     */
    private function assertLinearTime(\Closure $read, int $size, string $what, ?float $maxRatio = null): void
    {
        // The sizes above are chosen to stay well under a second on a dev
        // machine; a loaded laptop or a two-core CI runner reads them two to
        // three times slower, and its clock jitters a linear read up to three
        // and a half times the time at twice the size, so the budget bounds
        // the runaway and the ratio draws the line beyond the jitter: a
        // reading that goes back over the rest of the pattern for each unit
        // lands far above both.
        // A shared runner (CI set) gets those loose lines; a dev machine
        // keeps the strict ones, under which a quadratic read whose small
        // reading stays below the CI noise floor is still caught.
        $shared = false !== getenv('CI') && '' !== getenv('CI');
        $budget = $shared ? 4.0 : 3.0;
        $maxRatio ??= $shared ? 3.5 : 3.2;
        $noise = $shared ? 0.05 : 0.02;

        // A linear read doubles (2.1 to 2.4 on a dev machine); one burst of
        // the runner during either size can still push a ratio past the
        // line (3.56 on a macOS runner). A quadratic read is past it on every
        // measurement, so the ratio is measured up to three times and one
        // measurement under the line is enough.
        $readings = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $small = self::bestTime($read, $size);
            $this->assertLessThan($budget, $small, \sprintf('%s x %d: %.3f s.', $what, $size, $small));

            $large = self::bestTime($read, 2 * $size);
            $this->assertLessThan($budget, $large, \sprintf('%s x %d: %.3f s, %.3f s for half as many.', $what, 2 * $size, $large, $small));

            // Below a few tens of milliseconds the clock says more than the
            // reading on a shared runner, so the ratio is read only when BOTH
            // readings are above the noise.
            if ($small < $noise || $large < $noise) {
                return;
            }

            if ($large / $small < $maxRatio || 2 === $attempt) {
                $this->assertLessThan($maxRatio, $large / $small, \sprintf('%s: twice the units took more than %.1f times as long on every measurement: %s.', $what, $maxRatio, implode('; ', [...$readings, \sprintf('%.3f s for %d units, %.3f s for %d (%.2f)', $small, $size, $large, 2 * $size, $large / $small)])));

                return;
            }

            $readings[] = \sprintf('%.3f s for %d units, %.3f s for %d (%.2f)', $small, $size, $large, 2 * $size, $large / $small);
        }
    }

    /**
     * The best of four readings: a busy machine or a coverage driver slows
     * one reading down, rarely all four.
     *
     * @param \Closure(int): void $read
     */
    private static function bestTime(\Closure $read, int $size): float
    {
        $best = \INF;
        for ($run = 0; $run < 4; $run++) {
            $start = hrtime(true);
            $read($size);
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    }
}

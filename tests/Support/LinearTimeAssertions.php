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
 * Four times the text must cost about four times the time: a reading that
 * goes back over the rest of the pattern for each unit takes sixteen times
 * as long.
 */
trait LinearTimeAssertions
{
    /**
     * Reads $size units then four times as many, and asserts each read takes
     * less than the budget and the larger one less than $maxRatio times the
     * smaller.
     *
     * The clock is the CPU time of the process, not the wall clock: a
     * process the machine sets aside while it reads (a loaded laptop, a
     * shared runner, the other test processes) is not charged for the wait.
     * The smaller read is read twice, then the two sizes in turns, each
     * keeping its best reading, and the first turn under the line ends the
     * measure. A slowed reading only errs one way: the larger one slowed
     * fails a linear read for one turn, and the next turn reads it again;
     * the smaller one slowed would pass a quadratic read, and it never
     * stands alone: it has its twin from the first turn. A linear read so
     * costs two smaller reads and one larger.
     *
     * Four times the units, not two: a linear read takes four times as long
     * and a quadratic one sixteen, where twice the units gave two and four.
     * At half the size a quadratic part of the read stands half as high
     * against the linear one, and the wider gap still puts it past the line:
     * a read the old measure (twice $size, 3.2) caught at the boundary,
     * a quadratic part one and a half times the linear one, reads 9.1 here.
     *
     * Below a millisecond the clock says more than the read: the ratio of a
     * smaller read that short is not judged, only the budget.
     *
     * @param \Closure(int): void $read
     * @param float|null          $maxRatio the line for four times the units: 8 by default, 9.5 on a shared runner
     */
    private function assertLinearTime(\Closure $read, int $size, string $what, ?float $maxRatio = null): void
    {
        $shared = false !== getenv('CI') && '' !== getenv('CI');
        $budget = $shared ? 4.0 : 3.0;
        $maxRatio ??= $shared ? 9.5 : 8.0;

        $small = self::cpuTime($read, $size);
        $large = \INF;
        $readings = [];
        for ($turn = 0; $turn < 5; $turn++) {
            $small = min($small, self::cpuTime($read, $size));
            $large = min($large, self::cpuTime($read, 4 * $size));
            $readings[] = \sprintf('%.4f s for %d units, %.4f s for %d (%.2f)', $small, $size, $large, 4 * $size, $large / max($small, 1e-6));
            $this->assertLessThan($budget, $large, \sprintf('%s x %d: %.3f s.', $what, 4 * $size, $large));
            if ($small < 0.001) {
                return;
            }

            if ($large / $small < $maxRatio) {
                break;
            }
        }

        $this->assertLessThan($maxRatio, $large / $small, \sprintf('%s: four times the units took more than %.1f times as long on every turn: %s.', $what, $maxRatio, implode('; ', $readings)));
    }

    /**
     * The CPU time of one read, in seconds.
     *
     * @param \Closure(int): void $read
     */
    private static function cpuTime(\Closure $read, int $size): float
    {
        $start = self::cpuSeconds();
        $read($size);

        return self::cpuSeconds() - $start;
    }

    /**
     * The user and system time of the process: their sum is exact to the
     * microsecond on Linux and macOS, where each alone may be sampled.
     */
    private static function cpuSeconds(): float
    {
        $usage = getrusage();
        if (false === $usage) {
            throw new \RuntimeException('getrusage() failed: the CPU time of the process cannot be read.');
        }

        $seconds = 0.0;
        foreach (['ru_utime.tv_sec' => 1.0, 'ru_utime.tv_usec' => 1e-6, 'ru_stime.tv_sec' => 1.0, 'ru_stime.tv_usec' => 1e-6] as $field => $unit) {
            $value = $usage[$field] ?? 0;
            $seconds += (\is_int($value) ? $value : 0) * $unit;
        }

        return $seconds;
    }
}

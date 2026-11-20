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
 * A read that goes back over the rest of the pattern for each unit takes
 * sixteen times as long for four times the text. The benchmarks under
 * tests/Benchmark/Tally count that growth, in instructions that repeat from
 * run to run; here a large read stands under a budget, a tripwire on every
 * host the suite runs on.
 */
trait LinearTimeAssertions
{
    /**
     * Reads four times $size units once and asserts the read takes less than
     * the budget.
     *
     * The clock is the CPU time of the process, not the wall clock: a
     * process the machine sets aside while it reads (a loaded laptop, a
     * shared runner, the other test processes) is not charged for the wait.
     * The cycle collector is held while the read is timed: what a run of it
     * costs is the process's, not the read's.
     *
     * @param \Closure(int): void $read
     */
    private function assertLinearTime(\Closure $read, int $size, string $what): void
    {
        // PHP 8.1 on a shared runner reads 4,000 assertion bodies in some six
        // seconds of CPU time.
        $budget = false !== getenv('CI') && '' !== getenv('CI') ? 8.0 : 3.0;

        $seconds = CpuClock::time(static function () use ($read, $size): void {
            $read(4 * $size);
        });

        $this->assertLessThan($budget, $seconds, \sprintf('%s x %d: %.3f s.', $what, 4 * $size, $seconds));
    }
}

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

use PHPRegex\Parser\Text\Path;

/**
 * The CPU time of the process, for the tests that hold a computation to a
 * time budget: unlike the wall clock, it does not count the time the
 * process waits while the machine runs others, the other processes of a
 * parallel run among them. The time of the processes it starts is not
 * counted either: a budget on work done in a child process reads the wall
 * clock.
 */
final class CpuClock
{
    /**
     * The user and system time of the process, in nanoseconds, to subtract
     * as hrtime(true) is: their sum is exact to the microsecond on Linux and
     * macOS, where each alone may be sampled.
     */
    public static function nanoseconds(): int
    {
        // Windows accounts the CPU time of a process in quanta of 15.6 ms,
        // which say nothing of a read of a few milliseconds: the wall
        // clock is read there.
        if (Path::onWindows()) {
            return (int) hrtime(true); // an int on the 64-bit PHP the suite runs on
        }

        $usage = getrusage();
        if (false === $usage) {
            throw new \RuntimeException('getrusage() failed: the CPU time of the process cannot be read.');
        }

        $microseconds = 0;
        foreach (['ru_utime.tv_sec' => 1_000_000, 'ru_utime.tv_usec' => 1, 'ru_stime.tv_sec' => 1_000_000, 'ru_stime.tv_usec' => 1] as $field => $unit) {
            $value = $usage[$field] ?? 0;
            $microseconds += (\is_int($value) ? $value : 0) * $unit;
        }

        return $microseconds * 1000;
    }

    /**
     * The same, in seconds.
     */
    public static function seconds(): float
    {
        return self::nanoseconds() / 1e9;
    }
}

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

namespace PHPRegex\Tests\Benchmark\Support;

/**
 * The peak-memory marker of the running process, reset before a bench or a
 * test measures it. The engine function exists only from PHP 8.2, and the
 * benchmarks run on the PHP of the machine that measures: a host still on
 * 8.1 keeps its marker instead of resetting it. The function_exists() guard
 * is what lets the 8.1 analysis floor see the call without the function
 * behind it.
 */
final class PeakMemory
{
    public static function reset(): void
    {
        if (\function_exists('memory_reset_peak_usage')) {
            \memory_reset_peak_usage();
        }
    }
}

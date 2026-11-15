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

namespace PHPRegex\Tests\Benchmark\Tally\Support;

use PHPRegex\Tests\Benchmark\Support\BenchCases;

/**
 * The data cases of the timed benchmarks, as data sets of the counted ones:
 * both read the same patterns, named by their slug within the group.
 */
final class Cases
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function patterns(string $group, string ...$skipped): iterable
    {
        foreach (BenchCases::forGroup($group) as $case) {
            if (!\in_array($case->slug, $skipped, true)) {
                yield $case->slug => [$case->pattern];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function only(string $group, string ...$kept): iterable
    {
        foreach (BenchCases::forGroup($group) as $case) {
            if (\in_array($case->slug, $kept, true)) {
                yield $case->slug => [$case->pattern];
            }
        }
    }

    /**
     * The cases with the path their work must take, as Workloads::warmUp()
     * reads it.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function outcomes(string $group, string ...$skipped): iterable
    {
        foreach (BenchCases::forGroup($group) as $case) {
            if (!\in_array($case->slug, $skipped, true)) {
                yield $case->slug => [$group, $case->pattern, (string) $case->expect];
            }
        }
    }
}

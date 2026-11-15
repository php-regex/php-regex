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

/**
 * The sizes a growth benchmark reads: a base, twice and four times as much.
 * Tally compares each larger size with the base, so a method reads one
 * family of inputs and names the ladder that suits its cost.
 *
 * Counted instructions repeat from run to run, so the ladders stay far below
 * the sizes the timed tests read: four times the units counting more than
 * 4.4 times the instructions is a finding, where a timed ratio needs 8.
 */
trait Ladders
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function from25(): iterable
    {
        return self::ladder(25);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function from100(): iterable
    {
        return self::ladder(100);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function from250(): iterable
    {
        return self::ladder(250);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function from500(): iterable
    {
        return self::ladder(500);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function from1000(): iterable
    {
        return self::ladder(1000);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function from2000(): iterable
    {
        return self::ladder(2000);
    }

    /**
     * @return iterable<string, array{int}>
     */
    private static function ladder(int $base): iterable
    {
        foreach ([1, 2, 4] as $times) {
            yield 'n-'.($base * $times) => [$base * $times];
        }
    }
}

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
 * Families of patterns that grow with n, each measured at a few n so that
 * the ratio between two points shows how the work grows. Every ladder stops
 * below the default guards: a series measures work, not the exception path.
 *
 * @phpstan-type Point array{family: string, n: int, pattern: string}
 */
final class GrowthSeries
{
    /**
     * family => [ladder, pattern template], per group. Each ladder was
     * checked against the default limits; shrink it rather than raise one.
     */
    private const FAMILIES = [
        'automata' => [
            // a{n}: one NFA and one DFA state per repetition, yet the build
            // grows faster than n past 160; the ladder runs to 640 so that
            // its slope shows it, still under the NFA limit.
            'counted' => [[80, 160, 320, 640], 'counted'],
            // n distinct three-letter literals: the NFA grows with n, the minimal DFA stays small.
            'alternation' => [[10, 20, 40, 80], 'alternation'],
            // (?:a|a){n}b: two ambiguous paths per step, merged by determinization.
            'nested' => [[10, 20, 40, 80], 'nested'],
            // (a|b)*a(a|b){n}: the minimal DFA holds 2^(n+1) + 1 states (2,049 at n = 10).
            'subset-blowup' => [[4, 6, 8, 10], 'subsetBlowup'],
        ],
        'redos' => [
            // (?:w1|...|wn)* anchored: linear, the prover's product grows with n.
            'alternation-star' => [[5, 10, 20, 40], 'alternationStar'],
            // (?:a{n}b)+ anchored: proven linear to match, but the prover's
            // work grows about as n² past 80; the ladder runs to 320 so that
            // its slope shows it, every point still proven under the budget.
            'counted-under-plus' => [[40, 80, 160, 320], 'countedUnderPlus'],
            // (?:a|aa|...|a^n)* anchored: exponential, proven at every point.
            'overlap-star' => [[4, 6, 8, 10], 'overlapStar'],
            // .*a repeated n times, anchored: polynomial, the degree grows with n.
            'dotstar-chain' => [[5, 10, 20, 40], 'dotstarChain'],
        ],
    ];

    /**
     * Every point of the group's families, keyed "<family>@<n>"; none for a
     * group with no series.
     *
     * @return array<string, Point>
     */
    public static function forGroup(string $group): array
    {
        $points = [];
        foreach (self::FAMILIES[$group] ?? [] as $family => [$ladder, $builder]) {
            foreach ($ladder as $n) {
                $points[$family.'@'.$n] = ['family' => $family, 'n' => $n, 'pattern' => self::{$builder}($n)];
            }
        }

        return $points;
    }

    /**
     * The group's family names, in the order forGroup() lists them.
     *
     * @return list<string>
     */
    public static function families(string $group): array
    {
        return array_keys(self::FAMILIES[$group] ?? []);
    }

    /**
     * The parameter sets of a group's series: one per point, named
     * "<family>@<n>".
     *
     * @return array<string, array{key: string}>
     */
    public static function params(string $group): array
    {
        $params = [];
        foreach (array_keys(self::forGroup($group)) as $key) {
            $params[$key] = ['key' => $key];
        }

        return $params;
    }

    /**
     * The pattern of one point, for a parameter that carries only its key.
     *
     * @throws \OutOfBoundsException when the group has no such point
     */
    public static function pattern(string $group, string $key): string
    {
        return self::forGroup($group)[$key]['pattern'] ?? throw new \OutOfBoundsException(\sprintf('No growth point "%s" in group "%s".', $key, $group));
    }

    private static function counted(int $n): string
    {
        return '/a{'.$n.'}/';
    }

    private static function alternation(int $n): string
    {
        return '/(?:'.implode('|', self::words($n)).')/';
    }

    private static function nested(int $n): string
    {
        return '/(?:a|a){'.$n.'}b/';
    }

    private static function subsetBlowup(int $n): string
    {
        return '/(a|b)*a(a|b){'.$n.'}/';
    }

    private static function alternationStar(int $n): string
    {
        return '/^(?:'.implode('|', self::words($n)).')*$/';
    }

    private static function countedUnderPlus(int $n): string
    {
        return '/^(?:a{'.$n.'}b)+$/';
    }

    private static function overlapStar(int $n): string
    {
        return '/^(?:'.implode('|', array_map(static fn (int $length): string => str_repeat('a', $length), range(1, $n))).')*$/';
    }

    private static function dotstarChain(int $n): string
    {
        return '/^'.str_repeat('.*a', $n).'$/';
    }

    /**
     * n distinct three-letter words, the same ones on every call.
     *
     * @return list<string>
     */
    private static function words(int $n): array
    {
        $words = [];
        for ($i = 0; $i < $n; $i++) {
            $word = '';
            for ($k = $i, $j = 0; $j < 3; $j++, $k = intdiv($k, 26)) {
                $word .= \chr(97 + $k % 26);
            }
            $words[] = $word;
        }

        return $words;
    }
}

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

namespace PHPRegex\Tests\Unit\Hir;

use PHPRegex\Parser\Hir\CharSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The union of two sets is the set their ranges make together: sorted,
 * disjoint and never adjacent, whatever overlaps, touches or nests.
 */
final class CharSetUnionTest extends TestCase
{
    /**
     * @param list<array{int, int}> $left
     * @param list<array{int, int}> $right
     * @param list<array{int, int}> $expected
     */
    #[Test]
    #[DataProvider('provideUnions')]
    public function test_union_merges_overlapping_and_adjacent_ranges(array $left, array $right, array $expected): void
    {
        $union = CharSet::fromRanges($left)->union(CharSet::fromRanges($right));

        $this->assertSame($expected, $union->ranges);
        $this->assertSame($expected, CharSet::fromRanges($right)->union(CharSet::fromRanges($left))->ranges);
    }

    /**
     * @return iterable<string, array{left: list<array{int, int}>, right: list<array{int, int}>, expected: list<array{int, int}>}>
     */
    public static function provideUnions(): iterable
    {
        yield 'empty and empty' => ['left' => [], 'right' => [], 'expected' => []];
        yield 'empty and one' => ['left' => [], 'right' => [[3, 5]], 'expected' => [[3, 5]]];
        yield 'apart' => ['left' => [[0, 2]], 'right' => [[4, 6]], 'expected' => [[0, 2], [4, 6]]];
        yield 'adjacent' => ['left' => [[0, 2]], 'right' => [[3, 6]], 'expected' => [[0, 6]]];
        yield 'overlapping' => ['left' => [[0, 4]], 'right' => [[3, 6]], 'expected' => [[0, 6]]];
        yield 'nested' => ['left' => [[0, 10]], 'right' => [[3, 6]], 'expected' => [[0, 10]]];
        yield 'same start' => ['left' => [[3, 4]], 'right' => [[3, 9]], 'expected' => [[3, 9]]];
        yield 'one bridging two' => ['left' => [[0, 2], [8, 9]], 'right' => [[3, 7]], 'expected' => [[0, 9]]];
        yield 'interleaved' => ['left' => [[0, 0], [4, 4], [8, 8]], 'right' => [[2, 2], [6, 6], [10, 10]], 'expected' => [[0, 0], [2, 2], [4, 4], [6, 6], [8, 8], [10, 10]]];
        yield 'one swallowing many' => ['left' => [[1, 1], [3, 3], [5, 5], [20, 21]], 'right' => [[0, 6]], 'expected' => [[0, 6], [20, 21]]];
        yield 'the last code point' => ['left' => [[0x10FFFE, 0x10FFFF]], 'right' => [[0, 0x10FFFD]], 'expected' => [[0, 0x10FFFF]]];
    }

    /**
     * Seeded random sets, each union held to the ranges of both sorted
     * and merged afresh.
     */
    #[Test]
    public function test_union_is_the_set_of_both_ranges(): void
    {
        mt_srand(20261019);
        for ($round = 0; $round < 2000; $round++) {
            $left = CharSet::fromRanges(self::ranges());
            $right = CharSet::fromRanges(self::ranges());

            $this->assertSame(CharSet::fromRanges([...$left->ranges, ...$right->ranges])->ranges, $left->union($right)->ranges);
        }
    }

    /**
     * @return list<array{int, int}>
     */
    private static function ranges(): array
    {
        $ranges = [];
        for ($count = mt_rand(0, 8); $count > 0; $count--) {
            $from = mt_rand(0, 60);
            $ranges[] = [$from, $from + mt_rand(0, 6)];
        }

        return $ranges;
    }
}

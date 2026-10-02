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
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Scanning the engine encodes the alphabet as one subject: every code
 * point but the surrogates, in order, a block at a time. A class asked of
 * it comes back as exact runs of code points — across every width change
 * in UTF-8, around the surrogate hole, and at the last code points — or
 * the set would gain or lose a character at a block's edge.
 */
final class ClassSetSubjectTest extends TestCase
{
    /**
     * @param list<array{int, int}> $expected
     */
    #[Test]
    #[DataProvider('provideRanges')]
    public function test_a_class_spanning_the_boundaries_reads_exactly_its_code_points(int $from, int $to, array $expected): void
    {
        $set = ClassSetProvider::query(\sprintf('[\x{%X}-\x{%X}]', $from, $to), true, '');

        $this->assertInstanceOf(CharSet::class, $set);
        $this->assertSame($expected, $set->ranges);
    }

    /**
     * @return iterable<string, array{from: int, to: int, expected: list<array{int, int}>}>
     */
    public static function provideRanges(): iterable
    {
        yield 'ascii' => ['from' => 0x41, 'to' => 0x46, 'expected' => [[0x41, 0x46]]];
        yield 'one byte to two' => ['from' => 0x7E, 'to' => 0x82, 'expected' => [[0x7E, 0x82]]];
        yield 'two bytes to three' => ['from' => 0x7FE, 'to' => 0x802, 'expected' => [[0x7FE, 0x802]]];

        // The surrogates are not characters: the range is cut around the
        // hole, not carried over it.
        yield 'around the surrogates' => ['from' => 0xD7FE, 'to' => 0xE002, 'expected' => [[0xD7FE, 0xD7FF], [0xE000, 0xE002]]];
        yield 'three bytes to four' => ['from' => 0xFFFE, 'to' => 0x10002, 'expected' => [[0xFFFE, 0x10002]]];
        yield 'the last code points' => ['from' => 0x10FFFD, 'to' => 0x10FFFF, 'expected' => [[0x10FFFD, 0x10FFFF]]];
    }
}

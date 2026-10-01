<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Automata;

use PhpRegex\Automata\Alphabet\CharSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CharSetContainsTest extends TestCase
{
    #[Test]
    public function test_contains_single_codepoint(): void
    {
        $set = CharSet::fromCodePoint(65);

        $this->assertTrue($set->contains(65));
        $this->assertFalse($set->contains(64));
        $this->assertFalse($set->contains(66));
    }

    #[Test]
    public function test_contains_single_range(): void
    {
        $set = CharSet::fromRange(48, 57);

        $this->assertTrue($set->contains(48));
        $this->assertTrue($set->contains(57));
        $this->assertTrue($set->contains(52));
        $this->assertFalse($set->contains(47));
        $this->assertFalse($set->contains(58));
    }

    #[Test]
    public function test_contains_multiple_disjoint_ranges(): void
    {
        $set = CharSet::fromRanges([[48, 57], [65, 90], [97, 122]]);

        $this->assertTrue($set->contains(48));
        $this->assertTrue($set->contains(57));
        $this->assertTrue($set->contains(65));
        $this->assertTrue($set->contains(90));
        $this->assertTrue($set->contains(97));
        $this->assertTrue($set->contains(122));
        $this->assertFalse($set->contains(0));
        $this->assertFalse($set->contains(58));
        $this->assertFalse($set->contains(91));
        $this->assertFalse($set->contains(96));
        $this->assertFalse($set->contains(123));
        $this->assertFalse($set->contains(255));
    }

    #[Test]
    public function test_contains_empty_set(): void
    {
        $set = CharSet::empty();

        $this->assertFalse($set->contains(0));
        $this->assertFalse($set->contains(127));
        $this->assertFalse($set->contains(255));
    }

    #[Test]
    public function test_contains_full_set(): void
    {
        $set = CharSet::full();

        $this->assertTrue($set->contains(0));
        $this->assertTrue($set->contains(127));
        $this->assertTrue($set->contains(255));
    }

    #[Test]
    public function test_contains_boundary_of_adjacent_ranges(): void
    {
        $set = CharSet::fromRanges([[10, 20], [30, 40], [50, 60]]);

        $this->assertTrue($set->contains(10));
        $this->assertTrue($set->contains(20));
        $this->assertFalse($set->contains(21));
        $this->assertFalse($set->contains(29));
        $this->assertTrue($set->contains(30));
        $this->assertTrue($set->contains(40));
        $this->assertFalse($set->contains(41));
        $this->assertFalse($set->contains(49));
        $this->assertTrue($set->contains(50));
        $this->assertTrue($set->contains(60));
        $this->assertFalse($set->contains(61));
    }

    #[Test]
    public function test_contains_after_complement(): void
    {
        $set = CharSet::fromRange(65, 90)->complement();

        $this->assertFalse($set->contains(65));
        $this->assertFalse($set->contains(90));
        $this->assertTrue($set->contains(0));
        $this->assertTrue($set->contains(64));
        $this->assertTrue($set->contains(91));
        $this->assertTrue($set->contains(255));
    }

    #[Test]
    public function test_contains_after_union(): void
    {
        $letters = CharSet::fromRange(65, 90);
        $digits = CharSet::fromRange(48, 57);
        $set = $letters->union($digits);

        $this->assertTrue($set->contains(65));
        $this->assertTrue($set->contains(48));
        $this->assertFalse($set->contains(58));
    }

    #[Test]
    public function test_contains_after_intersection(): void
    {
        $wide = CharSet::fromRange(40, 80);
        $narrow = CharSet::fromRange(60, 100);
        $set = $wide->intersect($narrow);

        $this->assertTrue($set->contains(60));
        $this->assertTrue($set->contains(80));
        $this->assertFalse($set->contains(59));
        $this->assertFalse($set->contains(81));
    }

    #[Test]
    public function test_contains_after_subtraction(): void
    {
        $full = CharSet::fromRange(0, 255);
        $exclude = CharSet::fromRange(65, 90);
        $set = $full->subtract($exclude);

        $this->assertTrue($set->contains(0));
        $this->assertTrue($set->contains(64));
        $this->assertFalse($set->contains(65));
        $this->assertFalse($set->contains(90));
        $this->assertTrue($set->contains(91));
        $this->assertTrue($set->contains(255));
    }

    #[Test]
    #[DataProvider('provideUnicodeContainsCases')]
    public function test_contains_unicode_codepoints(int $codePoint, bool $expected): void
    {
        $set = CharSet::fromRange(0x2600, 0x26FF, CharSet::UNICODE_MAX_CODEPOINT);

        $this->assertSame($expected, $set->contains($codePoint));
    }

    /**
     * @return \Generator<string, array{int, bool}>
     */
    public static function provideUnicodeContainsCases(): \Generator
    {
        yield 'snowman in range' => [0x2603, true];
        yield 'start of range' => [0x2600, true];
        yield 'end of range' => [0x26FF, true];
        yield 'before range' => [0x25FF, false];
        yield 'after range' => [0x2700, false];
        yield 'ascii' => [65, false];
    }

    #[Test]
    public function test_contains_many_ranges_binary_search(): void
    {
        $ranges = [];
        for ($i = 0; $i < 50; $i++) {
            $start = $i * 10;
            $end = $start + 4;
            $ranges[] = [$start, $end];
        }

        $set = CharSet::fromRanges($ranges, 500);

        for ($i = 0; $i < 50; $i++) {
            $start = $i * 10;
            $this->assertTrue($set->contains($start), "Should contain {$start}");
            $this->assertTrue($set->contains($start + 2), 'Should contain '.($start + 2));
            $this->assertTrue($set->contains($start + 4), 'Should contain '.($start + 4));
            $this->assertFalse($set->contains($start + 5), 'Should not contain '.($start + 5));
            if ($i > 0) {
                $this->assertFalse($set->contains($start - 1), 'Should not contain '.($start - 1));
            }
        }
    }
}

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

namespace RegexParser\Tests\Unit\ReDoS;

use PHPUnit\Framework\TestCase;
use RegexParser\Analysis\CharSet;

final class CharSetTest extends TestCase
{
    public function test_factory_methods_and_flags(): void
    {
        $empty = CharSet::empty();
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->isUnknown());

        $unknown = CharSet::unknown();
        $this->assertTrue($unknown->isUnknown());
        $this->assertFalse($unknown->isEmpty());

        $full = CharSet::full();
        $this->assertFalse($full->isEmpty());
    }

    public function test_intersect_keeps_what_both_sets_hold(): void
    {
        // Ranges that touch on one character share it.
        $shared = CharSet::fromRange(97, 99)->intersect(CharSet::fromRange(99, 101));
        $this->assertSame('c', $shared->sampleChar());
        $this->assertTrue($shared->intersects(CharSet::fromChar('c')));
        $this->assertFalse($shared->intersects(CharSet::fromChar('b')));
        $this->assertFalse($shared->intersects(CharSet::fromChar('d')));
        $this->assertSame('c', CharSet::fromRange(99, 101)->intersect(CharSet::fromRange(97, 99))->sampleChar());

        // Ranges apart share nothing, in either order.
        $this->assertTrue(CharSet::fromRange(0, 5)->intersect(CharSet::fromRange(10, 20))->isEmpty());
        $this->assertTrue(CharSet::fromRange(10, 20)->intersect(CharSet::fromRange(0, 5))->isEmpty());

        $this->assertTrue(CharSet::fromRange(0, 5)->intersect(CharSet::unknown())->isUnknown());
    }

    public function test_union_merges_ranges_and_handles_unknown(): void
    {
        $rangeA = CharSet::fromRange(0, 2);
        $rangeB = CharSet::fromRange(3, 5);
        $merged = $rangeA->union($rangeB);

        $this->assertFalse($merged->isEmpty());
        $this->assertTrue($merged->intersects(CharSet::fromRange(4, 4)));

        $unknown = CharSet::unknown()->union($rangeA);
        $this->assertTrue($unknown->isUnknown());
    }

    public function test_complement_and_intersects(): void
    {
        $digits = CharSet::fromRange(\ord('0'), \ord('9'));
        $complement = $digits->complement();

        $this->assertTrue($complement->intersects(CharSet::fromChar('A')));
        $this->assertFalse($complement->intersects($digits));
    }

    public function test_sample_char_with_empty_set(): void
    {
        $empty = CharSet::empty();
        $char = $empty->sampleChar();

        $this->assertNull($char);
    }

    public function test_sample_char_with_unknown_set(): void
    {
        $unknown = CharSet::unknown();
        $char = $unknown->sampleChar();

        $this->assertNull($char);
    }

    public function test_sample_char_with_single_char(): void
    {
        $set = CharSet::fromChar('a');
        $char = $set->sampleChar();

        $this->assertSame('a', $char);
    }

    public function test_sample_char_with_range(): void
    {
        $set = CharSet::fromRange(0, 10);
        $char = $set->sampleChar();

        $this->assertSame(\chr(0), $char);
    }

    public function test_sample_char_with_merged_ranges(): void
    {
        $rangeA = CharSet::fromRange(0, 5);
        $rangeB = CharSet::fromRange(10, 15);
        $merged = $rangeA->union($rangeB);

        $char = $merged->sampleChar();
        $this->assertSame(\chr(0), $char);
    }

    public function test_from_char_with_empty_string(): void
    {
        $set = CharSet::fromChar('');
        $this->assertTrue($set->isEmpty());
    }

    public function test_from_char_with_single_byte(): void
    {
        $set = CharSet::fromChar('a');
        $this->assertFalse($set->isEmpty());
    }

    public function test_union_handles_adjacent_ranges(): void
    {
        $rangeA = CharSet::fromRange(0, 2);
        $rangeB = CharSet::fromRange(3, 5);
        $merged = $rangeA->union($rangeB);

        $this->assertFalse($merged->isEmpty());
        $this->assertTrue($merged->intersects(CharSet::fromRange(4, 4)));
    }

    public function test_union_overlapping_ranges(): void
    {
        $rangeA = CharSet::fromRange(0, 5);
        $rangeB = CharSet::fromRange(3, 10);
        $merged = $rangeA->union($rangeB);

        $this->assertTrue($merged->intersects(CharSet::fromRange(4, 4)));
    }

    public function test_intersects_with_overlapping_ranges(): void
    {
        $rangeA = CharSet::fromRange(0, 5);
        $rangeB = CharSet::fromRange(3, 10);

        $this->assertTrue($rangeA->intersects($rangeB));
        $this->assertTrue($rangeB->intersects($rangeA));
    }

    public function test_intersects_with_non_overlapping_ranges(): void
    {
        $rangeA = CharSet::fromRange(0, 5);
        $rangeB = CharSet::fromRange(10, 15);

        $this->assertFalse($rangeA->intersects($rangeB));
        $this->assertFalse($rangeB->intersects($rangeA));
    }

    public function test_complement_of_empty_set_is_full(): void
    {
        $empty = CharSet::empty();
        $full = $empty->complement();

        $this->assertTrue($full->intersects(CharSet::fromChar('a')));
    }

    public function test_complement_of_unknown_is_unknown(): void
    {
        $unknown = CharSet::unknown();
        $complement = $unknown->complement();

        $this->assertTrue($complement->isUnknown());
    }

    public function test_complement_with_multiple_ranges(): void
    {
        $set = CharSet::fromRange(10, 20);
        $complement = $set->complement();

        $this->assertTrue($complement->intersects(CharSet::fromChar('a')));
        $this->assertFalse($complement->intersects(CharSet::fromChar(\chr(15))));
    }

    public function test_from_range_clips_to_ascii_max(): void
    {
        $set = CharSet::fromRange(0, 200);

        $this->assertTrue($set->intersects(CharSet::fromChar(\chr(127))));
    }

    public function test_from_range_with_negative_start(): void
    {
        $set = CharSet::fromRange(-10, 10);

        $this->assertTrue($set->intersects(CharSet::fromChar(\chr(0))));
    }

    public function test_from_range_non_ascii_returns_unknown(): void
    {
        // Range starting above ASCII max should return unknown
        $set = CharSet::fromRange(0x80, 0x85);
        $this->assertTrue($set->isUnknown(), 'Non-ASCII range should return unknown');

        // Range ending above ASCII max should return unknown
        $set = CharSet::fromRange(0, 200);
        $this->assertTrue($set->isUnknown(), 'Range with end > ASCII_MAX should return unknown');
    }
}

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

namespace PHPRegex\Tests\Unit\ReDoS;

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPUnit\Framework\TestCase;

final class CharSetTest extends TestCase
{
    public function test_factory_methods_and_flags(): void
    {
        $empty = ByteCharSet::empty();
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->isUnknown());

        $unknown = ByteCharSet::unknown();
        $this->assertTrue($unknown->isUnknown());
        $this->assertFalse($unknown->isEmpty());

        $full = ByteCharSet::full();
        $this->assertFalse($full->isEmpty());
    }

    public function test_intersect_keeps_what_both_sets_hold(): void
    {
        // Ranges that touch on one character share it.
        $shared = ByteCharSet::fromRange(97, 99)->intersect(ByteCharSet::fromRange(99, 101));
        $this->assertSame('c', $shared->sampleChar());
        $this->assertTrue($shared->intersects(ByteCharSet::fromChar('c')));
        $this->assertFalse($shared->intersects(ByteCharSet::fromChar('b')));
        $this->assertFalse($shared->intersects(ByteCharSet::fromChar('d')));
        $this->assertSame('c', ByteCharSet::fromRange(99, 101)->intersect(ByteCharSet::fromRange(97, 99))->sampleChar());

        // Ranges apart share nothing, in either order.
        $this->assertTrue(ByteCharSet::fromRange(0, 5)->intersect(ByteCharSet::fromRange(10, 20))->isEmpty());
        $this->assertTrue(ByteCharSet::fromRange(10, 20)->intersect(ByteCharSet::fromRange(0, 5))->isEmpty());

        $this->assertTrue(ByteCharSet::fromRange(0, 5)->intersect(ByteCharSet::unknown())->isUnknown());
    }

    public function test_union_merges_ranges_and_handles_unknown(): void
    {
        $rangeA = ByteCharSet::fromRange(0, 2);
        $rangeB = ByteCharSet::fromRange(3, 5);
        $merged = $rangeA->union($rangeB);

        $this->assertFalse($merged->isEmpty());
        $this->assertTrue($merged->intersects(ByteCharSet::fromRange(4, 4)));

        $unknown = ByteCharSet::unknown()->union($rangeA);
        $this->assertTrue($unknown->isUnknown());
    }

    public function test_complement_and_intersects(): void
    {
        $digits = ByteCharSet::fromRange(\ord('0'), \ord('9'));
        $complement = $digits->complement();

        $this->assertTrue($complement->intersects(ByteCharSet::fromChar('A')));
        $this->assertFalse($complement->intersects($digits));
    }

    public function test_sample_char_with_empty_set(): void
    {
        $empty = ByteCharSet::empty();
        $char = $empty->sampleChar();

        $this->assertNull($char);
    }

    public function test_sample_char_with_unknown_set(): void
    {
        $unknown = ByteCharSet::unknown();
        $char = $unknown->sampleChar();

        $this->assertNull($char);
    }

    public function test_sample_char_with_single_char(): void
    {
        $set = ByteCharSet::fromChar('a');
        $char = $set->sampleChar();

        $this->assertSame('a', $char);
    }

    public function test_sample_char_with_range(): void
    {
        $set = ByteCharSet::fromRange(0, 10);
        $char = $set->sampleChar();

        $this->assertSame(\chr(0), $char);
    }

    public function test_sample_char_with_merged_ranges(): void
    {
        $rangeA = ByteCharSet::fromRange(0, 5);
        $rangeB = ByteCharSet::fromRange(10, 15);
        $merged = $rangeA->union($rangeB);

        $char = $merged->sampleChar();
        $this->assertSame(\chr(0), $char);
    }

    public function test_from_char_with_empty_string(): void
    {
        $set = ByteCharSet::fromChar('');
        $this->assertTrue($set->isEmpty());
    }

    public function test_from_char_with_single_byte(): void
    {
        $set = ByteCharSet::fromChar('a');
        $this->assertFalse($set->isEmpty());
    }

    public function test_union_handles_adjacent_ranges(): void
    {
        $rangeA = ByteCharSet::fromRange(0, 2);
        $rangeB = ByteCharSet::fromRange(3, 5);
        $merged = $rangeA->union($rangeB);

        $this->assertFalse($merged->isEmpty());
        $this->assertTrue($merged->intersects(ByteCharSet::fromRange(4, 4)));
    }

    public function test_union_overlapping_ranges(): void
    {
        $rangeA = ByteCharSet::fromRange(0, 5);
        $rangeB = ByteCharSet::fromRange(3, 10);
        $merged = $rangeA->union($rangeB);

        $this->assertTrue($merged->intersects(ByteCharSet::fromRange(4, 4)));
    }

    public function test_intersects_with_overlapping_ranges(): void
    {
        $rangeA = ByteCharSet::fromRange(0, 5);
        $rangeB = ByteCharSet::fromRange(3, 10);

        $this->assertTrue($rangeA->intersects($rangeB));
        $this->assertTrue($rangeB->intersects($rangeA));
    }

    public function test_intersects_with_non_overlapping_ranges(): void
    {
        $rangeA = ByteCharSet::fromRange(0, 5);
        $rangeB = ByteCharSet::fromRange(10, 15);

        $this->assertFalse($rangeA->intersects($rangeB));
        $this->assertFalse($rangeB->intersects($rangeA));
    }

    public function test_complement_of_empty_set_is_full(): void
    {
        $empty = ByteCharSet::empty();
        $full = $empty->complement();

        $this->assertTrue($full->intersects(ByteCharSet::fromChar('a')));
    }

    public function test_complement_of_unknown_is_unknown(): void
    {
        $unknown = ByteCharSet::unknown();
        $complement = $unknown->complement();

        $this->assertTrue($complement->isUnknown());
    }

    public function test_complement_with_multiple_ranges(): void
    {
        $set = ByteCharSet::fromRange(10, 20);
        $complement = $set->complement();

        $this->assertTrue($complement->intersects(ByteCharSet::fromChar('a')));
        $this->assertFalse($complement->intersects(ByteCharSet::fromChar(\chr(15))));
    }

    public function test_from_range_clips_to_ascii_max(): void
    {
        $set = ByteCharSet::fromRange(0, 200);

        $this->assertTrue($set->intersects(ByteCharSet::fromChar(\chr(127))));
    }

    public function test_from_range_with_negative_start(): void
    {
        $set = ByteCharSet::fromRange(-10, 10);

        $this->assertTrue($set->intersects(ByteCharSet::fromChar(\chr(0))));
    }

    public function test_from_range_non_ascii_returns_unknown(): void
    {
        // Range starting above ASCII max should return unknown
        $set = ByteCharSet::fromRange(0x80, 0x85);
        $this->assertTrue($set->isUnknown(), 'Non-ASCII range should return unknown');

        // Range ending above ASCII max should return unknown
        $set = ByteCharSet::fromRange(0, 200);
        $this->assertTrue($set->isUnknown(), 'Range with end > ASCII_MAX should return unknown');
    }
}

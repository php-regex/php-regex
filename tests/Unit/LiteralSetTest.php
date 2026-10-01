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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Analysis\LiteralSet;
use PHPUnit\Framework\TestCase;

final class LiteralSetTest extends TestCase
{
    private const TEST_MAX_SET_SIZE = 100;
    private const TEST_MAX_STRING_LENGTH = 1000;

    public function test_empty(): void
    {
        $set = LiteralSet::empty();

        $this->assertTrue($set->isVoid());
        $this->assertSame([], $set->prefixes);
        $this->assertSame([], $set->suffixes);
        $this->assertFalse($set->complete);
    }

    public function test_from_string_with_short_literal(): void
    {
        $set = LiteralSet::fromString('abc');

        $this->assertFalse($set->isVoid());
        $this->assertSame(['abc'], $set->prefixes);
        $this->assertSame(['abc'], $set->suffixes);
        $this->assertTrue($set->complete);
    }

    public function test_from_string_with_empty_literal(): void
    {
        $set = LiteralSet::fromString('');

        $this->assertFalse($set->isVoid());
        $this->assertSame([''], $set->prefixes);
        $this->assertSame([''], $set->suffixes);
        $this->assertTrue($set->complete);
    }

    public function test_from_string_with_too_long_literal_returns_empty(): void
    {
        $longLiteral = str_repeat('a', self::TEST_MAX_STRING_LENGTH + 1);
        $set = LiteralSet::fromString($longLiteral);

        $this->assertTrue($set->isVoid());
        $this->assertSame([], $set->prefixes);
        $this->assertSame([], $set->suffixes);
    }

    public function test_construct_with_arrays_exceeding_max_set_size(): void
    {
        $largePrefixes = array_map(strval(...), range(1, self::TEST_MAX_SET_SIZE + 10));
        $largeSuffixes = array_map(strval(...), range(1, self::TEST_MAX_SET_SIZE + 5));

        $set = new LiteralSet($largePrefixes, $largeSuffixes, true);

        // A cut list would leave out strings a match can start with: the
        // lists are dropped, and the set no longer claims to be complete.
        $this->assertSame([], $set->prefixes);
        $this->assertSame([], $set->suffixes);
        $this->assertFalse($set->complete);
    }

    public function test_unite_with_identical_sets_returns_same_instance(): void
    {
        $prefixes = ['a', 'b'];
        $suffixes = ['x', 'y'];
        $set = new LiteralSet($prefixes, $suffixes, true);

        $result = $set->unite($set);

        $this->assertSame($set, $result);
    }

    public function test_unite_with_different_sets_merges_prefixes_and_suffixes(): void
    {
        $set1 = new LiteralSet(['a'], ['x']);
        $set2 = new LiteralSet(['b'], ['y']);

        $result = $set1->unite($set2);
        $this->assertSame(['a', 'b'], $result->prefixes);
        $this->assertSame(['x', 'y'], $result->suffixes);
        $this->assertFalse($result->complete);
    }

    public function test_concat_generates_cross_product(): void
    {
        $set1 = new LiteralSet(['a', 'b'], ['ab', 'cd'], true);
        $set2 = new LiteralSet(['x', 'y'], ['xyz'], true);

        $result = $set1->concat($set2);

        $this->assertSame(['ax', 'ay', 'bx', 'by'], $result->prefixes);
        $this->assertSame(['abxyz', 'cdxyz'], $result->suffixes);
        $this->assertTrue($result->complete);
    }

    public function test_concat_with_incomplete_set_keeps_incomplete(): void
    {
        $set1 = new LiteralSet(['a'], [], false);
        $set2 = new LiteralSet(['b'], [], false);

        $result = $set1->concat($set2);
        $this->assertSame(['a'], $result->prefixes);
        $this->assertEmpty($result->suffixes);
        $this->assertFalse($result->complete);
    }

    public function test_cross_product_with_very_long_strings_skips_combinations(): void
    {
        $set1 = new LiteralSet(['a'], [], true);
        $longString = str_repeat('x', self::TEST_MAX_STRING_LENGTH + 1);
        $set2 = new LiteralSet([$longString], ['xyz'], true);

        $result = $set1->concat($set2);

        // The product does not fit, but every match still starts with "a".
        $this->assertSame(['a'], $result->prefixes);
        $this->assertSame(['xyz'], $result->suffixes);
        $this->assertFalse($result->complete);
    }

    public function test_cross_product_stops_at_max_set_size(): void
    {
        $set1 = new LiteralSet(array_map(strval(...), range('a', 'z')), [], true);
        $set2 = new LiteralSet(array_map(strval(...), range('0', '99')), [], true);

        $result = $set1->concat($set2);

        // 2600 combinations do not fit: the letters alone still hold.
        $this->assertCount(26, $result->prefixes);
    }

    public function test_unite_limits_size_and_deduplicates(): void
    {
        $set1 = new LiteralSet(array_map(strval(...), range(0, 99)), [], true);
        $set2 = new LiteralSet(array_map(strval(...), range(100, 219)), [], true); // past the limit: dropped in the constructor

        $result = $set1->unite($set2);

        // The second list was dropped: nothing is known of the union.
        $this->assertSame([], $result->prefixes);
        $this->assertFalse($result->complete);
        $this->assertTrue($result->isVoid());
    }

    public function test_get_longest_prefix(): void
    {
        $set = new LiteralSet(['a', 'ab', 'abc'], []);

        $this->assertSame('abc', $set->getLongestPrefix());
    }

    public function test_get_longest_prefix_with_empty_returns_null(): void
    {
        $set = new LiteralSet([], []);

        $this->assertNull($set->getLongestPrefix());
    }

    public function test_get_longest_suffix(): void
    {
        $set = new LiteralSet([], ['x', 'xy', 'xyz']);

        $this->assertSame('xyz', $set->getLongestSuffix());
    }

    public function test_get_longest_suffix_with_empty_returns_null(): void
    {
        $set = new LiteralSet([], []);

        $this->assertNull($set->getLongestSuffix());
    }
}

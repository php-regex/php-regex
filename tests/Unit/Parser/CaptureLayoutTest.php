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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Internal\CaptureKey;
use PHPRegex\Parser\Internal\CaptureLayout;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The keys preg_match() and preg_match_all() write, as facts both type
 * renderings read (CaptureShape's PHPStan strings and the Psalm plugin's
 * atomics): each key in the order PHP writes it, whether some match leaves
 * it out, the groups whose value it may hold, and whether it may hold the
 * unset value ('' or null) or a mark name. The layout reads the merged view
 * of a split shape; a case has its own layout.
 *
 * Rows are written one string per key: "key: groups", "key?" for a key some
 * matches leave out, the groups by number (0 the whole match, "-" none),
 * then "+unset" when the key may hold what an unset group reads, "+marks"
 * when it may hold a mark name, and "always" when the value is set on every
 * match (an offset pair then starts at 0, not -1).
 */
final class CaptureLayoutTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideMatchLayouts')]
    public function test_layout_of_match_lists_the_keys_preg_match_writes(string $pattern, bool $unmatchedAsNull, array $expected): void
    {
        $this->assertSame($expected, self::describe(CaptureLayout::ofMatch(self::shape($pattern), $unmatchedAsNull)));
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideMatchAllLayouts')]
    public function test_layout_of_match_all_lists_the_keys_preg_match_all_writes(string $pattern, array $expected): void
    {
        $this->assertSame($expected, self::describe(CaptureLayout::ofMatchAll(self::shape($pattern))));
    }

    #[Test]
    public function test_layout_keys_are_ints_for_numbers_and_strings_for_names(): void
    {
        $layout = CaptureLayout::ofMatch(self::shape('/(?<n>a)(b)(*MARK:m)/'));

        $this->assertSame([0, 'n', 1, 2, 'MARK'], array_map(static fn (CaptureKey $key): int|string => $key->key, $layout->keys));
    }

    /**
     * The marks' own key holds a mark name only; a group named MARK shares
     * its key with the marks, and so does one no match sets, whose key reads
     * the unset value.
     */
    #[Test]
    public function test_layout_tells_the_marks_own_key_from_a_group_named_mark(): void
    {
        $holdsMarksOnly = static fn (CaptureLayout $layout): array => array_map(static fn (CaptureKey $key): bool => $key->holdsMarksOnly(), $layout->keys);

        // preg_match('/(*MARK:m)a/', 'a', $m) -> ['a', 'MARK' => 'm']
        $this->assertSame([false, true], $holdsMarksOnly(CaptureLayout::ofMatch(self::shape('/(*MARK:m)a/'))));
        $this->assertSame([false, true], $holdsMarksOnly(CaptureLayout::ofMatchAll(self::shape('/(*MARK:m)a/'))));
        // preg_match('/(?<MARK>a)(*MARK:x)b/', 'ab', $m) -> ['ab', 'MARK' => 'x', 'a']
        $this->assertSame([false, false, false], $holdsMarksOnly(CaptureLayout::ofMatch(self::shape('/(?<MARK>a)(*MARK:x)b/'))));
        // preg_match('/(?!(?<MARK>a))b(*MARK:x)/', 'b', $m, PREG_UNMATCHED_AS_NULL) -> ['b', 'MARK' => 'x', null]
        $this->assertSame([false, false, false], $holdsMarksOnly(CaptureLayout::ofMatch(self::shape('/(?!(?<MARK>a))b(*MARK:x)/'), true)));
    }

    /**
     * @param list<string> $values
     */
    #[Test]
    #[DataProvider('provideLiteralValues')]
    public function test_layout_writes_values_as_literals_when_few_and_legible(array $values, bool $expected): void
    {
        $this->assertSame($expected, CaptureLayout::readsAsLiterals($values));
    }

    /**
     * @return iterable<string, array{values: list<string>, expected: bool}>
     */
    public static function provideLiteralValues(): iterable
    {
        yield 'none' => ['values' => [], 'expected' => false];
        yield 'one' => ['values' => ['a'], 'expected' => true];
        yield 'empty string' => ['values' => [''], 'expected' => true];
        yield 'utf-8' => ['values' => ['é'], 'expected' => true];
        yield 'sixteen' => ['values' => array_map(strval(...), range(1, 16)), 'expected' => true];
        yield 'seventeen' => ['values' => array_map(strval(...), range(1, 17)), 'expected' => false];
        yield 'invalid utf-8' => ['values' => ['a', "\xFF"], 'expected' => false];
        yield 'nul byte' => ['values' => ["a\x00"], 'expected' => false];
        yield 'newline' => ['values' => ["\n"], 'expected' => false];
        yield 'delete' => ['values' => ["\x7F"], 'expected' => false];
        yield 'space' => ['values' => [' '], 'expected' => true];
    }

    #[Test]
    public function test_layout_points_at_the_shapes_own_group_records(): void
    {
        $shape = self::shape('/(?<n>a)(b)?/');
        $layout = CaptureLayout::ofMatch($shape);

        $this->assertSame($shape->whole, $layout->keys[0]->groups[0]);
        $this->assertSame($shape->groups[1], $layout->keys[1]->groups[0]);
        $this->assertSame($shape->groups[1], $layout->keys[2]->groups[0]);
        $this->assertSame($shape->groups[2], $layout->keys[3]->groups[0]);
    }

    /**
     * The groups of a key are a list, whatever groups no match sets were
     * left out of it.
     */
    #[Test]
    public function test_layout_lists_the_groups_of_a_key_from_zero(): void
    {
        // preg_match('/(?J)(?!(?<n>b))(?<n>a)/', 'a', $m) -> ['a', 'n' => 'a', '', 'a'] (PHP 8.4.26)
        $shape = self::shape('/(?J)(?!(?<n>b))(?<n>a)/');
        $layout = CaptureLayout::ofMatch($shape);

        $this->assertSame([0, 'n', 1, 2], array_map(static fn (CaptureKey $key): int|string => $key->key, $layout->keys));
        // Group 1 is no match's: the name holds group 2's value, at index 0.
        $this->assertSame([$shape->groups[2]], $layout->keys[1]->groups);
        $this->assertSame([], $layout->keys[2]->groups);
    }

    #[Test]
    public function test_layout_of_a_split_shape_reads_the_merged_view(): void
    {
        $shape = self::shape('/(a)|(b)/');
        $this->assertCount(2, $shape->cases);
        $merged = new CaptureShape($shape->whole, $shape->groups, $shape->marks);

        $this->assertSame(self::describe(CaptureLayout::ofMatch($merged)), self::describe(CaptureLayout::ofMatch($shape)));
        // preg_match('/(a)|(b)/', 'a', $m) -> ['a', 'a']; on 'b' -> ['b', '', 'b'] (PHP 8.4.26)
        $this->assertSame(['0: 0 always', '1: 1 always'], self::describe(CaptureLayout::ofMatch($shape->cases[0])));
        $this->assertSame(['0: 0 always', '1: - +unset', '2: 2 always'], self::describe(CaptureLayout::ofMatch($shape->cases[1])));
    }

    /**
     * @return iterable<string, array{pattern: string, unmatchedAsNull: bool, expected: list<string>}>
     */
    public static function provideMatchLayouts(): iterable
    {
        // preg_match('/(a)(z)?/', 'a', $m) -> ['a', 'a']; with PREG_UNMATCHED_AS_NULL -> ['a', 'a', null] (PHP 8.4.26)
        yield 'trailing optional group is left out' => ['pattern' => '/(a)(z)?/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', '1: 1 always', '2?: 2']];
        yield 'trailing optional group, as null' => ['pattern' => '/(a)(z)?/', 'unmatchedAsNull' => true, 'expected' => ['0: 0 always', '1: 1 always', '2: 2 +unset']];
        // preg_match('/(z)?(a)/', 'a', $m) -> ['a', '', 'a']
        yield 'unset group before a set one reads empty' => ['pattern' => '/(z)?(a)/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', '1: 1 +unset', '2: 2 always']];
        // preg_match('/(?!(b))(a)/', 'a', $m) -> ['a', '', 'a']
        yield 'group no match sets, then a set one' => ['pattern' => '/(?!(b))(a)/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', '1: - +unset', '2: 2 always']];
        // preg_match('/(?!(b))a/', 'a', $m) -> ['a']; with PREG_UNMATCHED_AS_NULL -> ['a', null]
        yield 'group no match sets, last' => ['pattern' => '/(?!(b))a/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always']];
        yield 'group no match sets, last, as null' => ['pattern' => '/(?!(b))a/', 'unmatchedAsNull' => true, 'expected' => ['0: 0 always', '1: - +unset']];
        yield 'name before its number' => ['pattern' => '/(?<n>a)(b)/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'n: 1 always', '1: 1 always', '2: 2 always']];
        yield 'name of a trailing optional group' => ['pattern' => '/(?<y>\d{4})(?:-(?<d>\d\d))?/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'y: 1 always', '1: 1 always', 'd?: 2', '2?: 2']];
        // preg_match('/(?J)(?<n>a)(?<n>z)?(c)/', 'ac', $m) -> ['ac', 'n' => 'a', 'a', '', 'c']: the name holds a set group
        yield 'shared name, one group always set' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'n: 1,2 always', '1: 1 always', '2: 2 +unset', '3: 3 always']];
        // preg_match('/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'bx', $m) -> ['bx', 'n' => 'b', '', 'b', 'x']
        yield 'shared name, no group always set' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'n: 1,2 +unset', '1: 1 +unset', '2: 2 +unset', '3: 3 always']];
        // preg_match('/(*MARK:m)a/', 'a', $m) -> ['a', 'MARK' => 'm']
        yield 'mark verb' => ['pattern' => '/(*MARK:m)a/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'MARK?: - +marks']];
        // preg_match('/(?<x>a)(*MARK:m)b/', 'ab', $m) -> ['ab', 'x' => 'a', 'a', 'MARK' => 'm']
        yield 'named group next to a mark verb' => ['pattern' => '/(?<x>a)(*MARK:m)b/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'x: 1 always', '1: 1 always', 'MARK?: - +marks']];
        // preg_match('/(?<MARK>a)(*MARK:x)b/', 'ab', $m) -> ['ab', 'MARK' => 'x', 'a']: the verb writes over the group's key
        yield 'group named MARK beside a verb' => ['pattern' => '/(?<MARK>a)(*MARK:x)b/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'MARK: 1 +marks always', '1: 1 always']];
        yield 'group named MARK, no verb' => ['pattern' => '/(?<MARK>a)/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'MARK: 1 always', '1: 1 always']];
        yield 'branch reset groups share a key' => ['pattern' => '/(?|(a)|(b))/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', '1: 1 always']];
        // preg_match('/(a)(?<x>b)/n', 'ab', $m) -> ['ab', 'x' => 'b', 'b']
        yield 'no auto capture' => ['pattern' => '/(a)(?<x>b)/n', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always', 'x: 1 always', '1: 1 always']];
        yield 'no group' => ['pattern' => '/abc/', 'unmatchedAsNull' => false, 'expected' => ['0: 0 always']];
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<string>}>
     */
    public static function provideMatchAllLayouts(): iterable
    {
        // preg_match_all('/(a)(z)?/', 'a az', $m) -> [['a', 'az'], ['a', 'a'], ['', 'z']]: every key, every call (PHP 8.4.26)
        yield 'every key is written' => ['pattern' => '/(a)(z)?/', 'expected' => ['0: 0 always', '1: 1 always', '2: 2 +unset']];
        // preg_match_all('/(?!(b))a/', 'a', $m) -> [['a'], ['']]
        yield 'group no match sets is written' => ['pattern' => '/(?!(b))a/', 'expected' => ['0: 0 always', '1: - +unset']];
        yield 'group no match sets, then a set one' => ['pattern' => '/(?!(b))(a)/', 'expected' => ['0: 0 always', '1: - +unset', '2: 2 always']];
        // preg_match_all('/(?J)(?<n>a)|(?<n>b)/', 'ab', $m) -> n => ['', 'b']: the list of the highest-numbered group
        yield 'shared name holds its last group' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'expected' => ['0: 0 always', 'n: 2 +unset', '1: 1 +unset', '2: 2 +unset']];
        // preg_match_all('/a(*MARK:x)|b(*MARK:y)/', 'ab', $m) -> [['a', 'b'], 'MARK' => ['x', 'y']]
        yield 'marks are written when a match set one' => ['pattern' => '/a(*MARK:x)|b(*MARK:y)/', 'expected' => ['0: 0 always', 'MARK?: - +marks']];
        yield 'group named MARK beside a verb' => ['pattern' => '/(?<MARK>a)(*MARK:m)/', 'expected' => ['0: 0 always', 'MARK: 1 +marks always', '1: 1 always']];
    }

    private static function shape(string $pattern): CaptureShape
    {
        return (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern));
    }

    /**
     * @return list<string>
     */
    private static function describe(CaptureLayout $layout): array
    {
        return array_map(static function (CaptureKey $key): string {
            $groups = [] === $key->groups ? '-' : implode(',', array_map(static fn ($group): int => $group->number, $key->groups));

            return $key->key.($key->optional ? '?' : '').': '.$groups
                .($key->unset ? ' +unset' : '')
                .($key->marks ? ' +marks' : '')
                .($key->alwaysSet ? ' always' : '');
        }, $layout->keys);
    }
}

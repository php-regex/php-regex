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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\LineDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The line diff of a suggested rewrite: the longest run of lines kept, the
 * others deleted then inserted; and, inside one line, the prefix and the
 * suffix both sides share.
 */
final class LineDiffTest extends TestCase
{
    /**
     * @param list<string>                                               $old
     * @param list<string>                                               $new
     * @param list<array{type: 'delete'|'equal'|'insert', line: string}> $expected
     */
    #[Test]
    #[DataProvider('provideLines')]
    public function test_lines_keep_the_longest_common_run(array $old, array $new, array $expected): void
    {
        $this->assertSame($expected, LineDiff::lines($old, $new));
    }

    /**
     * @return iterable<string, array{old: list<string>, new: list<string>, expected: list<array{type: string, line: string}>}>
     */
    public static function provideLines(): iterable
    {
        yield 'nothing' => ['old' => [], 'new' => [], 'expected' => []];
        yield 'an insert into nothing' => ['old' => [], 'new' => ['added'], 'expected' => [['type' => 'insert', 'line' => 'added']]];
        yield 'a delete of everything' => ['old' => ['gone'], 'new' => [], 'expected' => [['type' => 'delete', 'line' => 'gone']]];
        yield 'the same lines' => ['old' => ['a', 'b'], 'new' => ['a', 'b'], 'expected' => [['type' => 'equal', 'line' => 'a'], ['type' => 'equal', 'line' => 'b']]];
        yield 'a line replaced between kept ones' => [
            'old' => ['/', 'x', '/x'],
            'new' => ['/', 'y', '/x'],
            'expected' => [['type' => 'equal', 'line' => '/'], ['type' => 'delete', 'line' => 'x'], ['type' => 'insert', 'line' => 'y'], ['type' => 'equal', 'line' => '/x']],
        ];
        yield 'a tie deletes before it inserts' => [
            'old' => ['a', 'b'],
            'new' => ['b', 'a'],
            'expected' => [['type' => 'delete', 'line' => 'a'], ['type' => 'equal', 'line' => 'b'], ['type' => 'insert', 'line' => 'a']],
        ];
        yield 'an insert at the end' => [
            'old' => ['a'],
            'new' => ['a', 'b'],
            'expected' => [['type' => 'equal', 'line' => 'a'], ['type' => 'insert', 'line' => 'b']],
        ];
        yield 'a delete at the start' => [
            'old' => ['a', 'b'],
            'new' => ['b'],
            'expected' => [['type' => 'delete', 'line' => 'a'], ['type' => 'equal', 'line' => 'b']],
        ];
        yield 'the longest run, not the first' => [
            'old' => ['x', 'a', 'b', 'c'],
            'new' => ['a', 'b', 'c', 'x'],
            'expected' => [['type' => 'delete', 'line' => 'x'], ['type' => 'equal', 'line' => 'a'], ['type' => 'equal', 'line' => 'b'], ['type' => 'equal', 'line' => 'c'], ['type' => 'insert', 'line' => 'x']],
        ];
    }

    /**
     * The lines kept are a longest common run, and the operations read back
     * both sides: the kept and deleted lines give the old one, the kept and
     * inserted lines the new one.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    #[Test]
    #[DataProvider('provideLongestRuns')]
    public function test_lines_keep_as_many_lines_as_the_longest_common_run(array $old, array $new, int $longest): void
    {
        $ops = LineDiff::lines($old, $new);

        $this->assertCount($longest, array_filter($ops, static fn (array $op): bool => 'equal' === $op['type']));
        $this->assertSame($old, array_values(array_map(static fn (array $op): string => $op['line'], array_filter($ops, static fn (array $op): bool => 'insert' !== $op['type']))));
        $this->assertSame($new, array_values(array_map(static fn (array $op): string => $op['line'], array_filter($ops, static fn (array $op): bool => 'delete' !== $op['type']))));
    }

    /**
     * @return iterable<string, array{old: list<string>, new: list<string>, longest: int}>
     */
    public static function provideLongestRuns(): iterable
    {
        yield 'two sequences of seven and six' => ['old' => ['a', 'b', 'c', 'b', 'd', 'a', 'b'], 'new' => ['b', 'd', 'c', 'a', 'b', 'a'], 'longest' => 4];
        yield 'every other line changed' => ['old' => ['a', 'b', 'c', 'd', 'e'], 'new' => ['a', 'x', 'c', 'y', 'e'], 'longest' => 3];
        yield 'a run that starts late' => ['old' => ['x', 'y', 'a', 'b', 'c'], 'new' => ['a', 'b', 'c', 'x'], 'longest' => 3];
        yield 'one line in common at the end' => ['old' => ['a', 'b', 'z'], 'new' => ['c', 'z'], 'longest' => 1];
        yield 'nothing in common' => ['old' => ['a', 'b'], 'new' => ['c', 'd', 'e'], 'longest' => 0];
    }

    /**
     * @param array{int, int} $expected the length of the shared prefix, then of the shared suffix
     */
    #[Test]
    #[DataProvider('provideAffixes')]
    public function test_affixes_never_overlap(string $old, string $new, array $expected): void
    {
        $this->assertSame($expected, LineDiff::affixes($old, $new));
    }

    /**
     * @return iterable<string, array{old: string, new: string, expected: array{int, int}}>
     */
    public static function provideAffixes(): iterable
    {
        yield 'the same text' => ['old' => '/ab/', 'new' => '/ab/', 'expected' => [4, 0]];
        yield 'a middle rewritten' => ['old' => '/[0-9]+/', 'new' => '/\d+/', 'expected' => [1, 2]];
        yield 'a part dropped' => ['old' => '/a{1}b{1}/', 'new' => '/ab/', 'expected' => [2, 1]];
        yield 'nothing shared' => ['old' => 'abc', 'new' => 'xyz', 'expected' => [0, 0]];
        yield 'a repeated character, counted once' => ['old' => 'aa', 'new' => 'aaa', 'expected' => [2, 0]];
        yield 'an empty side' => ['old' => '', 'new' => 'a', 'expected' => [0, 0]];
        yield 'a suffix alone' => ['old' => 'xb', 'new' => 'yb', 'expected' => [0, 1]];
    }
}

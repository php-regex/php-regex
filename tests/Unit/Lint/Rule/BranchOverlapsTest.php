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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\Rule\Support\BranchOverlaps;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Text\Bytes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first two branches that overlap are found without comparing every
 * pair: the answer is held here against that comparison, each branch with
 * every later one in the order of the alternation, and the work is counted.
 *
 * A branch is written [text, caseless, ranges]: the text it starts with,
 * whether it reads its start in either case, and the characters it can
 * start with; then, where the test gives them, the bytes it can go on with
 * past its text, null where they are not known.
 *
 * @phpstan-type Branch array{0: string, 1: bool, 2: list<array{int, int}>, 3?: list<array{int, int}>|null}
 */
final class BranchOverlapsTest extends TestCase
{
    private const ANY = [[0, 0]];

    /**
     * @param list<Branch>     $branches
     * @param ?array{int, int} $expected
     */
    #[Test]
    #[DataProvider('provideBranches')]
    public function test_the_first_pair_is_the_one_a_comparison_of_every_pair_meets(array $branches, ?array $expected): void
    {
        $this->assertSame($expected, self::pairwise($branches));
        $this->assertSame($expected, self::overlaps($branches)->first());
    }

    /**
     * @return iterable<string, array{branches: list<Branch>, expected: ?array{int, int}}>
     */
    public static function provideBranches(): iterable
    {
        yield 'no branch' => ['branches' => [], 'expected' => null];
        yield 'one branch' => ['branches' => self::texts('a'), 'expected' => null];
        yield 'two equal texts' => ['branches' => self::texts('ab', 'ab'), 'expected' => [0, 1]];
        yield 'a text that starts the next' => ['branches' => self::texts('a', 'ab'), 'expected' => [0, 1]];
        yield 'a text started by the next' => ['branches' => self::texts('ab', 'a'), 'expected' => [0, 1]];
        yield 'texts differing in their last character' => ['branches' => self::texts('abc1', 'abc2', 'abc3'), 'expected' => null];
        yield 'texts sharing nothing' => ['branches' => self::texts('a', 'b', 'c'), 'expected' => null];
        yield 'a text that starts two others' => ['branches' => self::texts('ab1', 'abc', 'ab'), 'expected' => [0, 2]];
        yield 'a text started by one far from it in byte order' => ['branches' => self::texts('b', 'ac', 'ab', 'a'), 'expected' => [1, 3]];
        yield 'the pair of the first branch, not the nearest texts' => ['branches' => self::texts('ac', 'abc', 'ab', 'a'), 'expected' => [0, 3]];
        yield 'the first later branch, not the longest text' => ['branches' => self::texts('a', 'x', 'abc', 'ab'), 'expected' => [0, 2]];
        yield 'a chain of texts' => ['branches' => self::texts('aaa', 'a', 'aa'), 'expected' => [0, 1]];
        yield 'a pair after branches that overlap nothing' => ['branches' => self::texts('x', 'y', 'ab', 'z', 'abc'), 'expected' => [2, 4]];
        yield 'a text with a byte above ASCII' => ['branches' => self::texts("\xC3\xA9a", "\xC3\xA8", "\xC3\xA9"), 'expected' => [0, 2]];
        yield 'a digit-like text read as text' => ['branches' => self::texts('10', '9', '1'), 'expected' => [0, 2]];

        yield 'two cases, neither caseless' => [
            'branches' => [['Ab', false, self::ANY], ['ab', false, self::ANY]],
            'expected' => null,
        ];
        yield 'two cases, the second caseless' => [
            'branches' => [['Ab', false, self::ANY], ['ab', true, self::ANY]],
            'expected' => [0, 1],
        ];
        yield 'two cases, the first caseless' => [
            'branches' => [['Ab', true, self::ANY], ['abc', false, self::ANY]],
            'expected' => [0, 1],
        ];
        yield 'two caseless texts' => [
            'branches' => [['AB', true, self::ANY], ['a', true, self::ANY]],
            'expected' => [0, 1],
        ];
        yield 'case-sensitive texts in two cases beside an unrelated caseless one' => [
            'branches' => [['AB', false, self::ANY], ['x', true, self::ANY], ['ab', false, self::ANY]],
            'expected' => null,
        ];
        yield 'case-sensitive texts that overlap beside a caseless one' => [
            'branches' => [['AB', false, self::ANY], ['A', false, self::ANY], ['x', true, self::ANY]],
            'expected' => [0, 1],
        ];
        yield 'case-sensitive texts met only by the caseless one' => [
            'branches' => [['Ab', false, self::ANY], ['aB', false, self::ANY], ['AB', true, self::ANY]],
            'expected' => [0, 2],
        ];
        yield 'a caseless text between two case-sensitive ones it starts' => [
            'branches' => [['ABc', false, self::ANY], ['abD', false, self::ANY], ['aB', true, self::ANY]],
            'expected' => [0, 2],
        ];
        yield 'a caseless text alone in its case' => [
            'branches' => [['a', true, self::ANY], ['B', false, self::ANY], ['b', false, self::ANY]],
            'expected' => null,
        ];
        yield 'a caseless text that two shorter case-sensitive ones start' => [
            'branches' => [['A', false, self::ANY], ['aB', false, self::ANY], ['abc', true, self::ANY]],
            'expected' => [0, 2],
        ];
        yield 'a caseless text that starts two longer case-sensitive ones' => [
            'branches' => [['aBc', false, self::ANY], ['Abd', false, self::ANY], ['x', false, self::ANY], ['AB', true, self::ANY]],
            'expected' => [0, 3],
        ];
        yield 'a caseless text equal to itself only' => [
            'branches' => [['ab', true, self::ANY], ['ac', false, self::ANY]],
            'expected' => null,
        ];

        yield 'no text, sets apart' => [
            'branches' => [['', false, [[1, 3]]], ['', false, [[4, 6]]], ['', false, [[7, 9]]]],
            'expected' => null,
        ];
        yield 'no text, sets meeting at one character' => [
            'branches' => [['', false, [[1, 3]]], ['', false, [[7, 9]]], ['', false, [[3, 5]]]],
            'expected' => [0, 2],
        ];
        yield 'no text, the same set' => [
            'branches' => [['', false, [[1, 3]]], ['', false, [[1, 3]]]],
            'expected' => [0, 1],
        ];
        yield 'no text, a set of two ranges meeting on its second' => [
            'branches' => [['', false, [[1, 2], [8, 9]]], ['', false, [[4, 5]]], ['', false, [[9, 12]]]],
            'expected' => [0, 2],
        ];
        yield 'an empty set meets nothing' => [
            'branches' => [['', false, []], ['', false, []], ['a', false, []]],
            'expected' => null,
        ];
        yield 'texts told apart in one set' => [
            'branches' => [['ab', false, [[1, 1]]], ['ac', false, [[1, 1]]]],
            'expected' => null,
        ];
        yield 'equal texts in sets apart' => [
            'branches' => [['ab', false, [[1, 1]]], ['ab', false, [[2, 2]]]],
            'expected' => null,
        ];
        yield 'a text and no text in one set' => [
            'branches' => [['ab', false, [[1, 1]]], ['cd', false, [[1, 1]]], ['', false, [[1, 1]]]],
            'expected' => [0, 2],
        ];
        yield 'a text and no text in sets that meet' => [
            'branches' => [['ab', false, [[1, 10]]], ['cd', false, [[1, 10]]], ['', false, [[5, 6]]]],
            'expected' => [0, 2],
        ];
        yield 'no text meeting a text after two texts told apart' => [
            'branches' => [['ab', false, [[1, 4]]], ['cd', false, [[1, 4]]], ['', false, [[6, 8]]], ['ef', false, [[8, 9]]]],
            'expected' => [2, 3],
        ];
        yield 'texts that overlap where their sets meet' => [
            'branches' => [['x', false, [[1, 9]]], ['ab', false, [[1, 5]]], ['abc', false, [[5, 9]]]],
            'expected' => [1, 2],
        ];
        yield 'texts that overlap in sets that end before the other starts' => [
            'branches' => [['ab', false, [[1, 4]]], ['abc', false, [[5, 9]]]],
            'expected' => null,
        ];
        yield 'texts that overlap once a set with no text has ended' => [
            'branches' => [['x', false, [[1, 2]]], ['ab', false, [[4, 9]]], ['abc', false, [[4, 9]]], ['', false, [[1, 2]]]],
            'expected' => [0, 3],
        ];
        yield 'texts that overlap, found after the set of another has ended' => [
            'branches' => [['', false, [[1, 2]]], ['ab', false, [[1, 9]]], ['abc', false, [[5, 9]]], ['y', false, [[7, 9]]]],
            'expected' => [0, 1],
        ];
        yield 'texts that overlap in the second range of their set' => [
            'branches' => [['q', false, [[1, 1]]], ['ab', true, [[3, 3], [7, 7]]], ['AB', false, [[7, 7]]]],
            'expected' => [1, 2],
        ];
        yield 'one branch with no text among sets apart' => [
            'branches' => [['', false, [[1, 1]]], ['ab', false, [[2, 2]]], ['', false, [[3, 3]]]],
            'expected' => null,
        ];
    }

    #[Test]
    public function test_generated_branches_give_the_pair_of_the_pairwise_comparison(): void
    {
        $sets = [[], [[0, 0]], [[1, 1]], [[0, 1]], [[1, 3]], [[3, 4]], [[0, 0], [4, 5]], [[2, 2], [5, 5]], [[0, 5]]];
        $letters = ['a', 'b', 'A'];
        $state = 20261110;
        $next = static function (int $below) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

            return ($state >> 8) % $below;
        };

        $pairs = 0;
        for ($case = 0; $case < 4000; $case++) {
            $branches = [];
            // A third of the cases share one set, as literal branches do.
            $shared = 0 === $next(3) ? $sets[1 + $next(\count($sets) - 1)] : null;
            for ($count = $next(9); $count > 0; $count--) {
                $text = '';
                for ($length = $next(4); $length > 0; $length--) {
                    $text .= $letters[$next(3)];
                }

                $branches[] = [$text, 0 === $next(3), $shared ?? $sets[$next(\count($sets))]];
            }

            $expected = self::pairwise($branches);
            $pairs += null === $expected ? 0 : 1;
            $this->assertSame($expected, self::overlaps($branches)->first(), json_encode($branches, \JSON_THROW_ON_ERROR));
        }

        // The corpus holds both answers in number.
        $this->assertGreaterThan(1000, $pairs);
        $this->assertLessThan(3000, $pairs);
    }

    /**
     * @param Branch $one
     * @param Branch $other
     */
    #[Test]
    #[DataProvider('providePairs')]
    public function test_two_branches_overlap_when_their_sets_meet_and_one_text_starts_the_other(array $one, array $other, bool $expected): void
    {
        $this->assertSame($expected, self::overlapping($one, $other));
        $this->assertSame($expected, self::overlaps([$one, $other])->overlap(0, 1));
        $this->assertSame($expected, self::overlaps([$one, $other])->overlap(1, 0));
    }

    /**
     * @return iterable<string, array{one: Branch, other: Branch, expected: bool}>
     */
    public static function providePairs(): iterable
    {
        yield 'a text that starts the other' => ['one' => ['a', false, self::ANY], 'other' => ['ab', false, self::ANY], 'expected' => true];
        yield 'texts told apart' => ['one' => ['ab', false, self::ANY], 'other' => ['ac', false, self::ANY], 'expected' => false];
        yield 'another case, read as written' => ['one' => ['A', false, self::ANY], 'other' => ['ab', false, self::ANY], 'expected' => false];
        yield 'another case, one caseless' => ['one' => ['A', false, self::ANY], 'other' => ['ab', true, self::ANY], 'expected' => true];
        yield 'another case, told apart all the same' => ['one' => ['Ab', true, self::ANY], 'other' => ['ac', true, self::ANY], 'expected' => false];
        yield 'sets apart' => ['one' => ['a', false, [[1, 2]]], 'other' => ['a', false, [[3, 4]]], 'expected' => false];
        yield 'sets meeting' => ['one' => ['', false, [[1, 3]]], 'other' => ['a', false, [[3, 4]]], 'expected' => true];
        yield 'an empty set' => ['one' => ['', false, []], 'other' => ['', false, [[3, 4]]], 'expected' => false];
    }

    /**
     * A text read in two cases, part of it caselessly, meets another
     * character by character: two characters meet when they are the same,
     * or one letter in two cases where either is read caselessly. A text is
     * written with its case, "i" where a character is read caselessly.
     */
    #[Test]
    #[DataProvider('provideTextsInTwoCases')]
    public function test_texts_read_in_two_cases_meet_character_by_character(string $one, string $oneCase, string $other, string $otherCase, bool $expected): void
    {
        $overlaps = self::overlapsInTwoCases([[$one, $oneCase], [$other, $otherCase]]);

        $this->assertSame($expected, $overlaps->overlap(0, 1));
        $this->assertSame($expected, $overlaps->overlap(1, 0));
        $this->assertSame($expected ? [0, 1] : null, $overlaps->first());
    }

    /**
     * @return iterable<string, array{one: string, oneCase: string, other: string, otherCase: string, expected: bool}>
     */
    public static function provideTextsInTwoCases(): iterable
    {
        yield 'a letter read caselessly, then one in its own case' => ['one' => 'aB', 'oneCase' => 'i-', 'other' => 'Ab', 'otherCase' => 'i-', 'expected' => false];
        yield 'a letter read caselessly, then the same letter' => ['one' => 'aB', 'oneCase' => 'i-', 'other' => 'ABc', 'otherCase' => 'i--', 'expected' => true];
        yield 'a letter in its own case beside one read caselessly' => ['one' => 'Ax', 'oneCase' => 'i-', 'other' => 'aX', 'otherCase' => '--', 'expected' => false];
        yield 'a letter read caselessly beside one in its own case' => ['one' => 'Ax', 'oneCase' => '-i', 'other' => 'AX', 'otherCase' => '--', 'expected' => true];
        yield 'letters in their own case, then letters read caselessly' => ['one' => 'Ab', 'oneCase' => '-i', 'other' => 'aB', 'otherCase' => '-i', 'expected' => false];
        yield 'a text read in its own case beside one read caselessly' => ['one' => 'AB', 'oneCase' => '--', 'other' => 'abc', 'otherCase' => 'ii-', 'expected' => true];
    }

    #[Test]
    public function test_generated_texts_in_two_cases_give_the_pair_of_the_pairwise_comparison(): void
    {
        $letters = ['a', 'b', 'A'];
        $state = 20261125;
        $next = static function (int $below) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

            return ($state >> 8) % $below;
        };

        $pairs = 0;
        for ($case = 0; $case < 4000; $case++) {
            $branches = [];
            for ($count = $next(7); $count > 0; $count--) {
                $text = $mask = '';
                for ($length = $next(4); $length > 0; $length--) {
                    $text .= $letters[$next(3)];
                    $mask .= 0 === $next(2) ? 'i' : '-';
                }

                $branches[] = [$text, $mask];
            }

            $expected = null;
            for ($i = 0; $i < \count($branches) && null === $expected; $i++) {
                for ($j = $i + 1; $j < \count($branches) && null === $expected; $j++) {
                    $expected = self::meetInTwoCases($branches[$i], $branches[$j]) ? [$i, $j] : null;
                }
            }

            $pairs += null === $expected ? 0 : 1;
            $this->assertSame($expected, self::overlapsInTwoCases($branches)->first(), json_encode($branches, \JSON_THROW_ON_ERROR));
        }

        // The corpus holds both answers in number.
        $this->assertGreaterThan(1000, $pairs);
        $this->assertLessThan(3000, $pairs);
    }

    /**
     * Two texts read in their own case, one starting the other, are told
     * apart by what follows the shorter one: the bytes its branch can go on
     * with past its text hold the next byte of the longer text, or, where
     * the texts are the same, a byte the other branch can go on with too.
     *
     * @param Branch $one
     * @param Branch $other
     */
    #[Test]
    #[DataProvider('providePairsWithWhatFollowsTheirText')]
    public function test_two_texts_overlap_when_the_shorter_can_go_on_as_the_longer_does(array $one, array $other, bool $expected): void
    {
        $this->assertSame($expected, self::overlapping($one, $other));
        $this->assertSame($expected, self::overlaps([$one, $other])->overlap(0, 1));
        $this->assertSame($expected, self::overlaps([$other, $one])->overlap(0, 1));
        $this->assertSame($expected, self::overlaps([$one, $other])->overlap(1, 0));
        $this->assertSame($expected ? [0, 1] : null, self::overlaps([$one, $other])->first());
    }

    /**
     * @return iterable<string, array{one: Branch, other: Branch, expected: bool}>
     */
    public static function providePairsWithWhatFollowsTheirText(): iterable
    {
        $digits = [[0x30, 0x39]];
        $letters = [[0x61, 0x7A]];

        yield 'a text going on with the next byte of the longer one' => ['one' => ['a', false, self::ANY, $letters], 'other' => ['ab', false, self::ANY, null], 'expected' => true];
        yield 'a text going on with another byte than the next of the longer one' => ['one' => ['a', false, self::ANY, $digits], 'other' => ['ab', false, self::ANY, null], 'expected' => false];
        yield 'a text that may end where the longer one goes on' => ['one' => ['a', false, self::ANY, null], 'other' => ['ab', false, self::ANY, $digits], 'expected' => true];
        yield 'what follows the longer text is not read' => ['one' => ['a', false, self::ANY, $letters], 'other' => ['ab', false, self::ANY, $digits], 'expected' => true];
        yield 'the byte right after the shorter text, not the one before it' => ['one' => ['ab', false, self::ANY, [[0x62, 0x62]]], 'other' => ['abcd', false, self::ANY, null], 'expected' => false];
        yield 'the byte right after the shorter text, not the one after it' => ['one' => ['ab', false, self::ANY, [[0x64, 0x64]]], 'other' => ['abcd', false, self::ANY, null], 'expected' => false];
        yield 'the byte right after the shorter text' => ['one' => ['ab', false, self::ANY, [[0x63, 0x63]]], 'other' => ['abcd', false, self::ANY, null], 'expected' => true];
        yield 'a byte above ASCII after the shorter text' => ['one' => ['a', false, self::ANY, [[0x80, 0xFF]]], 'other' => ["a\xC3\xA9", false, self::ANY, null], 'expected' => true];
        yield 'a byte above ASCII the shorter text does not go on with' => ['one' => ['a', false, self::ANY, $letters], 'other' => ["a\xC3\xA9", false, self::ANY, null], 'expected' => false];
        yield 'the same text, going on apart' => ['one' => ['a', false, self::ANY, $digits], 'other' => ['a', false, self::ANY, $letters], 'expected' => false];
        yield 'the same text, going on alike' => ['one' => ['a', false, self::ANY, $digits], 'other' => ['a', false, self::ANY, [[0x39, 0x41]]], 'expected' => true];
        yield 'the same text, one branch ending there' => ['one' => ['a', false, self::ANY, $digits], 'other' => ['a', false, self::ANY, null], 'expected' => true];
        yield 'the same text, both branches ending there' => ['one' => ['a', false, self::ANY, null], 'other' => ['a', false, self::ANY, null], 'expected' => true];
        yield 'texts told apart, whatever follows them' => ['one' => ['ab', false, self::ANY, $letters], 'other' => ['ac', false, self::ANY, $letters], 'expected' => false];
        yield 'sets apart, whatever follows the texts' => ['one' => ['a', false, [[1, 2]], $letters], 'other' => ['ab', false, [[3, 4]], null], 'expected' => false];
        // A branch that starts with no text goes on with its first bytes.
        yield 'no text, going on with the first byte of the other' => ['one' => ['', false, self::ANY, $letters], 'other' => ['ab', false, self::ANY, null], 'expected' => true];
        yield 'no text, going on with another byte than the first of the other' => ['one' => ['', false, self::ANY, $digits], 'other' => ['ab', false, self::ANY, null], 'expected' => false];
        // A text read caselessly meets the other in either case: what
        // follows it is not read.
        yield 'a caseless text, whatever follows it' => ['one' => ['a', true, self::ANY, $digits], 'other' => ['AB', false, self::ANY, null], 'expected' => true];
        yield 'a text beside a caseless one, whatever follows it' => ['one' => ['a', false, self::ANY, $digits], 'other' => ['AB', true, self::ANY, null], 'expected' => true];
    }

    #[Test]
    public function test_texts_read_in_two_cases_meet_whatever_follows_them(): void
    {
        $overlaps = new BranchOverlaps(
            ['a', 'aB'],
            ['a', 'ab'],
            [false, true],
            array_fill(0, 2, CharSet::fromRanges(self::ANY)),
            [[['a', 'a', false]], [['a', 'a', false], ['B', 'b', true]]],
            static fn (int $branch): ByteCharSet => 0 === $branch ? ByteCharSet::fromRange(0x30, 0x39) : ByteCharSet::unknown(),
        );

        $this->assertTrue($overlaps->overlap(0, 1));
        $this->assertSame([0, 1], $overlaps->first());
    }

    /**
     * @param list<Branch>     $branches
     * @param ?array{int, int} $expected
     */
    #[Test]
    #[DataProvider('provideBranchesWithWhatFollowsTheirText')]
    public function test_the_first_pair_is_looked_for_past_the_branches_told_apart_by_what_follows(array $branches, ?array $expected): void
    {
        $this->assertSame($expected, self::pairwise($branches));
        $this->assertSame($expected, self::overlaps($branches)->first());
    }

    /**
     * @return iterable<string, array{branches: list<Branch>, expected: ?array{int, int}}>
     */
    public static function provideBranchesWithWhatFollowsTheirText(): iterable
    {
        $digits = [[0x30, 0x39]];

        yield 'a pair told apart, alone' => [
            'branches' => [['a', false, self::ANY, $digits], ['ab', false, self::ANY, null]],
            'expected' => null,
        ];
        yield 'a pair told apart before one that overlaps' => [
            'branches' => [['a', false, self::ANY, $digits], ['ab', false, self::ANY, null], ['abc', false, self::ANY, null]],
            'expected' => [1, 2],
        ];
        yield 'a branch told apart from one text, not from a later one' => [
            'branches' => [['a', false, self::ANY, $digits], ['ab', false, self::ANY, null], ['x', false, self::ANY, null], ['a1', false, self::ANY, null]],
            'expected' => [0, 3],
        ];
        yield 'two branches told apart from every text they start' => [
            'branches' => [['a', false, self::ANY, $digits], ['b', false, self::ANY, $digits], ['ab', false, self::ANY, null], ['bc', false, self::ANY, null], ['x', false, self::ANY, null]],
            'expected' => null,
        ];
        yield 'two branches told apart, then a pair far from them' => [
            'branches' => [['a', false, self::ANY, $digits], ['b', false, self::ANY, $digits], ['ab', false, self::ANY, null], ['bc', false, self::ANY, null], ['x', false, self::ANY, null], ['xy', false, self::ANY, null]],
            'expected' => [4, 5],
        ];
        yield 'a branch told apart, whose text a later branch shares and goes on alike' => [
            'branches' => [['a', false, self::ANY, $digits], ['ab', false, self::ANY, null], ['a', false, self::ANY, [[0x35, 0x35]]]],
            'expected' => [0, 2],
        ];
        yield 'a branch with no text told apart from a text by its first bytes' => [
            'branches' => [['', false, self::ANY, $digits], ['ab', false, self::ANY, null], ['ac', false, self::ANY, null]],
            'expected' => null,
        ];
    }

    #[Test]
    public function test_generated_branches_with_what_follows_give_the_pair_of_the_pairwise_comparison(): void
    {
        $sets = [[[0, 0]], [[0, 1]], [[1, 3]], [[0, 5]]];
        $follows = [null, null, [[0x61, 0x61]], [[0x62, 0x62]], [[0x61, 0x62]], [[0x41, 0x41]], []];
        $letters = ['a', 'b', 'A'];
        $state = 20261126;
        $next = static function (int $below) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

            return ($state >> 8) % $below;
        };

        $pairs = 0;
        $apart = 0;
        for ($case = 0; $case < 4000; $case++) {
            $branches = [];
            for ($count = $next(9); $count > 0; $count--) {
                $text = '';
                for ($length = $next(4); $length > 0; $length--) {
                    $text .= $letters[$next(3)];
                }

                $branches[] = [$text, 0 === $next(4), $sets[$next(\count($sets))], $follows[$next(\count($follows))]];
            }

            $expected = self::pairwise($branches);
            $pairs += null === $expected ? 0 : 1;
            // The same branches, what follows their texts left unread.
            $apart += $expected === self::pairwise(array_map(static fn (array $branch): array => \array_slice($branch, 0, 3), $branches)) ? 0 : 1;
            $this->assertSame($expected, self::overlaps($branches)->first(), json_encode($branches, \JSON_THROW_ON_ERROR));
        }

        // The corpus holds both answers in number, and what follows the
        // texts changes the answer of many.
        $this->assertGreaterThan(1000, $pairs);
        $this->assertLessThan(3000, $pairs);
        $this->assertGreaterThan(300, $apart);
    }

    /**
     * What follows a text is asked of the branches whose texts start one
     * another, once each, however many times they are compared.
     */
    #[Test]
    public function test_what_follows_a_text_is_asked_once_of_the_branches_that_need_it(): void
    {
        $asked = [];
        $follows = static function (int $branch) use (&$asked): ByteCharSet {
            $asked[] = $branch;

            return 0 === $branch || 3 === $branch ? ByteCharSet::fromRange(0x30, 0x39) : ByteCharSet::unknown();
        };
        // "a" starts "ab" and "ac", and stands twice; "x" starts nothing.
        $texts = ['a', 'ab', 'ac', 'a', 'x', 'y'];
        $overlaps = new BranchOverlaps($texts, $texts, array_fill(0, 6, false), array_fill(0, 6, CharSet::fromRanges(self::ANY)), null, $follows);

        $this->assertSame([0, 3], $overlaps->first());
        $this->assertTrue($overlaps->overlap(0, 3));
        $this->assertFalse($overlaps->overlap(0, 1));
        $this->assertFalse($overlaps->overlap(2, 0));
        // Branch 0 with 1, with 2, then with 3, which is asked in turn.
        $this->assertSame([0, 3], $asked);
    }

    #[Test]
    public function test_a_branch_told_apart_from_every_text_it_starts_costs_one_row_of_comparisons(): void
    {
        $compared = [];
        foreach ([200, 800] as $total) {
            // The first text starts every other, and goes on with no digit.
            $branches = [['shared_', false, self::ANY, [[0x61, 0x7A]]]];
            for ($index = 1; $index < $total; $index++) {
                $branches[] = [\sprintf('shared_%05d', $index), false, self::ANY, null];
            }

            $overlaps = self::overlaps($branches);
            $this->assertNull($overlaps->first());
            $compared[$total] = $overlaps->compared();
        }

        // Every pair would be 19,900 comparisons, then 319,600. The texts
        // are read in byte order, each against the one before it and
        // against the first, which starts it; the first branch is compared
        // with each later one, and the texts left are read once more.
        $this->assertSame(397 + 199 + 198, $compared[200]);
        $this->assertSame(1597 + 799 + 798, $compared[800]);
    }

    #[Test]
    public function test_branches_sharing_a_long_start_are_compared_a_few_times_each(): void
    {
        $compared = [];
        foreach ([200, 800] as $total) {
            $branches = [];
            for ($index = 0; $index < $total; $index++) {
                $branches[] = [\sprintf('a_long_start_shared_by_every_branch_%05d', $index), false, self::ANY];
            }

            $overlaps = self::overlaps($branches);
            $this->assertNull($overlaps->first());
            $compared[$total] = $overlaps->compared();
        }

        // Every pair would be 19,900 comparisons, then 319,600.
        $this->assertSame(199, $compared[200]);
        $this->assertSame(799, $compared[800]);
    }

    #[Test]
    public function test_a_pair_at_the_end_of_a_wide_alternation_costs_one_row_of_comparisons(): void
    {
        $branches = [];
        for ($index = 0; $index < 500; $index++) {
            $branches[] = [\sprintf('shared_%04d', $index), false, self::ANY];
        }

        $branches[] = ['shared_0250_and_more', false, self::ANY];

        $overlaps = self::overlaps($branches);
        $this->assertSame([250, 500], $overlaps->first());
        // The texts read in byte order, then branch 250 with each later one.
        $this->assertSame(501 + 250, $overlaps->compared());
    }

    #[Test]
    public function test_branches_with_no_text_are_not_compared_to_find_that_they_overlap(): void
    {
        $branches = [];
        for ($index = 0; $index < 300; $index++) {
            $branches[] = ['', false, [[$index, $index + 1]]];
        }

        $overlaps = self::overlaps($branches);
        $this->assertSame([0, 1], $overlaps->first());
        $this->assertSame(1, $overlaps->compared());
    }

    /**
     * @param list<Branch>     $branches
     * @param ?array{int, int} $expected
     */
    #[Test]
    #[DataProvider('provideRuledOutBranches')]
    public function test_branches_ruled_out_together_are_not_compared(array $branches, ?array $expected, int $compared): void
    {
        $overlaps = self::overlaps($branches);

        $this->assertSame($expected, self::pairwise($branches));
        $this->assertSame($expected, $overlaps->first());
        $this->assertSame($compared, $overlaps->compared());
    }

    /**
     * @return iterable<string, array{branches: list<Branch>, expected: ?array{int, int}, compared: int}>
     */
    public static function provideRuledOutBranches(): iterable
    {
        yield 'no text, sets apart' => [
            'branches' => [['', false, [[1, 3]]], ['', false, [[4, 6]]], ['', false, [[7, 9]]]],
            'expected' => null,
            'compared' => 0,
        ];
        yield 'texts that overlap, in sets apart' => [
            'branches' => [['ab', false, [[1, 4]]], ['abc', false, [[5, 9]]]],
            'expected' => null,
            'compared' => 0,
        ];
        // The two last branches are read once; the first is not read with them.
        yield 'a text that overlaps one of a set it never meets' => [
            'branches' => [['ab', false, [[1, 1]]], ['abc', false, [[3, 3]]], ['x', false, [[3, 3]]]],
            'expected' => null,
            'compared' => 1,
        ];
        // The three texts are read in byte order, each with the one before it.
        yield 'texts told apart, after a set with no text has ended' => [
            'branches' => [['', false, [[1, 1]]], ['ab', false, [[3, 3]]], ['ac', false, [[3, 3]]], ['ad', false, [[3, 3]]]],
            'expected' => null,
            'compared' => 2,
        ];
        // The two texts are read once; their set has ended where the branch
        // with no text meets the last one, and is compared with it.
        yield 'a set that has ended before one with no text meets another' => [
            'branches' => [['ab', false, [[1, 4]]], ['cd', false, [[1, 4]]], ['', false, [[6, 8]]], ['ef', false, [[8, 9]]]],
            'expected' => [2, 3],
            'compared' => 2,
        ];
        // Branch 1 with the two later ones: the first branch is never held with another.
        yield 'sets given out of the order of the alphabet' => [
            'branches' => [['', false, [[1, 2]]], ['', false, [[8, 9]]], ['', false, [[4, 5]]], ['', false, [[8, 9]]]],
            'expected' => [1, 3],
            'compared' => 2,
        ];
    }

    #[Test]
    public function test_texts_are_read_again_only_when_a_set_brings_branches(): void
    {
        // Read where the first set starts, again where the second joins
        // it, not where the second leaves.
        $branches = [['ab', false, [[1, 9]]], ['ac', false, [[1, 9]]], ['ad', false, [[3, 5]]]];

        $overlaps = self::overlaps($branches);
        $this->assertNull($overlaps->first());
        $this->assertSame(1 + 2, $overlaps->compared());
    }

    #[Test]
    public function test_caseless_texts_are_read_in_one_case_beside_the_texts_as_written(): void
    {
        $branches = [['Ab', false, self::ANY], ['aC', true, self::ANY], ['AD', false, self::ANY], ['ae', false, self::ANY]];

        $overlaps = self::overlaps($branches);
        $this->assertNull($overlaps->first());
        // The three case-sensitive texts as written, then the four in one case.
        $this->assertSame(2 + 3, $overlaps->compared());
    }

    #[Test]
    public function test_texts_are_not_read_where_a_branch_with_no_text_overlaps_them_all(): void
    {
        // Nor once that branch has left the two others alone.
        $branches = [['ab', false, [[1, 9]]], ['ac', false, [[1, 9]]], ['', false, [[1, 2]]]];

        $overlaps = self::overlaps($branches);
        $this->assertSame([0, 2], $overlaps->first());
        // Branch 0 with each later one, nothing else.
        $this->assertSame(2, $overlaps->compared());
    }

    /**
     * Case-sensitive branches that can all start with the same character.
     *
     * @return list<Branch>
     */
    private static function texts(string ...$texts): array
    {
        return array_map(static fn (string $text): array => [$text, false, self::ANY], array_values($texts));
    }

    /**
     * @param list<Branch> $branches
     */
    private static function overlaps(array $branches): BranchOverlaps
    {
        $follows = null;
        foreach ($branches as $branch) {
            if (\array_key_exists(3, $branch)) {
                $follows = static fn (int $asked): ByteCharSet => self::bytes($branches[$asked][3] ?? null);

                break;
            }
        }

        return new BranchOverlaps(
            array_column($branches, 0),
            array_map(strtolower(...), array_column($branches, 0)),
            array_column($branches, 1),
            array_map(static fn (array $branch): CharSet => CharSet::fromRanges($branch[2]), $branches),
            null,
            $follows,
        );
    }

    /**
     * The bytes of the ranges, not known for null.
     *
     * @param list<array{int, int}>|null $ranges
     */
    private static function bytes(?array $ranges): ByteCharSet
    {
        if (null === $ranges) {
            return ByteCharSet::unknown();
        }

        $set = ByteCharSet::empty();
        foreach ($ranges as [$from, $to]) {
            $set = $set->union(ByteCharSet::fromBytes($from, $to));
        }

        return $set;
    }

    /**
     * Branches that can all start with the same character, each a text and
     * its case, character by character.
     *
     * @param list<array{string, string}> $branches
     */
    private static function overlapsInTwoCases(array $branches): BranchOverlaps
    {
        $units = [];
        foreach ($branches as [$text, $mask]) {
            $units[] = array_map(static fn (string $character, string $case): array => [$character, strtolower($character), 'i' === $case], Bytes::split($text), Bytes::split($mask));
        }

        return new BranchOverlaps(
            array_column($branches, 0),
            array_map(strtolower(...), array_column($branches, 0)),
            array_map(static fn (string $mask): bool => str_contains($mask, 'i'), array_column($branches, 1)),
            array_fill(0, \count($branches), CharSet::fromRanges(self::ANY)),
            $units,
        );
    }

    /**
     * Whether one text starts the other, character by character, each read
     * in its case.
     *
     * @param array{string, string} $one
     * @param array{string, string} $other
     */
    private static function meetInTwoCases(array $one, array $other): bool
    {
        for ($at = 0; $at < min(\strlen($one[0]), \strlen($other[0])); $at++) {
            $caseless = 'i' === $one[1][$at] || 'i' === $other[1][$at];
            if ($one[0][$at] !== $other[0][$at] && (!$caseless || strtolower($one[0][$at]) !== strtolower($other[0][$at]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every branch compared with every later one, in order: the answer
     * the search has to give.
     *
     * @param list<Branch> $branches
     *
     * @return ?array{int, int}
     */
    private static function pairwise(array $branches): ?array
    {
        $total = \count($branches);
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                if (self::overlapping($branches[$i], $branches[$j])) {
                    return [$i, $j];
                }
            }
        }

        return null;
    }

    /**
     * @param Branch $one
     * @param Branch $other
     */
    private static function overlapping(array $one, array $other): bool
    {
        $meet = false;
        foreach ($one[2] as [$from, $to]) {
            foreach ($other[2] as [$otherFrom, $otherTo]) {
                $meet = $meet || ($from <= $otherTo && $otherFrom <= $to);
            }
        }

        [$first, $second] = $one[1] || $other[1] ? [strtolower($one[0]), strtolower($other[0])] : [$one[0], $other[0]];
        if (!$meet || !(str_starts_with($first, $second) || str_starts_with($second, $first))) {
            return false;
        }

        // What follows the texts tells two branches read in their own case
        // apart, where the test gives it.
        if ($one[1] || $other[1] || !\array_key_exists(3, $one) || !\array_key_exists(3, $other)) {
            return true;
        }

        [$short, $long] = \strlen($one[0]) <= \strlen($other[0]) ? [$one, $other] : [$other, $one];
        if (null === $short[3]) {
            return true;
        }

        // The bytes the longer branch has past the shorter text: the next
        // byte of its own text, or what follows it where the texts end
        // together.
        $next = \strlen($long[0]) > \strlen($short[0]) ? [[\ord($long[0][\strlen($short[0])]), \ord($long[0][\strlen($short[0])])]] : $long[3];
        if (null === $next) {
            return true;
        }

        foreach ($short[3] as [$from, $to]) {
            foreach ($next as [$nextFrom, $nextTo]) {
                if ($from <= $nextTo && $nextFrom <= $to) {
                    return true;
                }
            }
        }

        return false;
    }
}

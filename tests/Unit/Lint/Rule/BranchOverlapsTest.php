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
use PHPRegex\Parser\Hir\CharSet;
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
 * start with.
 *
 * @phpstan-type Branch array{string, bool, list<array{int, int}>}
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
        return new BranchOverlaps(
            array_column($branches, 0),
            array_map(strtolower(...), array_column($branches, 0)),
            array_column($branches, 1),
            array_map(static fn (array $branch): CharSet => CharSet::fromRanges($branch[2]), $branches),
        );
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

        return $meet && (str_starts_with($first, $second) || str_starts_with($second, $first));
    }
}

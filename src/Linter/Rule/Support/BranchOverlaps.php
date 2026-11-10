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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Parser\Hir\CharSet;

/**
 * The first two branches of an alternation that overlap, found without
 * comparing every pair of them.
 *
 * Two branches overlap when the characters they can start with meet and
 * the text one starts with starts the text of the other, both texts read
 * in one case when either branch is caseless. A branch that starts with no
 * text overlaps every branch its characters meet.
 *
 * Comparing each branch with every later one costs the square of their
 * number: seconds for a list of two thousand names. The pair that
 * comparison meets first is the pair of the first branch that overlaps
 * another, so that branch is looked for first, by what rules pairs out
 * together: the sets are walked through the alphabet, where only the
 * branches holding one character can overlap, and their texts are read in
 * byte order, where a text comes right before the texts it starts.
 *
 * @internal
 */
final class BranchOverlaps
{
    private int $compared = 0;

    /**
     * @param list<string>  $texts    the text each branch starts with, "" for a branch that starts with no literal
     * @param list<string>  $folded   the same texts with their letters in one case
     * @param list<bool>    $caseless whether each branch reads its start in either case
     * @param list<CharSet> $sets     the characters each branch can start with
     */
    public function __construct(
        private readonly array $texts,
        private readonly array $folded,
        private readonly array $caseless,
        private readonly array $sets,
    ) {}

    /**
     * The first two branches that overlap, in the order of the
     * alternation: the pair met first when each branch is compared with
     * every later one.
     *
     * @return ?array{int, int}
     */
    public function first(): ?array
    {
        // No branch before the first that overlaps another has a pair.
        $total = \count($this->texts);
        for ($one = $this->firstOverlapping(); $one < $total; $one++) {
            for ($other = $one + 1; $other < $total; $other++) {
                if ($this->overlap($one, $other)) {
                    return [$one, $other];
                }
            }
        }

        return null;
    }

    /**
     * Whether two branches overlap.
     */
    public function overlap(int $one, int $other): bool
    {
        $this->compared++;
        if ($this->sets[$one]->intersect($this->sets[$other])->isEmpty()) {
            return false;
        }

        // A caseless branch meets the other in either case.
        [$first, $second] = $this->caseless[$one] || $this->caseless[$other]
            ? [$this->folded[$one], $this->folded[$other]]
            : [$this->texts[$one], $this->texts[$other]];

        return str_starts_with($first, $second) || str_starts_with($second, $first);
    }

    /**
     * The comparisons of two branches made so far: what the cost of a
     * wide alternation is measured by.
     */
    public function compared(): int
    {
        return $this->compared;
    }

    /**
     * The first branch that overlaps another, or the number of branches
     * when none does.
     */
    private function firstOverlapping(): int
    {
        // The branches of each set: a list of names that start with one
        // letter is one set.
        $ranges = $members = $textless = [];
        foreach ($this->sets as $branch => $set) {
            $key = $set->key();
            $ranges[$key] = $set->ranges;
            $members[$key][] = $branch;
            $textless[$key] = ($textless[$key] ?? 0) + ('' === $this->texts[$branch] ? 1 : 0);
        }

        // Where each set starts and stops holding the characters of the
        // alphabet, in their order.
        $starts = $ends = [];
        foreach ($ranges as $key => $held) {
            foreach ($held as [$from, $to]) {
                $starts[$from][] = $key;
                $ends[$to + 1][] = $key;
            }
        }

        $positions = array_keys($starts + $ends);
        sort($positions);

        $first = \count($this->texts);
        $holding = $unseen = [];
        $branches = $withoutText = 0;
        $joined = false;
        foreach ($positions as $position) {
            foreach ($ends[$position] ?? [] as $key) {
                unset($holding[$key], $unseen[$key]);
                $branches -= \count($members[$key]);
                $withoutText -= $textless[$key];
            }

            foreach ($starts[$position] ?? [] as $key) {
                $holding[$key] = $unseen[$key] = true;
                $branches += \count($members[$key]);
                $withoutText += $textless[$key];
                $joined = true;
            }

            if ($branches < 2) {
                continue;
            }

            if ($withoutText > 0) {
                // A branch with no text overlaps every branch that can
                // start with the character, and they overlap it.
                foreach ($unseen as $key => $_) {
                    $first = min($first, $members[$key][0]);
                }

                $unseen = [];
                $joined = false;

                continue;
            }

            if ($joined) {
                // Every branch starts with a text: the ones a set brought
                // since the texts were last read may overlap the others.
                $first = min($first, $this->firstRelated(array_merge(...array_values(array_intersect_key($members, $holding)))));
                $joined = false;
            }
        }

        return $first;
    }

    /**
     * The first of the branches whose text starts the text of another of
     * them, or is started by it; the number of branches when there is none.
     *
     * @param list<int> $branches
     */
    private function firstRelated(array $branches): int
    {
        $written = $folded = $caseless = [];
        foreach ($branches as $branch) {
            $folded[$branch] = $this->folded[$branch];
            if ($this->caseless[$branch]) {
                $caseless[$branch] = true;
            } else {
                $written[$branch] = $this->texts[$branch];
            }
        }

        // Two case-sensitive branches are compared as written, a caseless
        // one with any other in one case.
        [$related] = $this->related($written, []);
        if ([] !== $caseless) {
            [$any, $toCaseless] = $this->related($folded, $caseless);
            $related += $toCaseless + array_intersect_key($any, $caseless);
        }

        return [] === $related ? \count($this->texts) : min(array_keys($related));
    }

    /**
     * The branches whose text starts the text of another, or is started
     * by it, then the ones for which that other is a marked branch.
     *
     * @param array<int, string> $texts  the texts, by branch
     * @param array<int, true>   $marked the marked branches
     *
     * @return array{array<int, true>, array<int, true>}
     */
    private function related(array $texts, array $marked): array
    {
        // In byte order a text comes before the texts it starts, and no
        // other text between them: the texts read so far that start the
        // current one are a stack, the shortest at the bottom.
        asort($texts, \SORT_STRING);
        $order = array_keys($texts);
        // Past the last text, nothing left on the stack starts anything.
        $order[] = null;

        $any = $toMarked = $startsMarked = $stack = [];
        $markedOnStack = 0;
        foreach ($order as $branch) {
            while ([] !== $stack && (null === $branch || !$this->starts($texts[$stack[\count($stack) - 1]], $texts[$branch]))) {
                $left = array_pop($stack);
                $markedOnStack -= isset($marked[$left]) ? 1 : 0;
                // What a text starts, the shorter texts that start it start
                // as well.
                if ([] !== $stack && (isset($marked[$left]) || isset($startsMarked[$left]))) {
                    $under = $stack[\count($stack) - 1];
                    $startsMarked[$under] = $toMarked[$under] = true;
                }
            }

            if (null === $branch) {
                break;
            }

            if ([] !== $stack) {
                $any[$branch] = $any[$stack[\count($stack) - 1]] = true;
            }

            if ($markedOnStack > 0) {
                $toMarked[$branch] = true;
            }

            $stack[] = $branch;
            $markedOnStack += isset($marked[$branch]) ? 1 : 0;
        }

        return [$any, $toMarked];
    }

    private function starts(string $start, string $text): bool
    {
        $this->compared++;

        return str_starts_with($text, $start);
    }
}

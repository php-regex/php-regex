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

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Hir\CharSet;

/**
 * The first two branches of an alternation that overlap, found without
 * comparing every pair of them.
 *
 * Two branches overlap when the characters they can start with meet and
 * the text one starts with starts the text of the other, both texts read
 * in one case when either branch is caseless. A text read in two cases,
 * "a(?-i)B" under i, is read character by character: two characters meet
 * when they are the same, or one letter in two cases where either is read
 * caselessly. A branch that starts with no text overlaps every branch its
 * characters meet.
 *
 * Two texts read in their own case are told apart by what follows the
 * shorter one as well: in "a\d|aa" no input starts both branches, the "a"
 * the second goes on with being no digit. Where the texts are the same,
 * the branches go on apart when no byte follows both, "a\d|a[a-z]". A
 * branch read caselessly is compared by its text alone.
 *
 * Comparing each branch with every later one costs the square of their
 * number: seconds for a list of two thousand names. The pair that
 * comparison meets first is the pair of the first branch that overlaps
 * another, so that branch is looked for first, by what rules pairs out
 * together: the sets are walked through the alphabet, where only the
 * branches holding one character can overlap, and their texts are read in
 * byte order, where a text comes right before the texts it starts. A
 * branch found that way which what follows the texts tells apart from
 * every later one is set aside, and the search starts again without it.
 *
 * @internal
 */
final class BranchOverlaps
{
    private int $compared = 0;

    /**
     * The branches found to overlap no other, by branch.
     *
     * @var array<int, true>
     */
    private array $apart = [];

    /**
     * The bytes the branches asked about so far can go on with past their
     * text, by branch.
     *
     * @var array<int, ByteCharSet>
     */
    private array $followed = [];

    /**
     * @param list<string>                                 $texts    the text each branch starts with, "" for a branch that starts with no literal
     * @param list<string>                                 $folded   the same texts with their letters in one case
     * @param list<bool>                                   $caseless whether each branch reads a character of its text in either case
     * @param list<CharSet>                                $sets     the characters each branch can start with
     * @param list<list<array{string, string, bool}>>|null $units    each text character by character, as written, in one
     *                                                               case, and whether it is read in either case; null where
     *                                                               each text is read in one case throughout
     * @param (\Closure(int): ByteCharSet)|null            $follows  the bytes a branch can go on with past its text, by
     *                                                               branch, not known where it may end there: asked of
     *                                                               the branches whose texts start one another, once
     *                                                               each; null where the texts alone are compared
     */
    public function __construct(
        private readonly array $texts,
        private readonly array $folded,
        private readonly array $caseless,
        private readonly array $sets,
        private readonly ?array $units = null,
        private readonly ?\Closure $follows = null,
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
        // No branch before the first that overlaps another has a pair. A
        // branch found by its text and its set that overlaps no later one,
        // read character by character or by what follows its text,
        // overlaps none: the next is looked for without it.
        $total = \count($this->texts);
        while (($one = $this->firstOverlapping()) < $total) {
            for ($other = $one + 1; $other < $total; $other++) {
                if ($this->overlap($one, $other)) {
                    return [$one, $other];
                }
            }

            $this->apart[$one] = true;
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

        // A text read in two cases meets the other character by character.
        if (null !== $this->units && ($this->caseless[$one] || $this->caseless[$other])) {
            return self::meet($this->units[$one] ?? [], $this->units[$other] ?? []);
        }

        // A caseless branch meets the other in either case.
        $caseless = $this->caseless[$one] || $this->caseless[$other];
        [$first, $second] = $caseless
            ? [$this->folded[$one], $this->folded[$other]]
            : [$this->texts[$one], $this->texts[$other]];
        if (!str_starts_with($first, $second) && !str_starts_with($second, $first)) {
            return false;
        }

        return $caseless || null === $this->follows || $this->goOnAlike($one, $other, $this->follows);
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
     * Whether two branches read in their own case, the text of one
     * starting the text of the other, can read one byte more alike: the
     * branch whose text ends first goes on with the next byte of the other
     * text, or, where both texts end together, with a byte the other branch
     * goes on with too. Bytes that are not known meet every byte.
     *
     * @param \Closure(int): ByteCharSet $follows the bytes a branch can go on with past its text
     */
    private function goOnAlike(int $one, int $other, \Closure $follows): bool
    {
        [$short, $long] = str_starts_with($this->texts[$other], $this->texts[$one]) ? [$one, $other] : [$other, $one];
        $next = $this->texts[$long][\strlen($this->texts[$short])] ?? '';
        $this->followed[$short] ??= $follows($short);

        return $this->followed[$short]->intersects('' === $next ? $this->followed[$long] ??= $follows($long) : ByteCharSet::fromChar($next));
    }

    /**
     * The first branch that overlaps another by its set and its text, or
     * the number of branches when none does; the branches set apart are
     * left out.
     */
    private function firstOverlapping(): int
    {
        // The branches of each set: a list of names that start with one
        // letter is one set.
        $ranges = $members = $textless = [];
        foreach ($this->sets as $branch => $set) {
            if (isset($this->apart[$branch])) {
                continue;
            }

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

    /**
     * Whether one text starts the other, read character by character: the
     * same character, or one letter in two cases where either is read
     * caselessly.
     *
     * @param list<array{string, string, bool}> $one
     * @param list<array{string, string, bool}> $other
     */
    private static function meet(array $one, array $other): bool
    {
        foreach (array_slice($one, 0, \count($other)) as $at => [$written, $folded, $caseless]) {
            [$otherWritten, $otherFolded, $otherCaseless] = $other[$at];
            if ($written !== $otherWritten && (!($caseless || $otherCaseless) || $folded !== $otherFolded)) {
                return false;
            }
        }

        return true;
    }

    private function starts(string $start, string $text): bool
    {
        $this->compared++;

        return str_starts_with($text, $start);
    }
}

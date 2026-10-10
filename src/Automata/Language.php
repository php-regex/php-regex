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

namespace PHPRegex\Automata;

use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Parser\Hir\CharSet;

/**
 * The set of strings a pattern matches, read off its automaton: whether it
 * is finite, how many strings of each length it holds, each of them in
 * order, and the strings it rejects. Counts are exact, as decimal strings,
 * however large; strings come shortest first, then in code point order.
 *
 *     $language = (new LanguageSolver())->language('/^[A-Z]{2}\d{4}$/');
 *     $language->isFinite();   // true
 *     $language->size();       // "6760000"
 *
 * @phpstan-import-type RangeTransition from DfaState
 */
final class Language
{
    private const LIMB = 1_000_000_000;

    /**
     * @var array<string, array<int, true>> per side, inside or outside, the states that lead to acceptance
     */
    private array $live = [];

    /**
     * @var array<int, list<RangeTransition>> per state, its moves as [from, to, target], in order
     */
    private array $moves = [];

    /**
     * @var array<string, bool>
     */
    private array $reachesIn = [];

    private ?CharSet $universe = null;

    /**
     * @internal built by LanguageSolver::language()
     */
    public function __construct(private readonly Dfa $dfa, private readonly bool $unicode) {}

    /**
     * Whether no string at all belongs to the language.
     */
    public function isEmpty(): bool
    {
        return !isset($this->live()[$this->dfa->startState]);
    }

    /**
     * Whether the language holds finitely many strings: no loop on a way
     * from the start to an accepting state.
     */
    public function isFinite(): bool
    {
        return $this->isFiniteOn(false);
    }

    /**
     * The length of the shortest string, or null for the empty language.
     */
    public function minLength(): ?int
    {
        $distance = [$this->dfa->startState => 0];
        $queue = [$this->dfa->startState];
        for ($head = 0; $head < \count($queue); $head++) {
            $current = $queue[$head];
            if ($this->dfa->getState($current)->isAccepting) {
                return $distance[$current];
            }

            foreach ($this->movesOf($current) as [, , $target]) {
                if (!isset($distance[$target])) {
                    $distance[$target] = $distance[$current] + 1;
                    $queue[] = $target;
                }
            }
        }

        return null;
    }

    /**
     * The length of the longest string, or null when the language is empty
     * or infinite.
     */
    public function maxLength(): ?int
    {
        return $this->maxLengthOn(false);
    }

    /**
     * How many strings of the length the language holds, as a decimal
     * string.
     */
    public function countOfLength(int $length): string
    {
        return self::decimal($this->countsUpTo($length)[$length]);
    }

    /**
     * How many strings the language holds, as a decimal string; null when
     * it holds infinitely many.
     */
    public function size(): ?string
    {
        $max = $this->maxLength();
        if (null === $max) {
            return $this->isEmpty() ? '0' : null;
        }

        $total = [0];
        foreach ($this->countsUpTo($max) as $count) {
            $total = self::add($total, $count);
        }

        return self::decimal($total);
    }

    /**
     * Every string of the language, shortest first, then in code point
     * order; an infinite language never runs out.
     *
     * @return \Generator<int, string>
     */
    public function strings(): \Generator
    {
        $min = $this->minLength();
        if (null === $min) {
            return;
        }

        $max = $this->maxLength();
        for ($length = $min; null === $max || $length <= $max; $length++) {
            yield from $this->ofLength($this->dfa->startState, $length, '', false);
        }
    }

    /**
     * Every string outside the language, shortest first, then in code point
     * order: each is proven out by the automaton. They run out when only
     * finitely many strings are outside, none for a language of every string.
     *
     * @return \Generator<int, string>
     */
    public function nonMembers(): \Generator
    {
        if (!isset($this->live(true)[$this->dfa->startState])) {
            return;
        }

        $max = $this->maxLengthOn(true);
        for ($length = 0; null === $max || $length <= $max; $length++) {
            yield from $this->ofLength($this->dfa->startState, $length, '', true);
        }
    }

    private function isFiniteOn(bool $outside): bool
    {
        $live = $this->live($outside);
        $state = [];
        /** @var list<array{int, int}> $stack */
        $stack = [[$this->dfa->startState, 0]];
        if (!isset($live[$this->dfa->startState])) {
            return true;
        }

        // Depth first, a state on the current way seen again closes a loop.
        $state[$this->dfa->startState] = 1;
        while ([] !== $stack) {
            [$current, $next] = array_pop($stack);
            $moves = $this->movesOf($current);
            if ($next >= \count($moves)) {
                $state[$current] = 2;

                continue;
            }

            $stack[] = [$current, $next + 1];
            $target = $moves[$next][2];
            if (!isset($live[$target])) {
                continue;
            }

            if (1 === ($state[$target] ?? 0)) {
                return false;
            }

            if (!isset($state[$target])) {
                $state[$target] = 1;
                $stack[] = [$target, 0];
            }
        }

        return true;
    }

    /**
     * The longest string inside the language, or outside it.
     */
    private function maxLengthOn(bool $outside): ?int
    {
        $live = $this->live($outside);
        if (!isset($live[$this->dfa->startState]) || !$this->isFiniteOn($outside)) {
            return null;
        }

        $longest = [];
        $longestFrom = function (int $state) use (&$longestFrom, &$longest, $live, $outside): int {
            if (isset($longest[$state])) {
                return $longest[$state];
            }

            $best = $this->accepts($state, $outside) ? 0 : -1;
            foreach ($this->movesOf($state) as [, , $target]) {
                if (isset($live[$target])) {
                    $best = max($best, $longestFrom($target) + 1);
                }
            }

            return $longest[$state] = $best;
        };

        return $longestFrom($this->dfa->startState);
    }

    /**
     * @return \Generator<int, string>
     */
    private function ofLength(int $state, int $left, string $prefix, bool $outside): \Generator
    {
        if (0 === $left) {
            if ($this->accepts($state, $outside)) {
                yield $prefix;
            }

            return;
        }

        foreach ($this->movesOf($state) as [$from, $to, $target]) {
            if (!$this->reaches($target, $left - 1, $outside)) {
                continue;
            }

            for ($codePoint = $from; $codePoint <= $to; $codePoint++) {
                yield from $this->ofLength($target, $left - 1, $prefix.$this->character($codePoint), $outside);
            }
        }
    }

    /**
     * Whether a string of exactly that many more characters leads from the
     * state to acceptance.
     */
    private function reaches(int $state, int $left, bool $outside): bool
    {
        $key = ($outside ? 'o' : 'i').$state.':'.$left;
        if (isset($this->reachesIn[$key])) {
            return $this->reachesIn[$key];
        }

        if (0 === $left) {
            return $this->reachesIn[$key] = $this->accepts($state, $outside);
        }

        foreach ($this->movesOf($state) as [, , $target]) {
            if ($this->reaches($target, $left - 1, $outside)) {
                return $this->reachesIn[$key] = true;
            }
        }

        return $this->reachesIn[$key] = false;
    }

    /**
     * Whether the state accepts, or, with $outside, rejects: the automaton
     * reads every character of the alphabet, so its rejecting states accept
     * the strings outside the language.
     */
    private function accepts(int $state, bool $outside): bool
    {
        return $this->dfa->getState($state)->isAccepting !== $outside;
    }

    /**
     * The moves of a state as ordered ranges, over every character of the
     * alphabet.
     *
     * @return list<RangeTransition>
     */
    private function movesOf(int $state): array
    {
        if (!isset($this->moves[$state])) {
            // A state's single code points come first, as transitionFor()
            // reads them, and may repeat its ranges: a range keeps the rest.
            $moves = [];
            $dfaState = $this->dfa->getState($state);
            $listed = CharSet::empty();
            foreach ($dfaState->transitions as $codePoint => $target) {
                $moves[] = [$codePoint, $codePoint, $target];
                $listed = $listed->union(CharSet::single($codePoint));
            }
            foreach ($dfaState->ranges as [$from, $to, $target]) {
                foreach (CharSet::range($from, $to)->subtract($listed)->ranges as [$start, $end]) {
                    $moves[] = [$start, $end, $target];
                }
            }
            usort($moves, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $this->moves[$state] = $moves;
        }

        return $this->moves[$state];
    }

    /**
     * Per length up to the given one, how many strings of that length the
     * language holds, as base 10^9 limbs.
     *
     * @return list<list<int>>
     */
    private function countsUpTo(int $length): array
    {
        // ways[state]: how many strings of the current length lead from the
        // state to acceptance; counted backwards from length 0.
        $states = array_keys($this->dfa->states);
        $ways = [];
        foreach ($states as $state) {
            $ways[$state] = [$this->dfa->getState($state)->isAccepting ? 1 : 0];
        }

        $counts = [$ways[$this->dfa->startState]];
        for ($step = 1; $step <= $length; $step++) {
            $next = [];
            foreach ($states as $state) {
                $total = [0];
                foreach ($this->movesOf($state) as [$from, $to, $target]) {
                    $total = self::add($total, self::times($ways[$target], $this->characters($from, $to)));
                }
                $next[$state] = $total;
            }

            $ways = $next;
            $counts[] = $ways[$this->dfa->startState];
        }

        return $counts;
    }

    /**
     * How many characters of the alphabet the range holds: the surrogates are
     * none under /u.
     */
    private function characters(int $from, int $to): int
    {
        $this->universe ??= CharSet::universe($this->unicode);
        $count = 0;
        foreach (CharSet::range($from, $to)->intersect($this->universe)->ranges as [$start, $end]) {
            $count += $end - $start + 1;
        }

        return $count;
    }

    private function character(int $codePoint): string
    {
        return $this->unicode ? (string) mb_chr($codePoint, 'UTF-8') : \chr($codePoint);
    }

    /**
     * @return array<int, true>
     */
    private function live(bool $outside = false): array
    {
        $side = $outside ? 'outside' : 'inside';
        if (isset($this->live[$side])) {
            return $this->live[$side];
        }

        $predecessors = [];
        $live = [];
        $queue = [];
        foreach ($this->dfa->states as $id => $state) {
            foreach ($this->movesOf($id) as [, , $target]) {
                $predecessors[$target][$id] = true;
            }

            if ($state->isAccepting !== $outside) {
                $live[$id] = true;
                $queue[] = $id;
            }
        }

        while ([] !== $queue) {
            $target = array_pop($queue);
            foreach (array_keys($predecessors[$target] ?? []) as $source) {
                if (!isset($live[$source])) {
                    $live[$source] = true;
                    $queue[] = $source;
                }
            }
        }

        return $this->live[$side] = $live;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     *
     * @return list<int>
     */
    private static function add(array $a, array $b): array
    {
        $sum = [];
        $carry = 0;
        for ($index = 0, $count = max(\count($a), \count($b)); $index < $count; $index++) {
            $limb = ($a[$index] ?? 0) + ($b[$index] ?? 0) + $carry;
            $sum[] = $limb % self::LIMB;
            $carry = intdiv($limb, self::LIMB);
        }

        if ($carry > 0) {
            $sum[] = $carry;
        }

        return $sum;
    }

    /**
     * @param list<int> $a
     *
     * @return list<int>
     */
    private static function times(array $a, int $factor): array
    {
        $product = [];
        $carry = 0;
        foreach ($a as $limb) {
            $value = $limb * $factor + $carry;
            $product[] = $value % self::LIMB;
            $carry = intdiv($value, self::LIMB);
        }

        // A factor is at most the alphabet's size: the carry fits one limb.
        if ($carry > 0) {
            $product[] = $carry;
        }

        return $product;
    }

    /**
     * @param list<int> $limbs
     */
    private static function decimal(array $limbs): string
    {
        $text = (string) array_pop($limbs);
        foreach (array_reverse($limbs) as $limb) {
            $text .= str_pad((string) $limb, 9, '0', \STR_PAD_LEFT);
        }

        return $text;
    }
}

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

namespace PHPRegex\Automata\Model;

use PHPRegex\Automata\Support\WorkBudget;

/**
 * An NFA packed into flat arrays over the classes of an alphabet, the way a
 * product search reads it: the moves of state s are moveClass and
 * moveTarget from moveStart[s] to moveStart[s + 1], sorted by class, its
 * epsilon moves epsTarget from epsStart[s] to epsStart[s + 1]. States are
 * numbered 0 to stateCount - 1, whatever ids the NFA gave them.
 *
 * A state is "important" when it moves on a character or accepts: the
 * closures a search keeps hold those alone, as the epsilon-only states of
 * a Thompson construction only lead to them.
 *
 * @internal the solver builds the automata
 */
final class PackedNfa
{
    /**
     * The important states of each state's epsilon closure, sorted, by state.
     *
     * @var array<int, list<int>>
     */
    private array $closures = [];

    /**
     * @param list<int> $moveStart  where each state's moves start, stateCount + 1 entries
     * @param list<int> $moveClass  the class of each move
     * @param list<int> $moveTarget the target of each move
     * @param list<int> $epsStart   where each state's epsilon moves start, stateCount + 1 entries
     * @param list<int> $epsTarget  the target of each epsilon move
     * @param string    $accepting  one bit per state
     */
    public function __construct(
        public readonly Alphabet $alphabet,
        public readonly int $start,
        public readonly int $stateCount,
        public readonly array $moveStart,
        public readonly array $moveClass,
        public readonly array $moveTarget,
        public readonly array $epsStart,
        public readonly array $epsTarget,
        public readonly string $accepting,
        public readonly int $minCodePoint,
        public readonly int $maxCodePoint,
    ) {}

    /**
     * Two NFAs packed over one alphabet: the classes of each read alike on
     * the other, so a product moves both on the same class.
     *
     * @return array{self, self}
     */
    public static function packPair(Nfa $left, Nfa $right, ?WorkBudget $budget = null): array
    {
        $alphabet = self::alphabetOf([$left, $right], $budget);

        return [self::pack($left, $alphabet, $budget), self::pack($right, $alphabet, $budget)];
    }

    /**
     * The alphabet of the NFAs, from the least first code point to the
     * greatest last one, cut where a character set starts or stops, and
     * the ranges grouped into classes: the ranges every set holds alike.
     *
     * @param list<Nfa> $nfas
     */
    public static function alphabetOf(array $nfas, ?WorkBudget $budget = null): Alphabet
    {
        $min = \PHP_INT_MAX;
        $max = 0;
        $boundaries = [];
        $sets = [];
        foreach ($nfas as $nfa) {
            $min = min($min, $nfa->minCodePoint);
            $max = max($max, $nfa->maxCodePoint);
            foreach ($nfa->states as $state) {
                foreach ($state->transitions as $transition) {
                    $key = $transition->charSet->key();
                    if (isset($sets[$key])) {
                        continue;
                    }

                    $sets[$key] = $transition->charSet->ranges;
                    foreach ($transition->charSet->ranges as [$start, $end]) {
                        $boundaries[$start] = true;
                        $boundaries[$end + 1] = true;
                    }
                }
            }
        }

        $boundaries[$min] = true;
        $boundaries[$max + 1] = true;
        $points = array_keys($boundaries);
        sort($points, \SORT_NUMERIC);
        $starts = [];
        foreach ($points as $point) {
            if ($point >= $min && $point <= $max) {
                $starts[] = $point;
            }
        }

        /** @var array<int, list<int>> $holders the sets holding each range */
        $holders = [];
        $number = 0;
        foreach ($sets as $ranges) {
            foreach ($ranges as [$start, $end]) {
                $from = self::indexOf($start, $starts);
                $to = self::indexOf(min($end, $max), $starts);
                for ($index = $from; $index <= $to; $index++) {
                    $budget?->consume();
                    $holders[$index][] = $number;
                }
            }

            $number++;
        }

        $ranges = [];
        $classOfRange = [];
        $classes = [];
        foreach ($starts as $index => $start) {
            $ranges[] = [$start, ($starts[$index + 1] ?? $max + 1) - 1];
            $signature = implode(',', $holders[$index] ?? []);
            $classes[$signature] ??= \count($classes);
            $classOfRange[] = $classes[$signature];
        }

        return Alphabet::fromRanges($ranges, $classOfRange);
    }

    /**
     * The NFA over the classes of an alphabet that cuts every one of its
     * character sets at a range boundary.
     */
    public static function pack(Nfa $nfa, Alphabet $alphabet, ?WorkBudget $budget = null): self
    {
        $dense = [];
        foreach ($nfa->states as $id => $_) {
            $dense[$id] = \count($dense);
        }

        /** @var array<string, list<int>> $classesOfSet */
        $classesOfSet = [];
        $moveStart = [];
        $moveClass = [];
        $moveTarget = [];
        $epsStart = [];
        $epsTarget = [];
        $accepting = str_repeat("\0", intdiv(\count($dense) + 7, 8));
        foreach ($nfa->states as $id => $state) {
            $moveStart[] = \count($moveClass);
            $epsStart[] = \count($epsTarget);
            if ($state->isAccepting) {
                $at = $dense[$id];
                $accepting[$at >> 3] = \chr(\ord($accepting[$at >> 3]) | (1 << ($at & 7)));
            }

            $moves = [];
            foreach ($state->transitions as $transition) {
                $key = $transition->charSet->key();
                $classesOfSet[$key] ??= self::classesOf($transition->charSet->ranges, $alphabet);
                foreach ($classesOfSet[$key] as $class) {
                    $budget?->consume();
                    $moves[$class][$dense[$transition->target]] = true;
                }
            }

            ksort($moves, \SORT_NUMERIC);
            foreach ($moves as $class => $targets) {
                foreach ($targets as $target => $_) {
                    $moveClass[] = $class;
                    $moveTarget[] = $target;
                }
            }

            foreach ($state->epsilonTransitions as $target) {
                $epsTarget[] = $dense[$target];
            }
        }

        $moveStart[] = \count($moveClass);
        $epsStart[] = \count($epsTarget);

        return new self($alphabet, $dense[$nfa->startState], \count($dense), $moveStart, $moveClass, $moveTarget, $epsStart, $epsTarget, $accepting, $nfa->minCodePoint, $nfa->maxCodePoint);
    }

    public function isAccepting(int $state): bool
    {
        return 0 !== (\ord($this->accepting[$state >> 3]) & (1 << ($state & 7)));
    }

    /**
     * The important states of the state's epsilon closure, sorted.
     *
     * @return list<int>
     */
    public function closure(int $state): array
    {
        if (isset($this->closures[$state])) {
            return $this->closures[$state];
        }

        $seen = [$state => true];
        $pending = [$state];
        $important = [];
        while ([] !== $pending) {
            $current = array_pop($pending);
            if ($this->moveStart[$current] !== $this->moveStart[$current + 1] || $this->isAccepting($current)) {
                $important[] = $current;
            }

            for ($at = $this->epsStart[$current], $end = $this->epsStart[$current + 1]; $at < $end; $at++) {
                $target = $this->epsTarget[$at];
                if (!isset($seen[$target])) {
                    $seen[$target] = true;
                    $pending[] = $target;
                }
            }
        }

        sort($important, \SORT_NUMERIC);

        return $this->closures[$state] = $important;
    }

    /**
     * The important states the state reaches on the class, through the
     * epsilon moves after it, as keys.
     *
     * @return array<int, true>
     */
    public function step(int $state, int $class): array
    {
        // The moves are sorted by class: the first of the class is found by
        // halving, so a state read on each of many classes is not scanned
        // from its first move each time.
        $low = $this->moveStart[$state];
        $high = $this->moveStart[$state + 1];
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($this->moveClass[$mid] < $class) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        $reached = [];
        for ($at = $low, $end = $this->moveStart[$state + 1]; $at < $end && $this->moveClass[$at] === $class; $at++) {
            foreach ($this->closure($this->moveTarget[$at]) as $target) {
                $reached[$target] = true;
            }
        }

        return $reached;
    }

    /**
     * The classes the state moves on, each once, ascending.
     *
     * @return list<int>
     */
    public function classesFrom(int $state): array
    {
        $classes = [];
        $last = -1;
        for ($at = $this->moveStart[$state], $end = $this->moveStart[$state + 1]; $at < $end; $at++) {
            if ($this->moveClass[$at] !== $last) {
                $last = $classes[] = $this->moveClass[$at];
            }
        }

        return $classes;
    }

    /**
     * Every move written, the measure of the packed automaton.
     */
    public function moveCount(): int
    {
        return \count($this->moveClass);
    }

    /**
     * @param list<array{int, int}> $ranges
     *
     * @return list<int>
     */
    private static function classesOf(array $ranges, Alphabet $alphabet): array
    {
        $classes = [];
        foreach ($ranges as [$start, $end]) {
            // The alphabet runs without a hole from its first range to its
            // last: a set starting before it starts with it.
            $index = $alphabet->rangeOf(max($start, $alphabet->rangeStart[0] ?? $start));
            if (null === $index) {
                continue;
            }

            for ($count = $alphabet->rangeCount(); $index < $count && $alphabet->rangeStart[$index] <= $end; $index++) {
                $classes[$alphabet->classOfRange[$index]] = true;
            }
        }

        $classes = array_keys($classes);
        sort($classes, \SORT_NUMERIC);

        return $classes;
    }

    /**
     * The index of the last start at or below the code point.
     *
     * @param list<int> $starts
     */
    private static function indexOf(int $codePoint, array $starts): int
    {
        $low = 0;
        $high = \count($starts) - 1;
        $result = 0;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            if ($starts[$mid] <= $codePoint) {
                $result = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $result;
    }
}

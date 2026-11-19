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

/**
 * The alphabet of an automaton cut into ranges, and the ranges grouped into
 * classes: the characters of one class move every state of the automaton
 * alike. A Unicode class splits the alphabet into some two thousand ranges
 * and a handful of classes, so a state keeps one target per class, and the
 * ranges are held once, here, for every state.
 *
 * A class is numbered in the order of its first range; its representative
 * is the first character of that range, the smallest it holds.
 *
 * @internal the solver builds the automata
 */
final class Alphabet
{
    /**
     * @param list<int> $rangeStart     the first character of each range, ascending
     * @param list<int> $rangeEnd       the last character of each range
     * @param list<int> $classOfRange   the class of each range
     * @param list<int> $representative the smallest character of each class
     */
    public function __construct(
        public readonly array $rangeStart,
        public readonly array $rangeEnd,
        public readonly array $classOfRange,
        public readonly array $representative,
        public readonly int $classCount,
    ) {}

    /**
     * The alphabet of the ranges given, ascending and disjoint, and the
     * class of each.
     *
     * @param list<array{int, int}> $ranges
     * @param list<int>             $classOfRange
     */
    public static function fromRanges(array $ranges, array $classOfRange): self
    {
        $starts = [];
        $ends = [];
        $representative = [];
        foreach ($ranges as $index => [$start, $end]) {
            $starts[] = $start;
            $ends[] = $end;
            $class = $classOfRange[$index];
            $representative[$class] ??= $start;
        }

        ksort($representative, \SORT_NUMERIC);

        return new self($starts, $ends, $classOfRange, array_values($representative), \count($representative));
    }

    public function rangeCount(): int
    {
        return \count($this->rangeStart);
    }

    /**
     * The index of the range holding the character, or null when none
     * does: a character outside the alphabet, or in a hole of it.
     */
    public function rangeOf(int $codePoint): ?int
    {
        $low = 0;
        $high = \count($this->rangeStart) - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            if ($codePoint < $this->rangeStart[$mid]) {
                $high = $mid - 1;
            } elseif ($codePoint > $this->rangeEnd[$mid]) {
                $low = $mid + 1;
            } else {
                return $mid;
            }
        }

        return null;
    }

    /**
     * The class of the character, or null outside the alphabet.
     */
    public function classOf(int $codePoint): ?int
    {
        $range = $this->rangeOf($codePoint);

        return null === $range ? null : $this->classOfRange[$range];
    }
}

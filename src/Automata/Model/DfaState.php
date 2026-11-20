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
 * Immutable DFA state, as `Dfa` hands it out: read, never built by a caller.
 *
 * Its moves are ranges, sorted and disjoint, each read as its first code
 * point (or byte), its last, inclusive, and the id of the state it leads to.
 * A state the solver determinizes holds one target per class of its
 * automaton's alphabet: a Unicode class splits the alphabet into hundreds of
 * ranges and a handful of classes, and the ranges are held once, by the
 * alphabet. Other states hold their ranges as three ints a range in one
 * flat list.
 *
 * @phpstan-type RangeTransition array{0: int, 1: int, 2: int}
 */
final class DfaState
{
    /**
     * @internal the solver builds the automata
     *
     * @param array<int, int> $transitions  the state each byte leads to, in an automaton over bytes; empty
     *                                      over code points, where the ranges say it all
     * @param list<int>       $ranges       three ints a range: its first code point, its last and its target;
     *                                      empty when the alphabet and the class targets say it
     * @param list<int>       $classTargets the state each class of the alphabet leads to
     */
    public function __construct(
        public readonly int $id,
        private readonly array $transitions,
        public readonly bool $isAccepting,
        private readonly array $ranges = [],
        private readonly ?Alphabet $alphabet = null,
        private readonly array $classTargets = [],
    ) {}

    /**
     * The id of the state the code point (or byte) leads to, or null when
     * no range holds it.
     */
    public function transitionFor(int $codePoint): ?int
    {
        if (isset($this->transitions[$codePoint])) {
            return $this->transitions[$codePoint];
        }

        if (null !== $this->alphabet) {
            $class = $this->alphabet->classOf($codePoint);

            return null === $class ? null : ($this->classTargets[$class] ?? null);
        }

        $ranges = $this->ranges;
        $low = 0;
        $high = intdiv(\count($ranges), 3) - 1;

        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $at = 3 * $mid;

            if ($codePoint < $ranges[$at]) {
                $high = $mid - 1;
            } elseif ($codePoint > $ranges[$at + 1]) {
                $low = $mid + 1;
            } else {
                return $ranges[$at + 2];
            }
        }

        return null;
    }

    /**
     * How many ranges the state moves on.
     */
    public function rangeCount(): int
    {
        if (null !== $this->alphabet) {
            return $this->alphabet->rangeCount();
        }

        return intdiv(\count($this->ranges), 3);
    }

    /**
     * Each range as its first code point, its last and its target, in
     * order. The list is built on each call.
     *
     * @return list<RangeTransition>
     */
    public function ranges(): array
    {
        $ranges = [];
        $packed = $this->packedRanges();
        for ($at = 0, $count = \count($packed); $at < $count; $at += 3) {
            $ranges[] = [$packed[$at], $packed[$at + 1], $packed[$at + 2]];
        }

        return $ranges;
    }

    /**
     * The ranges as held: three ints a range, its first code point, its
     * last and its target.
     *
     * @internal the solver reads them without building a list per range
     *
     * @return list<int>
     */
    public function packedRanges(): array
    {
        if (null === $this->alphabet) {
            return $this->ranges;
        }

        $alphabet = $this->alphabet;
        $packed = [];
        foreach ($alphabet->rangeStart as $index => $start) {
            $packed[] = $start;
            $packed[] = $alphabet->rangeEnd[$index];
            $packed[] = $this->classTargets[$alphabet->classOfRange[$index]];
        }

        return $packed;
    }

    /**
     * The alphabet whose classes the state moves on, null when the state
     * holds its ranges itself.
     *
     * @internal the solver reads the moves by class
     */
    public function alphabet(): ?Alphabet
    {
        return $this->alphabet;
    }

    /**
     * The state each class of the alphabet leads to, in class order; empty
     * when the state holds its ranges itself.
     *
     * @internal the solver reads the moves by class
     *
     * @return list<int>
     */
    public function classTargets(): array
    {
        return $this->classTargets;
    }

    /**
     * The state each byte leads to, in an automaton over bytes; empty over
     * code points.
     *
     * @internal the minimizers read it
     *
     * @return array<int, int>
     */
    public function byteTransitions(): array
    {
        return $this->transitions;
    }
}

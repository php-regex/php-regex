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
 * Immutable DFA container.
 *
 * An alphabet range is a code point range, inclusive on both ends.
 *
 * @phpstan-type AlphabetRange array{0: int, 1: int}
 */
final class Dfa
{
    /**
     * @internal the solver builds the automata
     *
     * @param array<int, DfaState>      $states
     * @param array<int, AlphabetRange> $alphabetRanges
     */
    public function __construct(
        public readonly int $startState,
        public readonly array $states,
        public readonly array $alphabetRanges = [],
        public readonly int $minCodePoint = 0,
        public readonly int $maxCodePoint = 255,
    ) {}

    public function getState(int $stateId): DfaState
    {
        return $this->states[$stateId];
    }
}

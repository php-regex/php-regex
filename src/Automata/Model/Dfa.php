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
final readonly class Dfa
{
    /**
     * @param array<int, DfaState>      $states
     * @param array<int, AlphabetRange> $alphabetRanges
     */
    public function __construct(
        public int $startState,
        public array $states,
        public array $alphabetRanges = [],
        public int $minCodePoint = 0,
        public int $maxCodePoint = 255,
    ) {}

    public function getState(int $stateId): DfaState
    {
        return $this->states[$stateId];
    }
}

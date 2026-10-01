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

use PHPRegex\Automata\Alphabet\CharSet;

/**
 * Immutable DFA container.
 */
final readonly class Dfa
{
    /**
     * @param array<int, DfaState>            $states
     * @param array<int, array{0:int, 1:int}> $alphabetRanges
     */
    public function __construct(
        public int $startState,
        public array $states,
        public array $alphabetRanges = [],
        public int $minCodePoint = CharSet::MIN_CODEPOINT,
        public int $maxCodePoint = CharSet::MAX_CODEPOINT,
    ) {}

    public function getState(int $stateId): DfaState
    {
        return $this->states[$stateId];
    }
}

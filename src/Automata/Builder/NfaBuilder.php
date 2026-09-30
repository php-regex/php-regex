<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Automata\Builder;

use RegexParser\Automata\Alphabet\CharSet;
use RegexParser\Automata\Model\Nfa;
use RegexParser\Automata\Model\NfaFragment;
use RegexParser\Automata\Model\NfaState;
use RegexParser\Automata\Model\NfaTransition;
use RegexParser\Exception\ComplexityException;

/**
 * Mutable builder for NFA graphs.
 *
 * @internal
 */
final class NfaBuilder
{
    /**
     * @var array<int, array<NfaTransition>>
     */
    private array $transitions = [];

    /**
     * @var array<int, array<int>>
     */
    private array $epsilonTransitions = [];

    /**
     * @var array<int, bool>
     */
    private array $acceptingStates = [];

    private int $nextStateId = 0;

    public function __construct(
        private readonly int $maxStates,
        private readonly int $minCodePoint = CharSet::MIN_CODEPOINT,
        private readonly int $maxCodePoint = CharSet::MAX_CODEPOINT,
    ) {}

    /**
     * @throws ComplexityException
     */
    public function createState(bool $accepting = false): int
    {
        $stateId = $this->nextStateId++;
        if ($stateId >= $this->maxStates) {
            throw new ComplexityException(
                \sprintf('NFA state limit exceeded (%d).', $this->maxStates),
            );
        }

        $this->transitions[$stateId] = [];
        $this->epsilonTransitions[$stateId] = [];
        if ($accepting) {
            $this->acceptingStates[$stateId] = true;
        }

        return $stateId;
    }

    public function addTransition(int $from, CharSet $charSet, int $to): void
    {
        if ($charSet->isEmpty()) {
            return;
        }

        $this->transitions[$from][] = new NfaTransition($charSet, $to);
    }

    public function addEpsilon(int $from, int $to): void
    {
        $this->epsilonTransitions[$from][] = $to;
    }

    public function markAccepting(int $state): void
    {
        $this->acceptingStates[$state] = true;
    }

    public function build(NfaFragment $fragment): Nfa
    {
        foreach ($fragment->acceptStates as $state) {
            $this->markAccepting($state);
        }

        $states = [];
        foreach ($this->transitions as $stateId => $transitions) {
            $states[$stateId] = new NfaState(
                $stateId,
                $transitions,
                $this->epsilonTransitions[$stateId] ?? [],
                $this->acceptingStates[$stateId] ?? false,
            );
        }

        return new Nfa($fragment->startState, $states, $this->minCodePoint, $this->maxCodePoint);
    }
}

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

namespace PHPRegex\Automata\Builder;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaFragment;
use PHPRegex\Automata\Model\NfaState;
use PHPRegex\Automata\Model\NfaTransition;
use PHPRegex\Parser\Hir\CharSet;

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
        private readonly int $minCodePoint = 0,
        private readonly int $maxCodePoint = 255,
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

    /**
     * The transitions leaving a state, for analyses that run while the
     * automaton is still being built.
     *
     * @return array<NfaTransition>
     */
    public function transitionsOf(int $state): array
    {
        return $this->transitions[$state] ?? [];
    }

    /**
     * @return array<int>
     */
    public function epsilonTransitionsOf(int $state): array
    {
        return $this->epsilonTransitions[$state] ?? [];
    }

    public function isAccepting(int $state): bool
    {
        return isset($this->acceptingStates[$state]);
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

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

namespace PHPRegex\Automata\Solver;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\PackedNfa;
use PHPRegex\Automata\Support\WorkBudget;

/**
 * The subset construction of a packed NFA, one state at a time: a state is
 * built the first time a search reaches it, and each move the first time it
 * is read, then kept. A state is the set of important NFA states it stands
 * for, keyed by their packed numbers; the empty set is the dead state.
 *
 * @internal
 */
final class LazyDfa
{
    /**
     * @var array<string, int> each state's number, by the key of its set
     */
    private array $numbers = [];

    /**
     * @var list<list<int>> each state's NFA states
     */
    private array $sets = [];

    /**
     * @var list<bool>
     */
    private array $accepting = [];

    /**
     * @var array<int, array<int, int>> the moves read so far, by state and class
     */
    private array $moves = [];

    public readonly int $start;

    /**
     * @throws ComplexityException
     */
    public function __construct(
        public readonly PackedNfa $nfa,
        private readonly int $maxStates,
        private readonly ?WorkBudget $budget = null,
    ) {
        $this->start = $this->number($nfa->closure($nfa->start));
    }

    public function isAccepting(int $state): bool
    {
        return $this->accepting[$state];
    }

    /**
     * Whether the state matches nothing more, whatever follows.
     */
    public function isDead(int $state): bool
    {
        return [] === $this->sets[$state];
    }

    /**
     * The state the class leads to.
     *
     * @throws ComplexityException
     */
    public function next(int $state, int $class): int
    {
        if (isset($this->moves[$state][$class])) {
            return $this->moves[$state][$class];
        }

        $this->budget?->consume();
        $reached = [];
        foreach ($this->sets[$state] as $nfaState) {
            $reached += $this->nfa->step($nfaState, $class);
        }

        $targets = array_keys($reached);
        sort($targets, \SORT_NUMERIC);

        return $this->moves[$state][$class] = $this->number($targets);
    }

    /**
     * The states built so far.
     */
    public function stateCount(): int
    {
        return \count($this->sets);
    }

    /**
     * @param list<int> $set
     *
     * @throws ComplexityException
     */
    private function number(array $set): int
    {
        $key = [] === $set ? '' : pack('N*', ...$set);
        if (isset($this->numbers[$key])) {
            return $this->numbers[$key];
        }

        $number = \count($this->sets);
        if ($number >= $this->maxStates) {
            throw new ComplexityException(\sprintf('DFA state limit exceeded (%d).', $this->maxStates), diagnostic: ['phase' => 'determinization', 'limit' => $this->maxStates]);
        }

        $accepting = false;
        foreach ($set as $nfaState) {
            if ($this->nfa->isAccepting($nfaState)) {
                $accepting = true;

                break;
            }
        }

        $this->numbers[$key] = $number;
        $this->sets[] = $set;
        $this->accepting[] = $accepting;

        return $number;
    }
}

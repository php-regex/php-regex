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

namespace PHPRegex\Automata\Minimization;

use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Support\WorkBudget;

/**
 * Hopcroft's DFA minimization using a worklist and inverse transitions.
 *
 * @internal
 */
final class HopcroftWorklist implements MinimizationAlgorithmInterface, WorkBudgetAwareMinimizationAlgorithmInterface
{
    private ?WorkBudget $budget = null;

    public function setWorkBudget(?WorkBudget $budget): void
    {
        $this->budget = $budget;
    }

    /**
     * @param array<int> $alphabet
     */
    public function minimize(Dfa $dfa, array $alphabet): Dfa
    {
        $states = $dfa->states;

        if (\count($states) <= 1) {
            return $dfa;
        }

        $accepting = [];
        $nonAccepting = [];

        foreach ($states as $stateId => $state) {
            if ($state->isAccepting) {
                $accepting[] = $stateId;

                continue;
            }

            $nonAccepting[] = $stateId;
        }

        /** @var array<int, array<int, int>> $partitions */
        $partitions = [];
        if ([] !== $accepting) {
            $partitions[] = $accepting;
        }
        if ([] !== $nonAccepting) {
            $partitions[] = $nonAccepting;
        }

        if (\count($partitions) <= 1) {
            $stateToGroup = $this->buildStateToGroup($partitions);

            return $this->buildMinimizedDfa($dfa, $partitions, $stateToGroup);
        }

        $stateToGroup = $this->buildStateToGroup($partitions);
        $inverse = $this->buildInverseTransitions($dfa, $alphabet);

        $worklist = [];
        $inWorklist = [];

        $firstGroupSize = \count($partitions[0]);
        $secondGroupSize = \count($partitions[1]);
        $initial = $firstGroupSize <= $secondGroupSize ? 0 : 1;

        $worklist[] = $initial;
        $inWorklist[$initial] = true;

        while ([] !== $worklist) {
            $currentGroup = \array_pop($worklist);
            unset($inWorklist[$currentGroup]);

            $currentStates = $partitions[$currentGroup] ?? [];
            if ([] === $currentStates) {
                continue;
            }

            foreach ($alphabet as $symbol) {
                if (!isset($inverse[$symbol])) {
                    continue;
                }

                $predecessors = [];

                foreach ($currentStates as $targetState) {
                    foreach ($inverse[$symbol][$targetState] ?? [] as $sourceState) {
                        if (null !== $this->budget) {
                            $this->budget->consume();
                        }
                        $predecessors[$sourceState] = true;
                    }
                }

                if ([] === $predecessors) {
                    continue;
                }

                $affected = [];
                foreach ($predecessors as $stateId => $_) {
                    $groupId = $stateToGroup[$stateId];
                    $affected[$groupId][] = $stateId;
                }

                foreach ($affected as $groupId => $groupStatesInPredecessors) {
                    $group = $partitions[$groupId];

                    if (\count($groupStatesInPredecessors) === \count($group)) {
                        continue;
                    }

                    $inPredecessors = \array_fill_keys($groupStatesInPredecessors, true);
                    $left = [];
                    $right = [];

                    foreach ($group as $stateId) {
                        if (isset($inPredecessors[$stateId])) {
                            $left[] = $stateId;
                        } else {
                            $right[] = $stateId;
                        }
                    }

                    $partitions[$groupId] = $left;
                    $newGroupId = \count($partitions);
                    $partitions[$newGroupId] = $right;

                    foreach ($right as $stateId) {
                        $stateToGroup[$stateId] = $newGroupId;
                    }

                    if (isset($inWorklist[$groupId])) {
                        $worklist[] = $newGroupId;
                        $inWorklist[$newGroupId] = true;
                    } else {
                        $smaller = \count($left) <= \count($right) ? $groupId : $newGroupId;
                        if (!isset($inWorklist[$smaller])) {
                            $worklist[] = $smaller;
                            $inWorklist[$smaller] = true;
                        }
                    }
                }
            }
        }

        return $this->buildMinimizedDfa($dfa, $partitions, $stateToGroup);
    }

    /**
     * @param array<int, array<int, int>> $partitions
     *
     * @return array<int, int>
     */
    private function buildStateToGroup(array $partitions): array
    {
        $lookup = [];

        foreach ($partitions as $groupId => $states) {
            foreach ($states as $stateId) {
                $lookup[$stateId] = $groupId;
            }
        }

        return $lookup;
    }

    /**
     * @param array<int> $alphabet
     *
     * @return array<int, array<int, array<int>>>
     */
    private function buildInverseTransitions(Dfa $dfa, array $alphabet): array
    {
        $alphabetSet = \array_fill_keys($alphabet, true);
        $inverse = [];

        foreach ($dfa->states as $stateId => $state) {
            if ([] !== $state->ranges) {
                foreach ($state->ranges as [$start, $_end, $target]) {
                    if (!isset($alphabetSet[$start])) {
                        continue;
                    }

                    if (null !== $this->budget) {
                        $this->budget->consume();
                    }
                    $inverse[$start][$target][] = $stateId;
                }
            } else {
                foreach ($state->transitions as $symbol => $target) {
                    $symbol = (int) $symbol;
                    if (!isset($alphabetSet[$symbol])) {
                        continue;
                    }

                    if (null !== $this->budget) {
                        $this->budget->consume();
                    }
                    $inverse[$symbol][$target][] = $stateId;
                }
            }
        }

        return $inverse;
    }

    /**
     * @param array<int, array<int, int>> $partitions
     * @param array<int, int>             $stateToGroup
     */
    private function buildMinimizedDfa(Dfa $dfa, array $partitions, array $stateToGroup): Dfa
    {
        $newStates = [];

        foreach ($partitions as $newId => $group) {
            $representative = $dfa->states[$group[0]];
            $transitions = [];
            $ranges = [];

            foreach ($representative->transitions as $symbol => $target) {
                $transitions[(int) $symbol] = $stateToGroup[$target];
            }

            if ([] !== $representative->ranges) {
                foreach ($representative->ranges as [$start, $end, $target]) {
                    $ranges[] = [$start, $end, $stateToGroup[$target]];
                }
            } else {
                foreach ($transitions as $symbol => $target) {
                    $ranges[] = [$symbol, $symbol, $target];
                }
            }

            $newStates[$newId] = new DfaState($newId, $transitions, $representative->isAccepting, $ranges);
        }

        $startState = $stateToGroup[$dfa->startState];

        return new Dfa($startState, $newStates, $dfa->alphabetRanges, $dfa->minCodePoint, $dfa->maxCodePoint);
    }
}

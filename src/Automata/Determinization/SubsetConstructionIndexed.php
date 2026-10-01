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

namespace PHPRegex\Automata\Determinization;

use PHPRegex\Automata\Alphabet\CharSet;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Support\WorkBudget;

/**
 * Subset construction with indexed transition ranges for faster moves.
 *
 * @internal
 */
final class SubsetConstructionIndexed implements DeterminizationAlgorithmInterface, WorkBudgetAwareDeterminizationAlgorithmInterface
{
    private ?WorkBudget $budget = null;

    public function setWorkBudget(?WorkBudget $budget): void
    {
        $this->budget = $budget;
    }

    /**
     * @param array<int, array{0:int, 1:int}> $alphabetRanges
     *
     * @throws ComplexityException
     */
    public function determinize(Nfa $nfa, SolverOptions $options, array $alphabetRanges): Dfa
    {
        $startSet = $this->epsilonClosure([$nfa->startState], $nfa);
        $nfaTransitions = $this->countTransitions($nfa);
        $alphabetSize = \count($alphabetRanges);

        $rangeStarts = $this->rangeStarts($alphabetRanges);
        $indexedTransitions = $this->indexTransitions($nfa, $rangeStarts);

        /** @var array<string, int> $stateMap */
        $stateMap = [];
        /** @var array<int, array<int>> $stateSets */
        $stateSets = [];

        /** @var \SplQueue<int> $queue */
        $queue = new \SplQueue();

        $startKey = $this->setKey($startSet);
        $stateMap[$startKey] = 0;
        $stateSets[0] = $startSet;
        $queue->enqueue(0);
        if (null !== $this->budget) {
            $this->budget->updateStats(\count($stateMap), $nfaTransitions, $alphabetSize);
        }

        /** @var array<int, array{transitions: array<int, int>, ranges: array<int, array{0:int, 1:int, 2:int}>}> $transitions */
        $transitions = [];
        /** @var array<int, bool> $accepting */
        $accepting = [];

        while (!$queue->isEmpty()) {
            /** @var int $dfaId */
            $dfaId = $queue->dequeue();
            $currentSet = $stateSets[$dfaId];
            $accepting[$dfaId] = $this->isAccepting($currentSet, $nfa);

            /** @var array<int, int> $stateTransitions */
            $stateTransitions = [];
            /** @var array<int, array{0:int, 1:int, 2:int}> $stateRanges */
            $stateRanges = [];

            /** @var array<int, array<int, true>> $targetsByRange */
            $targetsByRange = [];
            foreach ($currentSet as $stateId) {
                foreach ($indexedTransitions[$stateId] ?? [] as $rangeIndex => $targets) {
                    foreach ($targets as $target => $_) {
                        if (null !== $this->budget) {
                            $this->budget->consume();
                        }
                        $targetsByRange[$rangeIndex][$target] = true;
                    }
                }
            }

            foreach ($alphabetRanges as $rangeIndex => [$start, $end]) {
                $targets = $targetsByRange[$rangeIndex] ?? [];
                /** @var array<int> $moveSet */
                $moveSet = [] === $targets ? [] : \array_keys($targets);
                if ([] !== $moveSet) {
                    \sort($moveSet, \SORT_NUMERIC);
                }

                $targetSet = $this->epsilonClosure($moveSet, $nfa);
                $targetKey = $this->setKey($targetSet);

                if (!isset($stateMap[$targetKey])) {
                    $newId = \count($stateMap);
                    if ($newId >= $options->maxDfaStates) {
                        throw new ComplexityException(
                            \sprintf('DFA state limit exceeded (%d).', $options->maxDfaStates),
                        );
                    }
                    $stateMap[$targetKey] = $newId;
                    $stateSets[$newId] = $targetSet;
                    $queue->enqueue($newId);
                    if (null !== $this->budget) {
                        $this->budget->updateStats(\count($stateMap), $nfaTransitions, $alphabetSize);
                    }
                }

                $targetId = $stateMap[$targetKey];
                if ($nfa->maxCodePoint <= CharSet::MAX_CODEPOINT) {
                    for ($char = $start; $char <= $end; $char++) {
                        $stateTransitions[$char] = $targetId;
                    }
                } else {
                    $stateTransitions[$start] = $targetId;
                }
                $stateRanges[] = [$start, $end, $targetId];
            }

            $transitions[$dfaId] = [
                'transitions' => $stateTransitions,
                'ranges' => $stateRanges,
            ];
        }

        /** @var array<int, DfaState> $states */
        $states = [];
        foreach ($transitions as $stateId => $stateTransitions) {
            $states[$stateId] = new DfaState(
                $stateId,
                $stateTransitions['transitions'],
                $accepting[$stateId] ?? false,
                $stateTransitions['ranges'],
            );
        }

        return new Dfa(0, $states, $alphabetRanges, $nfa->minCodePoint, $nfa->maxCodePoint);
    }

    /**
     * @param array<int> $stateIds
     *
     * @return array<int>
     */
    private function epsilonClosure(array $stateIds, Nfa $nfa): array
    {
        if ([] === $stateIds) {
            return [];
        }

        /** @var \SplQueue<int> $queue */
        $queue = new \SplQueue();
        /** @var array<int, bool> $seen */
        $seen = [];

        foreach ($stateIds as $stateId) {
            $queue->enqueue($stateId);
            $seen[$stateId] = true;
        }

        while (!$queue->isEmpty()) {
            /** @var int $stateId */
            $stateId = $queue->dequeue();
            $state = $nfa->getState($stateId);
            foreach ($state->epsilonTransitions as $target) {
                if (!isset($seen[$target])) {
                    $seen[$target] = true;
                    $queue->enqueue($target);
                }
            }
        }

        /** @var array<int> $result */
        $result = \array_keys($seen);
        \sort($result, \SORT_NUMERIC);

        return $result;
    }

    /**
     * @param array<int> $stateIds
     */
    private function isAccepting(array $stateIds, Nfa $nfa): bool
    {
        foreach ($stateIds as $stateId) {
            /** @var int $stateId */
            if ($nfa->getState($stateId)->isAccepting) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{0:int, 1:int}> $alphabetRanges
     *
     * @return array<int>
     */
    private function rangeStarts(array $alphabetRanges): array
    {
        $starts = [];
        foreach ($alphabetRanges as [$start]) {
            $starts[] = $start;
        }

        return $starts;
    }

    /**
     * @param array<int> $rangeStarts
     *
     * @return array<int, array<int, array<int, true>>>
     */
    private function indexTransitions(Nfa $nfa, array $rangeStarts): array
    {
        $indexed = [];

        foreach ($nfa->states as $stateId => $state) {
            foreach ($state->transitions as $transition) {
                foreach ($transition->charSet->ranges() as [$start, $end]) {
                    $startIndex = $this->rangeIndexForCodePoint($start, $rangeStarts);
                    $endIndex = $this->rangeIndexForCodePoint($end, $rangeStarts);

                    for ($index = $startIndex; $index <= $endIndex; $index++) {
                        if (null !== $this->budget) {
                            $this->budget->consume();
                        }
                        $indexed[$stateId][$index][$transition->target] = true;
                    }
                }
            }
        }

        return $indexed;
    }

    /**
     * @param array<int> $rangeStarts
     */
    private function rangeIndexForCodePoint(int $codePoint, array $rangeStarts): int
    {
        $low = 0;
        $high = \count($rangeStarts) - 1;
        $result = 0;

        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $value = $rangeStarts[$mid];

            if ($value <= $codePoint) {
                $result = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $result;
    }

    /**
     * @param array<int> $stateIds
     */
    private function setKey(array $stateIds): string
    {
        if ([] === $stateIds) {
            return 'empty';
        }

        return \implode(',', $stateIds);
    }

    private function countTransitions(Nfa $nfa): int
    {
        $count = 0;
        foreach ($nfa->states as $state) {
            $count += \count($state->transitions);
        }

        return $count;
    }
}

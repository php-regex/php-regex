<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Automata\Minimization;

use PhpRegex\Automata\Model\Dfa;
use PhpRegex\Automata\Model\DfaState;
use PhpRegex\Automata\Support\WorkBudget;

/**
 * Moore's partition refinement minimization algorithm.
 *
 * @internal
 */
final class MoorePartitionRefinement implements MinimizationAlgorithmInterface, WorkBudgetAwareMinimizationAlgorithmInterface
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
        if ([] !== $nonAccepting) {
            $partitions[] = $nonAccepting;
        }
        if ([] !== $accepting) {
            $partitions[] = $accepting;
        }

        $stateToGroup = $this->buildStateToGroup($partitions);

        $changed = true;
        while ($changed) {
            $changed = false;
            $newPartitions = [];

            foreach ($partitions as $group) {
                $buckets = [];
                foreach ($group as $stateId) {
                    $signature = $this->signature($states[$stateId], $stateToGroup, $alphabet);
                    $buckets[$signature][] = $stateId;
                }

                if (\count($buckets) > 1) {
                    $changed = true;
                }

                foreach ($buckets as $bucket) {
                    $newPartitions[] = $bucket;
                }
            }

            $partitions = $newPartitions;
            $stateToGroup = $this->buildStateToGroup($partitions);
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
     * @param array<int, int> $stateToGroup
     * @param array<int>      $alphabet
     */
    private function signature(DfaState $state, array $stateToGroup, array $alphabet): string
    {
        $parts = [];

        foreach ($alphabet as $symbol) {
            if (null !== $this->budget) {
                $this->budget->consume();
            }
            $target = $state->transitionFor($symbol);
            $parts[] = null === $target ? 'x' : (string) $stateToGroup[$target];
        }

        return implode(',', $parts);
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

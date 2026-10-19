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

        $stateToGroup = PartitionQuotient::groupOf($partitions);

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
            $stateToGroup = PartitionQuotient::groupOf($partitions);
        }

        return PartitionQuotient::build($dfa, $partitions, $stateToGroup);
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
}

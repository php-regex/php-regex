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

/**
 * The automaton a partition of a DFA's states into equivalent groups gives,
 * whichever algorithm found the groups: one state per group, its moves and
 * ranges those of the group's first state, each led to the group of its
 * target.
 *
 * @internal
 */
final class PartitionQuotient
{
    /**
     * The group of each state.
     *
     * @param array<int, array<int, int>> $partitions
     *
     * @return array<int, int>
     */
    public static function groupOf(array $partitions): array
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
     * @param array<int, array<int, int>> $partitions
     * @param array<int, int>             $stateToGroup
     */
    public static function build(Dfa $dfa, array $partitions, array $stateToGroup): Dfa
    {
        $newStates = [];

        foreach ($partitions as $newId => $group) {
            $representative = $dfa->states[$group[0]];
            $transitions = [];
            $ranges = [];

            foreach ($representative->byteTransitions() as $symbol => $target) {
                $transitions[(int) $symbol] = $stateToGroup[$target];
            }

            $packed = $representative->packedRanges();
            if ([] !== $packed) {
                for ($at = 0, $count = \count($packed); $at < $count; $at += 3) {
                    $ranges[] = $packed[$at];
                    $ranges[] = $packed[$at + 1];
                    $ranges[] = $stateToGroup[$packed[$at + 2]];
                }
            } else {
                foreach ($transitions as $symbol => $target) {
                    $ranges[] = $symbol;
                    $ranges[] = $symbol;
                    $ranges[] = $target;
                }
            }

            $newStates[$newId] = new DfaState($newId, $transitions, $representative->isAccepting, $ranges);
        }

        $startState = $stateToGroup[$dfa->startState];

        return new Dfa($startState, $newStates, $dfa->alphabetRanges, $dfa->minCodePoint, $dfa->maxCodePoint);
    }
}

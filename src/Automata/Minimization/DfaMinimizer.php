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
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Support\WorkBudget;

/**
 * Deterministic DFA minimizer delegating to a strategy implementation.
 *
 * @internal
 */
final readonly class DfaMinimizer
{
    public function __construct(private ?MinimizationAlgorithmInterface $algorithm = null) {}

    public function minimize(Dfa $dfa, ?SolverOptions $options = null): Dfa
    {
        $states = $dfa->states;

        if (\count($states) <= 1) {
            return $dfa;
        }

        $alphabet = $this->effectiveAlphabet($dfa);
        $algorithm = $this->algorithm ?? new HopcroftWorklist();
        $budget = null;
        if (null !== $options?->maxTransitionsProcessed) {
            $budget = new WorkBudget(
                $options->maxTransitionsProcessed,
                'minimize',
                \count($states),
                $this->countTransitions($dfa),
                \count($alphabet),
            );
        }
        if ($algorithm instanceof WorkBudgetAwareMinimizationAlgorithmInterface) {
            $algorithm->setWorkBudget($budget);
        }

        return $algorithm->minimize($dfa, $alphabet);
    }

    /**
     * @return array<int>
     */
    private function effectiveAlphabet(Dfa $dfa): array
    {
        if ([] !== $dfa->alphabetRanges) {
            $symbols = [];
            foreach ($dfa->alphabetRanges as $range) {
                $symbols[] = $range[0];
            }

            \sort($symbols, \SORT_NUMERIC);

            return $symbols;
        }

        $alphabet = [];

        foreach ($dfa->states as $state) {
            foreach ($state->transitions as $symbol => $target) {
                $alphabet[(int) $symbol] = true;
            }
        }

        /** @var array<int> $result */
        $result = \array_keys($alphabet);
        \sort($result, \SORT_NUMERIC);

        return $result;
    }

    private function countTransitions(Dfa $dfa): int
    {
        $count = 0;
        foreach ($dfa->states as $state) {
            if ([] !== $state->ranges) {
                $count += \count($state->ranges);

                continue;
            }

            $count += \count($state->transitions);
        }

        return $count;
    }
}

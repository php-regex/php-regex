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

namespace PhpRegex\Automata\Builder;

use PhpRegex\Automata\Determinization\DeterminizationAlgorithmFactory;
use PhpRegex\Automata\Determinization\DeterminizationAlgorithmInterface;
use PhpRegex\Automata\Determinization\WorkBudgetAwareDeterminizationAlgorithmInterface;
use PhpRegex\Automata\Minimization\DfaMinimizer;
use PhpRegex\Automata\Minimization\MinimizationAlgorithmFactory;
use PhpRegex\Automata\Model\Dfa;
use PhpRegex\Automata\Model\Nfa;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Support\WorkBudget;

/**
 * Determinizes NFAs into DFAs using a configured strategy.
 *
 * @internal
 */
final readonly class DfaBuilder
{
    public function __construct(
        private ?DfaMinimizer $minimizer = null,
        private ?MinimizationAlgorithmFactory $minimizationFactory = null,
        private ?DeterminizationAlgorithmInterface $determinizer = null,
        private ?DeterminizationAlgorithmFactory $determinizationFactory = null,
    ) {}

    /**
     * @throws \PhpRegex\Automata\Exception\ComplexityException
     */
    public function determinize(Nfa $nfa, SolverOptions $options): Dfa
    {
        $alphabetRanges = $this->buildAlphabetRanges($nfa);
        $nfaTransitions = $this->countTransitions($nfa);
        $workBudget = null;
        if (null !== $options->maxTransitionsProcessed) {
            $workBudget = new WorkBudget(
                $options->maxTransitionsProcessed,
                'determinize',
                0,
                $nfaTransitions,
                \count($alphabetRanges),
            );
        }

        $determinizer = $this->determinizer;
        if (null === $determinizer) {
            $factory = $this->determinizationFactory ?? new DeterminizationAlgorithmFactory();
            $determinizer = $factory->create($options->determinizationAlgorithm);
        }

        if ($determinizer instanceof WorkBudgetAwareDeterminizationAlgorithmInterface) {
            $determinizer->setWorkBudget($workBudget);
        }

        $dfa = $determinizer->determinize($nfa, $options, $alphabetRanges);

        if (!$options->minimizeDfa) {
            return $dfa;
        }

        $minimizer = $this->minimizer;
        if (null === $minimizer) {
            $factory = $this->minimizationFactory ?? new MinimizationAlgorithmFactory();
            $algorithm = $factory->create($options->minimizationAlgorithm);
            $minimizer = new DfaMinimizer($algorithm);
        }

        return $minimizer->minimize($dfa, $options);
    }

    /**
     * @return array<int, array{0:int, 1:int}>
     */
    private function buildAlphabetRanges(Nfa $nfa): array
    {
        $min = $nfa->minCodePoint;
        $max = $nfa->maxCodePoint;

        $boundaries = [
            $min => true,
            $max + 1 => true,
        ];

        foreach ($nfa->states as $state) {
            foreach ($state->transitions as $transition) {
                foreach ($transition->charSet->ranges() as [$start, $end]) {
                    $boundaries[$start] = true;
                    if ($end + 1 <= $max + 1) {
                        $boundaries[$end + 1] = true;
                    }
                }
            }
        }

        /** @var array<int> $points */
        $points = \array_keys($boundaries);
        \sort($points, \SORT_NUMERIC);

        $ranges = [];
        $count = \count($points);
        for ($i = 0; $i < $count - 1; $i++) {
            $start = $points[$i];
            $end = $points[$i + 1] - 1;

            if ($start > $max) {
                break;
            }

            if ($end < $min) {
                continue;
            }

            $ranges[] = [$start, \min($end, $max)];
        }

        if ([] === $ranges) {
            $ranges[] = [$min, $max];
        }

        return $ranges;
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

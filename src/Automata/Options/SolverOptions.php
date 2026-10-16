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

namespace PHPRegex\Automata\Options;

use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Minimization\MinimizationAlgorithm;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;

/**
 * Configuration for automata-based regex comparisons.
 */
final class SolverOptions
{
    /**
     * @param MatchMode                $matchMode                How to interpret matching semantics
     * @param int                      $maxNfaStates             Maximum allowed NFA states
     * @param int                      $maxDfaStates             Maximum allowed DFA states
     * @param bool                     $minimizeDfa              Whether to minimize DFAs after determinization
     * @param MinimizationAlgorithm    $minimizationAlgorithm    Strategy used for DFA minimization
     * @param int|null                 $maxTransitionsProcessed  Hard limit on transition work (determinize/minimize); null disables the guard
     * @param DeterminizationAlgorithm $determinizationAlgorithm Strategy used for NFA determinization
     *
     * @throws InvalidRegexOptionException when a state cap is below 1, or the transition budget below 0
     */
    public function __construct(
        public readonly MatchMode $matchMode = MatchMode::Full,
        public readonly int $maxNfaStates = 5000,
        public readonly int $maxDfaStates = 10000,
        public readonly bool $minimizeDfa = true,
        public readonly MinimizationAlgorithm $minimizationAlgorithm = MinimizationAlgorithm::Hopcroft,
        // A finite default: with no cap, pathological real-world patterns can
        // spin the determinization loop for tens of minutes.
        public readonly ?int $maxTransitionsProcessed = 1_000_000,
        public readonly DeterminizationAlgorithm $determinizationAlgorithm = DeterminizationAlgorithm::SubsetIndexed,
    ) {
        // A transition budget of 0 is a budget: the first transition spends it.
        foreach (['maxNfaStates' => [$maxNfaStates, 1], 'maxDfaStates' => [$maxDfaStates, 1], 'maxTransitionsProcessed' => [$maxTransitionsProcessed ?? 0, 0]] as $name => [$value, $floor]) {
            if ($value < $floor) {
                throw new InvalidRegexOptionException(\sprintf('"%s" must be at least %d, %d given.', $name, $floor, $value));
            }
        }
    }
}

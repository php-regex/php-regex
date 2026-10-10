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

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Options\SolverOptions;

/**
 * Defines an algorithm that determinizes an NFA into a DFA.
 *
 * @phpstan-import-type AlphabetRange from Dfa
 *
 * @internal
 */
interface DeterminizationAlgorithmInterface
{
    /**
     * @param array<int, AlphabetRange> $alphabetRanges
     *
     * @throws ComplexityException
     */
    public function determinize(Nfa $nfa, SolverOptions $options, array $alphabetRanges): Dfa;
}

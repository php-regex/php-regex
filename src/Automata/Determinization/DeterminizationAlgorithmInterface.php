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

namespace PhpRegex\Automata\Determinization;

use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Automata\Model\Dfa;
use PhpRegex\Automata\Model\Nfa;
use PhpRegex\Automata\Options\SolverOptions;

/**
 * Defines an algorithm that determinizes an NFA into a DFA.
 *
 * @internal
 */
interface DeterminizationAlgorithmInterface
{
    /**
     * @param array<int, array{0:int, 1:int}> $alphabetRanges
     *
     * @throws ComplexityException
     */
    public function determinize(Nfa $nfa, SolverOptions $options, array $alphabetRanges): Dfa;
}

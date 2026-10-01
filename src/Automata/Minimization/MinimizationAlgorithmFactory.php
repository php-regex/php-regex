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

namespace PhpRegex\Automata\Minimization;

/**
 * Factory for DFA minimization algorithm strategies.
 *
 * @internal
 */
final class MinimizationAlgorithmFactory
{
    public function create(MinimizationAlgorithm $algorithm): MinimizationAlgorithmInterface
    {
        return match ($algorithm) {
            MinimizationAlgorithm::MOORE => new MoorePartitionRefinement(),
            MinimizationAlgorithm::HOPCROFT => new HopcroftWorklist(),
        };
    }
}

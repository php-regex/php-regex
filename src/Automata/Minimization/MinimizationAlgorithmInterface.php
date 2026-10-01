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

/**
 * Strategy interface for DFA minimization algorithms.
 *
 * @internal
 */
interface MinimizationAlgorithmInterface
{
    /**
     * @param array<int> $alphabet Effective alphabet to iterate over
     */
    public function minimize(Dfa $dfa, array $alphabet): Dfa;
}

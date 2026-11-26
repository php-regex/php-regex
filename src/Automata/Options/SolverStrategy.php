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

/**
 * How intersection(), subsetOf() and equivalent() search for a witness.
 *
 * @internal the solver chooses its search: with a DFA cache, or for a pattern
 *           read as UTF-8 beside one read as bytes, it is the eager one
 */
enum SolverStrategy: string
{
    /**
     * Determinize and minimize both patterns, then walk the product of the
     * two DFAs.
     */
    case Eager = 'eager';

    /**
     * Walk the product of the NFAs as it is reached: the intersection pairs
     * NFA states and determinizes nothing; an inclusion pairs the left NFA
     * with the right one determinized one reached state at a time. A state
     * no witness passes through is never built.
     */
    case Lazy = 'lazy';
}

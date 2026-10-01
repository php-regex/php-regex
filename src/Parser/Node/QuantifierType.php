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

namespace PhpRegex\Parser\Node;

/**
 * Defines the "greediness" of a quantifier.
 */
enum QuantifierType: string
{
    /**
     * Greedy (e.g., "*", "+").
     */
    case T_GREEDY = 'greedy';

    /**
     * Lazy (non-greedy) (e.g., "*?", "+?").
     */
    case T_LAZY = 'lazy';

    /**
     * Possessive (e.g., "*+", "++").
     */
    case T_POSSESSIVE = 'possessive';
}

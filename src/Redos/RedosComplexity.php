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

namespace PHPRegex\Redos;

/**
 * How the cost of one match attempt grows with the length of the subject,
 * in a backtracking engine that follows PCRE's order.
 *
 * @api
 */
enum RedosComplexity: string
{
    /**
     * Linear: no input makes an attempt backtrack beyond a linear number of
     * steps.
     */
    case Linear = 'linear';

    /**
     * Polynomial: an input makes an attempt cost n^k steps, k being the
     * analysis' degree.
     */
    case Polynomial = 'polynomial';

    /**
     * Exponential: an input makes an attempt cost 2^n steps.
     */
    case Exponential = 'exponential';

    /**
     * Not proven: the pattern was not analysed, or the heuristics decided.
     */
    case Unknown = 'unknown';
}

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
 * Who decided a ReDoS verdict.
 *
 * @api
 */
enum RedosProof: string
{
    /**
     * The backtracking model proved the complexity class.
     */
    case Proven = 'proven';

    /**
     * The pattern holds a construct outside the model: the structural
     * heuristics decided.
     */
    case Heuristic = 'heuristic';

    /**
     * The model ran out of its budget: the structural heuristics decided.
     */
    case BudgetExceeded = 'budget_exceeded';

    /**
     * No analysis ran: the mode was off, the pattern ignored, or the
     * analysis failed.
     */
    case NotAnalyzed = 'not_analyzed';
}

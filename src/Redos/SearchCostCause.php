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
 * What makes a search quadratic while each of its attempts is linear: what
 * starts an attempt at each position of the run, which reads to the end of
 * the run.
 *
 * @api
 */
enum SearchCostCause: string
{
    /**
     * The unanchored search starts an attempt at each position of the run,
     * and each fails on the breaker: the cost of every search,
     * RedosAnalysis::$searchCost.
     */
    case UnanchoredSearch = 'unanchored_search';

    /**
     * After an empty match inside the run, the functions that look for every
     * match retry the same offset for a match that is not empty, and the
     * retry fails on the breaker: RedosAnalysis::$everyMatchSearchCost.
     */
    case EmptyMatchRetry = 'empty_match_retry';

    /**
     * The attempt that ends each short match inside the run reads to the end
     * of the run before it settles on it, and the functions that look for
     * every match start a call where it ends: RedosAnalysis::$everyMatchSearchCost.
     * A loop that calls preg_match() itself, and makes no retry, pays it
     * for the empty matches such an attempt settles on too.
     */
    case ShortMatch = 'short_match';
}

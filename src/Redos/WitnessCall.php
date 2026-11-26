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
 * The call of preg_match() a witness drives into the backtrack limit. PHP
 * runs a pattern differently when the call asks for no $matches: it retries
 * an empty match there, and "/(?:|(?:a|a)*$)/" gives up on "aa" x n . "!"
 * only then, where the call with $matches settles on the empty match.
 *
 * @api
 */
enum WitnessCall: string
{
    /**
     * preg_match($pattern, $subject, $matches), as every other function
     * that hands back what it matched.
     */
    case WithMatches = 'with_matches';

    /**
     * preg_match($pattern, $subject), without $matches.
     */
    case WithoutMatches = 'without_matches';
}

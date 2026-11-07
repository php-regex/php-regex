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

namespace PHPRegex\Infection\Site;

/**
 * What a call lets its caller see of the pattern, which decides when two
 * patterns are alike for it.
 *
 * @internal
 */
enum Observation
{
    /**
     * Whether the subject holds a match, and nothing else: preg_match()
     * given the pattern and the subject alone, and preg_grep(), which keeps
     * the elements that hold one (or the others). Two patterns that find a
     * match in the same subjects are alike here.
     */
    case Match;

    /**
     * The matches themselves, everywhere a call may run the pattern: what
     * preg_match() writes into $matches, what preg_match_all() collects,
     * what preg_replace() and preg_split() cut on. Two patterns are alike
     * when they write the same matches in every run.
     */
    case Matches;
}

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

namespace PHPRegex\Automata\Match;

/**
 * How the engine runs a pattern for a call. preg_match() searches from the
 * start of the subject; with an offset it searches from past it, where "^"
 * and "\A" never hold. preg_match_all(), preg_replace() and preg_split()
 * search again from the end of each match, and after an empty match first
 * retry where it stands, anchored there and refusing an empty match
 * (PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED).
 *
 * @internal
 */
enum MatchRun
{
    /**
     * preg_match() from offset 0: a new start at each position until a
     * match is found, "^" holding at the first.
     */
    case Search;

    /**
     * The same search from past the start of the subject.
     */
    case SearchPastSubjectStart;

    /**
     * The retry after an empty match at the start of the subject.
     */
    case EmptyMatchRetry;

    /**
     * The retry after an empty match past the start of the subject.
     */
    case EmptyMatchRetryPastSubjectStart;

    public function atSubjectStart(): bool
    {
        return self::Search === $this || self::EmptyMatchRetry === $this;
    }

    public function isRetry(): bool
    {
        return self::EmptyMatchRetry === $this || self::EmptyMatchRetryPastSubjectStart === $this;
    }
}

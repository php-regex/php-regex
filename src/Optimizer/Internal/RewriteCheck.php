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

namespace PHPRegex\Optimizer\Internal;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Exception\ExceptionInterface;

/**
 * The proof that a rewrite may replace its pattern, one verdict for the
 * optimizer and the lint: the $matches preg_match() writes on every subject,
 * or, where the match solver does not read a pattern or gives up, the same
 * normal form. The strings both match prove no $matches: /a(?!b)/ and /a/
 * match the same strings whole, yet not the same text in "ab". A check that
 * runs out of its budget proves nothing.
 *
 * @internal
 */
final class RewriteCheck
{
    /**
     * The configurations the match check may explore: a few hundred settle
     * the rewrites of real patterns (an anchored alternation of some thirty
     * SQL statements takes some six hundred), and the huge ones stay
     * unproven. A configuration holds some 9 KB while the check runs, so
     * one that gives up here has held about 9 MB.
     */
    private const MATCH_CHECK_STATES = 1_000;

    /**
     * The threads the match check may step in all: a configuration of a
     * list of hundreds of words holds a thread per word, so a few hundred
     * configurations there cost seconds.
     */
    private const MATCH_CHECK_WORK = 200_000;

    public function __construct(private readonly LanguageSolver $solver) {}

    /**
     * @throws ExceptionInterface when the match solver cannot parse a side
     *
     * @return bool true when proven, false when disproven or unproven
     */
    public function verify(string $original, string $optimized): bool
    {
        // A pattern goes to preg_match_all(), preg_replace(), preg_split()
        // and preg_match() from an offset as well: the rewrite must write
        // what the pattern writes in each. A pattern that reads the text
        // before where the search starts proves nothing from offset 0, "^"
        // needs a search past the start of the subject, and an empty match
        // the retry those calls make after it. Two patterns that read alike
        // need no run at all.
        $runs = $this->solver->runsOfEveryCall($original, $optimized);
        if (null === $runs) {
            return false;
        }

        // The same strings are not enough: a rewrite must write the same
        // $matches, which /(a|ab)/ and /(ab|a)/ do not on "ab". The same
        // $matches on every subject is the same answer on every subject,
        // so the strings preg_match() accepts agree as well.
        $options = new SolverOptions(maxDfaStates: self::MATCH_CHECK_STATES, maxTransitionsProcessed: self::MATCH_CHECK_WORK);

        try {
            foreach ($runs as $run) {
                if (!$this->solver->matchEquivalentIn($run, $original, $optimized, $options)->isEquivalent) {
                    return false;
                }
            }

            return true;
        } catch (ComplexityException) {
            // Outside what the match solver reads, or past its budget, and
            // not reading alike: the strings both match whole would prove no
            // $matches, a lookahead at the edge of the match or alternatives
            // taken in another order write other text.
            return false;
        }
    }
}

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
 * optimizer and the lint: first the $matches preg_match() writes on every
 * subject, then, where the match solver does not read a pattern or gives up,
 * the strings both match. A check that runs out of its budget proves
 * nothing.
 *
 * @internal
 */
final readonly class RewriteCheck
{
    /**
     * The configurations the match check may explore: a few hundred settle
     * the rewrites of real patterns, and the huge ones fall back to
     * comparing languages.
     */
    private const MATCH_CHECK_STATES = 500;

    /**
     * The threads the match check may step in all: a configuration of a
     * list of hundreds of words holds a thread per word, so a few hundred
     * configurations there cost seconds, where the language check takes a
     * fraction of one.
     */
    private const MATCH_CHECK_WORK = 200_000;

    /**
     * The transitions the language check may process. Each DFA state writes
     * a transition per range of the alphabet, and a Unicode class such as
     * \pL splits it into hundreds of ranges: at the solver's default budget,
     * one pattern's table took hundreds of megabytes before the check gave
     * up.
     */
    private const LANGUAGE_CHECK_WORK = 200_000;

    public function __construct(private LanguageSolver $solver) {}

    /**
     * @throws ExceptionInterface when the match solver cannot parse a side
     *
     * @return bool|null true when proven, false when disproven or when the
     *                   language check gave up on its budget, null when the
     *                   automata do not read the patterns
     */
    public function verify(string $original, string $optimized): ?bool
    {
        // The same strings are not enough: a rewrite must write the same
        // $matches, which /(a|ab)/ and /(ab|a)/ do not on "ab". The same
        // $matches on every subject is the same answer on every subject,
        // so the strings preg_match() accepts agree as well.
        try {
            return $this->solver->matchEquivalent($original, $optimized, new SolverOptions(maxDfaStates: self::MATCH_CHECK_STATES, maxTransitionsProcessed: self::MATCH_CHECK_WORK))->isEquivalent;
        } catch (ComplexityException) {
            // Outside what the match solver reads, or past its budget: the
            // strings must agree.
        }

        try {
            return $this->solver->equivalent($original, $optimized, new SolverOptions(maxTransitionsProcessed: self::LANGUAGE_CHECK_WORK))->isEquivalent;
        } catch (ComplexityException $e) {
            // The match check parsed both patterns already: only the
            // automata can refuse them here. Only a pattern they cannot
            // read at all keeps its rewrite on trust.
            return isset($e->getDiagnostic()['limit']) ? false : null;
        }
    }
}

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

namespace PHPRegex\Infection\Judge;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Infection\Site\Observation;
use PHPRegex\Parser\Exception\ExceptionInterface;

/**
 * Asks the automata whether two patterns are alike for what a call sees,
 * each question under its own cap of states and transitions and one unit
 * of the budget. Only a proof says alike: a pattern outside what the
 * automata read, or a cap reached, leaves the verdict open.
 *
 * @internal
 */
final class EquivalenceJudge
{
    /**
     * The states one question may build.
     */
    public const MAX_STATES = 1_000;

    /**
     * The transitions one question may walk.
     */
    public const MAX_TRANSITIONS = 200_000;

    public function __construct(private readonly LanguageSolver $solver = new LanguageSolver()) {}

    public function judge(Observation $observation, string $left, string $right, Budget $budget): Verdict
    {
        return Observation::Match === $observation
            ? $this->sameMatchingSubjects($left, $right, $budget)
            : $this->sameMatches($left, $right, $budget);
    }

    private function sameMatchingSubjects(string $left, string $right, Budget $budget): Verdict
    {
        if (!$budget->spend($left, $right)) {
            return new Verdict(null);
        }

        try {
            $result = $this->solver->equivalent($left, $right, new SolverOptions(MatchMode::Partial, maxDfaStates: self::MAX_STATES, maxTransitionsProcessed: self::MAX_TRANSITIONS));
        } catch (ExceptionInterface) {
            return new Verdict(null);
        }

        if ($result->isEquivalent) {
            return new Verdict(true);
        }

        $subjects = array_filter([$result->leftOnlyExample, $result->rightOnlyExample], static fn (?string $subject): bool => null !== $subject);
        usort($subjects, static fn (string $a, string $b): int => \strlen($a) <=> \strlen($b));

        return new Verdict(false, $subjects);
    }

    /**
     * The same matches in every run a preg_*() call may make: from the
     * start, from past it, and the retry after an empty match. No set of
     * runs covers every call of a pattern that reads the text before the
     * search, and the match solver reads no such pattern either: the
     * verdict stays open, and the sample generator looks for a subject.
     */
    private function sameMatches(string $left, string $right, Budget $budget): Verdict
    {
        if (!$budget->spend($left, $right)) {
            return new Verdict(null);
        }

        try {
            $runs = $this->solver->runsOfEveryCall($left, $right);
        } catch (ExceptionInterface) {
            return new Verdict(null);
        }

        if (null === $runs) {
            return new Verdict(null);
        }

        // No run at all for two patterns that read alike: proven.
        $options = new SolverOptions(maxDfaStates: self::MAX_STATES, maxTransitionsProcessed: self::MAX_TRANSITIONS);
        $proven = true;
        $apart = false;
        $subjects = [];
        foreach ($runs as $run) {
            if (!$budget->spend($left, $right)) {
                return new Verdict($apart ? false : null, $subjects);
            }

            try {
                $result = $this->solver->matchEquivalentIn($run, $left, $right, $options);
            } catch (ExceptionInterface) {
                $proven = false;

                continue;
            }

            if (!$result->isEquivalent) {
                $apart = true;
                if (null !== $result->counterExample && !\in_array($result->counterExample, $subjects, true)) {
                    $subjects[] = $result->counterExample;
                }
            }
        }

        if ($apart) {
            return new Verdict(false, $subjects);
        }

        return new Verdict($proven ? true : null);
    }
}

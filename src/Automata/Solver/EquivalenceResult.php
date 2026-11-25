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

namespace PHPRegex\Automata\Solver;

/**
 * Result of an equivalence check between two regexes.
 */
final class EquivalenceResult
{
    /**
     * The PCRE2 release that ran the solver, which it asks what the classes
     * of the patterns hold. The patterns are read for the target of the
     * solver's parser, which may be another PHP and PCRE2.
     */
    public readonly string $pcreVersion;

    /**
     * @internal built by LanguageSolver::equivalent()
     *
     * @param bool        $isEquivalent     Whether both regexes accept the same language
     * @param string|null $leftOnlyExample  Example accepted by left but not right
     * @param string|null $rightOnlyExample Example accepted by right but not left
     * @param string|null $pcreVersion      The PCRE2 release that ran the solver; the running one when null
     */
    public function __construct(
        public readonly bool $isEquivalent,
        public readonly ?string $leftOnlyExample = null,
        public readonly ?string $rightOnlyExample = null,
        ?string $pcreVersion = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
    }
}

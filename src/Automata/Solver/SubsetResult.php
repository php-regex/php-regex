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
 * Result of a subset check between two regexes.
 */
final class SubsetResult
{
    /**
     * The PCRE2 release that ran the solver, which it asks what the classes
     * of the patterns hold. The patterns are read for the target of the
     * solver's parser, which may be another PHP and PCRE2.
     */
    public readonly string $pcreVersion;

    /**
     * @internal built by LanguageSolver::subsetOf()
     *
     * @param bool        $isSubset       Whether the left language is subset of the right
     * @param string|null $counterExample Example string accepted by left but not right
     * @param string|null $pcreVersion    The PCRE2 release that ran the solver; the running one when null
     */
    public function __construct(
        public readonly bool $isSubset,
        public readonly ?string $counterExample = null,
        ?string $pcreVersion = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
    }
}

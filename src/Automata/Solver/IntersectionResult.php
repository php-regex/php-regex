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
 * Result of an intersection check between two regexes.
 */
final class IntersectionResult
{
    /**
     * The PCRE2 release that ran the solver, which it asks what the classes
     * of the patterns hold. The patterns are read for the target of the
     * solver's parser, which may be another PHP and PCRE2.
     */
    public readonly string $pcreVersion;

    /**
     * @internal built by LanguageSolver::intersection()
     *
     * @param bool        $isEmpty     Whether the intersection is empty
     * @param string|null $example     Example string found in the intersection
     * @param string|null $pcreVersion The PCRE2 release that ran the solver; the running one when null
     */
    public function __construct(
        public readonly bool $isEmpty,
        public readonly ?string $example = null,
        ?string $pcreVersion = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
    }
}

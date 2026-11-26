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

use PHPRegex\Parser\PcreTarget;

/**
 * Result of an intersection check between two regexes.
 */
final class IntersectionResult
{
    /**
     * The PCRE2 release that ran the solver, the engine it asks what the
     * classes of the patterns hold: the running one. The patterns are read
     * for $target, which may be another PHP and PCRE2.
     */
    public readonly string $pcreVersion;

    /**
     * The PHP version, the PCRE2 release and the locale the patterns were
     * read for, the target of the solver's parser: it decides what a
     * pattern means, "a{,2}" a repeat from PCRE2 10.43 and text before.
     */
    public readonly PcreTarget $target;

    /**
     * @internal built by LanguageSolver::intersection()
     *
     * @param bool            $isEmpty     Whether the intersection is empty
     * @param string|null     $example     Example string found in the intersection
     * @param string|null     $pcreVersion The PCRE2 release that ran the solver; the running one when null
     * @param PcreTarget|null $target      The target the patterns were read for; the running engine when null
     */
    public function __construct(
        public readonly bool $isEmpty,
        public readonly ?string $example = null,
        ?string $pcreVersion = null,
        ?PcreTarget $target = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
        $this->target = $target ?? PcreTarget::runtime();
    }
}

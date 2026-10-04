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
 * Result of a match-equivalence check: whether preg_match() writes the same
 * $matches for both patterns on every subject.
 */
final readonly class MatchEquivalenceResult
{
    public string $pcreVersion;

    /**
     * @param bool        $isEquivalent   Whether every subject gets the same answer, match and groups from both
     * @param string|null $counterExample The shortest subject that gets different ones
     * @param string|null $pcreVersion    The PCRE2 release the answer was computed with
     */
    public function __construct(
        public bool $isEquivalent,
        public ?string $counterExample = null,
        ?string $pcreVersion = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
    }
}

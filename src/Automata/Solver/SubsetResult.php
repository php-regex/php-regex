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
final readonly class SubsetResult
{
    public string $pcreVersion;

    /**
     * @param bool        $isSubset       Whether the left language is subset of the right
     * @param string|null $counterExample Example string accepted by left but not right
     * @param string|null $pcreVersion    The PCRE2 release the answer was computed with
     */
    public function __construct(
        public bool $isSubset,
        public ?string $counterExample = null,
        ?string $pcreVersion = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
    }
}

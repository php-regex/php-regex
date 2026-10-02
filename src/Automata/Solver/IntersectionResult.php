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
final readonly class IntersectionResult
{
    public string $pcreVersion;

    /**
     * @param bool        $isEmpty     Whether the intersection is empty
     * @param string|null $example     Example string found in the intersection
     * @param string|null $pcreVersion The PCRE2 release the answer was computed with
     */
    public function __construct(
        public bool $isEmpty,
        public ?string $example = null,
        ?string $pcreVersion = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
    }
}

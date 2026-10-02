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
final readonly class EquivalenceResult
{
    public string $pcreVersion;

    /**
     * @param bool        $isEquivalent     Whether both regexes accept the same language
     * @param string|null $leftOnlyExample  Example accepted by left but not right
     * @param string|null $rightOnlyExample Example accepted by right but not left
     * @param string|null $pcreVersion      The PCRE2 release the answer was computed with
     */
    public function __construct(
        public bool $isEquivalent,
        public ?string $leftOnlyExample = null,
        public ?string $rightOnlyExample = null,
        ?string $pcreVersion = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
    }
}

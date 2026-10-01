<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Automata\Solver;

/**
 * Result of a subset check between two regexes.
 */
final readonly class SubsetResult
{
    /**
     * @param bool        $isSubset       Whether the left language is subset of the right
     * @param string|null $counterExample Example string accepted by left but not right
     */
    public function __construct(public bool $isSubset, public ?string $counterExample = null) {}
}

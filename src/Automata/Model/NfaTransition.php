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

namespace PhpRegex\Automata\Model;

use PhpRegex\Automata\Alphabet\CharSet;

/**
 * NFA transition labeled with a character set.
 *
 * @internal
 */
final readonly class NfaTransition
{
    /**
     * @param \PhpRegex\Automata\Alphabet\CharSet $charSet Transition label
     * @param int                                 $target  Target state id
     */
    public function __construct(public CharSet $charSet, public int $target) {}
}

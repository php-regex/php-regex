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

namespace PHPRegex\Explain;

/**
 * The shape of a piece of an explanation, which each format lays out its
 * own way.
 *
 * @internal
 */
enum ExplanationKind
{
    /**
     * The whole pattern: its heading, then what it matches.
     */
    case Pattern;

    /**
     * Pieces matched one after the other.
     */
    case Sequence;

    /**
     * Branches, one of which matches.
     */
    case Alternation;

    /**
     * A group, a lookaround, a DEFINE block or a script run: a heading,
     * what it holds, and the line that closes it.
     */
    case Block;

    /**
     * A piece matched a number of times.
     */
    case Repetition;

    /**
     * A condition, the branch taken when it holds, and the one taken when
     * it does not, if any.
     */
    case Conditional;

    /**
     * A construct described on its own line.
     */
    case Leaf;
}

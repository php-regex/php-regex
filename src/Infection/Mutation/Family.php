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

namespace PHPRegex\Infection\Mutation;

/**
 * The six kinds of mutation, one per mutator: no mutated pattern belongs to
 * two of them, since Infection runs each mutator's mutants on their own.
 *
 * @internal
 */
enum Family
{
    /**
     * "+" and "*" swap, a bound moves by one, the repeat goes, the laziness
     * turns.
     */
    case Quantifier;

    /**
     * "^", "$", "\A", "\z", "\Z", "\b" and "\B" go; "$" and "\z" swap.
     */
    case Anchor;

    /**
     * A class turns, loses a member, narrows a range; "\d", "\w" and "\s"
     * turn.
     */
    case CharClass;

    /**
     * A branch goes, two neighbouring branches swap.
     */
    case Alternation;

    /**
     * A trailing modifier goes, "u" never.
     */
    case Flag;

    /**
     * An escaped metacharacter outside a class loses its backslash.
     */
    case Escape;
}

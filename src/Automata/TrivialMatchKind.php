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

namespace PHPRegex\Automata;

/**
 * The string function a preg_match() answers alike.
 */
enum TrivialMatchKind: string
{
    /**
     * str_contains(): the literal anywhere.
     */
    case Contains = 'contains';

    /**
     * str_starts_with().
     */
    case StartsWith = 'starts_with';

    /**
     * str_ends_with().
     */
    case EndsWith = 'ends_with';

    /**
     * ===: the subject is the literal.
     */
    case Equals = 'equals';

    /**
     * in_array(..., true): the subject is one of the literals.
     */
    case OneOf = 'one_of';

    /**
     * '' ===: the subject is empty.
     */
    case IsEmpty = 'is_empty';
}

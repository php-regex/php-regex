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

namespace PHPRegex\Parser\Analysis;

/**
 * Whether a capturing group holds a value after a successful match.
 */
enum Participation: string
{
    /**
     * Every match sets the group.
     */
    case Always = 'always';

    /**
     * Some matches set the group and some do not. The answer when the
     * pattern alone cannot tell.
     */
    case MayBeUnset = 'may_be_unset';

    /**
     * No match sets the group: it sits in a negative assertion, a DEFINE
     * block or a repeat of zero.
     */
    case Never = 'never';
}

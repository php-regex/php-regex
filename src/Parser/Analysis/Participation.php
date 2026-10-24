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
 *
 * The recipe for typing `$matches` off these cases, as the engine writes
 * it: a group with `Always` sits in every match array, its key present;
 * `MayBeUnset` means the key may be absent — the engine omits a trailing
 * group left unset, and writes `''` (or `null` under
 * PREG_UNMATCHED_AS_NULL, where even a trailing unset group appears)
 * for one that is not trailing; `Never` fills nothing — the group counts
 * in the numbering, and a non-trailing one still appears as `''`/`null`
 * while a trailing one is omitted. When in doubt, wider wins: an
 * optional key with a union value is never wrong.
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

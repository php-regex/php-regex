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

namespace PHPRegex\Parser\Syntax;

/**
 * The largest number PCRE takes where a pattern writes one: a repeat count,
 * a group number, the length of a lookbehind.
 *
 * @internal
 */
final class PcreNumber
{
    public const MAX = 65535;

    private function __construct() {}

    /**
     * Whether the digits, leading zeros aside, write a number past MAX: more
     * than five significant digits do, whatever an integer would make of
     * them.
     */
    public static function exceeds(string $digits): bool
    {
        return \strlen(ltrim($digits, '0')) > 5 || (int) $digits > self::MAX;
    }
}

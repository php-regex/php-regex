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

namespace PHPRegex\Parser\Engine;

/**
 * The limits one match runs under: the engine sets "pcre.backtrack_limit"
 * and "pcre.recursion_limit" for the call and puts back what it found.
 */
final readonly class PcreLimits
{
    public function __construct(public int $backtrackLimit, public int $recursionLimit) {}
}

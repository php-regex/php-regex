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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;

/**
 * The limits one match runs under: the engine sets "pcre.backtrack_limit"
 * and "pcre.recursion_limit" for the call and puts back what it found.
 */
final class PcreLimits
{
    /**
     * @throws InvalidRegexOptionException when a limit is below 1
     */
    public function __construct(public readonly int $backtrackLimit, public readonly int $recursionLimit)
    {
        if ($backtrackLimit < 1 || $recursionLimit < 1) {
            throw new InvalidRegexOptionException(\sprintf('"%s" must be at least 1, %d given.', $backtrackLimit < 1 ? 'backtrackLimit' : 'recursionLimit', $backtrackLimit < 1 ? $backtrackLimit : $recursionLimit));
        }
    }
}

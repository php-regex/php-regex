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

namespace PHPRegex\PHPUnit;

/**
 * A capture expectation the pattern's own groups answer to, every key
 * resolved to the group number it names.
 *
 * @internal read through PatternLab
 */
final class Expectation
{
    /**
     * @param array<int|string, int>   $numbers each expectation key, as written, with the group number it names
     * @param array<int|string, mixed> $shapes  each expectation key with the spelling it was given
     */
    public function __construct(public readonly array $numbers, public readonly array $shapes) {}
}

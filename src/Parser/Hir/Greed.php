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

namespace PHPRegex\Parser\Hir;

/**
 * How a repetition shares the input: as much as it can first, as little, or
 * as much without ever giving any back. "(?U)" is already applied.
 *
 * @internal
 */
enum Greed
{
    case Greedy;
    case Lazy;
    case Possessive;
}

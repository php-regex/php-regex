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

class LookKind
{
    public const Ahead = 'ahead';
    public const NegativeAhead = 'negativeahead';
    public const Behind = 'behind';
    public const NegativeBehind = 'negativebehind';
}

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

namespace PHPRegex\Parser\Node;

class ClassSetOperator
{
    public const Union = 'union';
    public const Intersection = 'intersection';
    public const Difference = 'difference';
    public const SymmetricDifference = 'symmetric_difference';
    public const Complement = 'complement';
}

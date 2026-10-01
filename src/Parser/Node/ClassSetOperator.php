<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Node;

/**
 * The set operators of a Perl extended class "(?[...])", PCRE2 10.45. "!"
 * binds tightest, then "&", then the others, left to right.
 */
enum ClassSetOperator: string
{
    /**
     * "+" or "|": the characters of either operand.
     */
    case UNION = 'union';

    /**
     * "&": the characters of both operands.
     */
    case INTERSECTION = 'intersection';

    /**
     * "-": the characters of the left operand not in the right one.
     */
    case DIFFERENCE = 'difference';

    /**
     * "^": the characters of exactly one operand.
     */
    case SYMMETRIC_DIFFERENCE = 'symmetric_difference';

    /**
     * "!", before its only operand: every character not in it.
     */
    case COMPLEMENT = 'complement';
}

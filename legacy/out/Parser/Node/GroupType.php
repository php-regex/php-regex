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

class GroupType
{
    public const Capturing = 'capturing';
    public const NonCapturing = 'non_capturing';
    public const Named = 'named';
    public const LookaheadPositive = 'lookahead_positive';
    public const LookaheadNegative = 'lookahead_negative';
    public const LookbehindPositive = 'lookbehind_positive';
    public const LookbehindNegative = 'lookbehind_negative';
    public const InlineFlags = 'inline_flags';
    public const Atomic = 'atomic';
    public const BranchReset = 'branch_reset';
    public const ScanSubstring = 'scan_substring';
}

<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Node;

/**
 * Defines the semantic type of a group.
 */
enum GroupType: string
{
    /**
     * A capturing group (...).
     */
    case Capturing = 'capturing';

    /**
     * A non-capturing group (?:...).
     */
    case NonCapturing = 'non_capturing';

    /**
     * A named capturing group (?<name>...) or (?P<name>...).
     */
    case Named = 'named';

    /**
     * A positive lookahead (?=...).
     */
    case LookaheadPositive = 'lookahead_positive';

    /**
     * A negative lookahead (?!...).
     */
    case LookaheadNegative = 'lookahead_negative';

    /**
     * A positive lookbehind (?<=...).
     */
    case LookbehindPositive = 'lookbehind_positive';

    /**
     * A negative lookbehind (?<!...).
     */
    case LookbehindNegative = 'lookbehind_negative';

    /**
     * Inline flags (?i:...).
     */
    case InlineFlags = 'inline_flags';

    /**
     * An atomic group (?>...).
     */
    case Atomic = 'atomic';

    /**
     * A branch reset group (?|...).
     */
    case BranchReset = 'branch_reset';

    /**
     * A substring scan (*scan_substring:(1)...), or (*scs:(1)...), PCRE2
     * 10.45: an assertion that matches its body against what the listed
     * groups captured, not against the subject where it stands.
     */
    case ScanSubstring = 'scan_substring';
}

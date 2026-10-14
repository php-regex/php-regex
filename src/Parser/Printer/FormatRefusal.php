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

namespace PHPRegex\Parser\Printer;

/**
 * Why the formatter gave a pattern back as it was written: laying it out
 * under x would change what it matches.
 */
enum FormatRefusal: string
{
    /**
     * The pattern holds "#" comments under (*CR), (*CRLF) or (*NUL), where a
     * comment does not end at a line feed.
     */
    case NewlineConvention = 'newline_convention';

    /**
     * The pattern, without x, holds a quantifier written with spaces, such
     * as "a{ 1,2 }": a quantifier from PCRE2 10.43 on, a literal before.
     */
    case SpacedQuantifier = 'spaced_quantifier';

    /**
     * The pattern turns x off at its top level, with "(?-x)" or "(?^)": a
     * line break after it would be a literal.
     */
    case ExtendedModeSwitchedOff = 'extended_mode_switched_off';
}

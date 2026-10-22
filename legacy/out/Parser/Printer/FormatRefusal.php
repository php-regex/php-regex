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

class FormatRefusal
{
    public const NewlineConvention = 'newline_convention';
    public const SpacedQuantifier = 'spaced_quantifier';
    public const ExtendedModeSwitchedOff = 'extended_mode_switched_off';
    public const LineBreakInName = 'line_break_in_name';
    public const ParenthesisInComment = 'parenthesis_in_comment';
    public const DelimiterMisread = 'delimiter_misread';
}

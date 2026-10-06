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

namespace PHPRegex\Parser;

/**
 * What a pattern reads as a newline, as an option it opens with sets it:
 * "(*CR)", "(*LF)", "(*CRLF)", "(*ANY)", "(*ANYCRLF)" or "(*NUL)". The value
 * is the option's name. A later release may add cases.
 */
enum NewlineConvention: string
{
    case Cr = 'CR';

    case Lf = 'LF';

    case CrLf = 'CRLF';

    /**
     * Any Unicode newline sequence.
     */
    case Any = 'ANY';

    /**
     * CR, LF or CRLF.
     */
    case AnyCrLf = 'ANYCRLF';

    case Nul = 'NUL';
}

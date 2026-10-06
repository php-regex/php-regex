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
 * What "\R" matches, as an option a pattern opens with sets it:
 * "(*BSR_ANYCRLF)" or "(*BSR_UNICODE)". The value is the option's name
 * without its "BSR_" prefix. A later release may add cases.
 */
enum BsrConvention: string
{
    /**
     * CR, LF or CRLF.
     */
    case AnyCrLf = 'ANYCRLF';

    /**
     * Any Unicode newline sequence.
     */
    case Unicode = 'UNICODE';
}

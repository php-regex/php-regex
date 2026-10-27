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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\LibPcre;

function ascii_digits(string $s): bool
{
    // PCRE2 10.43 added "(?a)" and "(?D)".
    return 1 === preg_match('/(?aD)x/', $s);
}

function scan_substring(string $s): bool
{
    // PCRE2 10.45 added "(*scs:".
    return 1 === preg_match('/(a)(*scs:(1)a)/', $s);
}

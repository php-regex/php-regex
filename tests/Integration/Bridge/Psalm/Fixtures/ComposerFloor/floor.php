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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\ComposerFloor;

function short_non_atomic_lookahead(string $s): bool
{
    // PCRE2 10.35 (PHP 8.0) added "(?*"; PHP 7.3 and 7.4 refuse it.
    return 1 === preg_match('/(?*a)a/', $s);
}

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

namespace PhpRegex\Tests\Integration\Bridge\PHPStan\Fixtures;

final class NeonConfigFixture
{
    public function patterns(): void
    {
        preg_match('/(?:a)/', 'a'); // lint: redundant non-capturing group
        preg_match('/no_dot/s', 'no_dot'); // lint: useless flag
        preg_match('/(a+)+$/', 'aaa'); // ReDoS, critical
        preg_match('/(foo/', 'foo'); // refused by every PCRE2: PHPStan core reports it
        // "(?aD)" arrived in PCRE2 10.43; PHP 8.2 bundles 10.40, which refuses it at offset 2.
        preg_match('/(?aD)x/', 'x');
    }
}

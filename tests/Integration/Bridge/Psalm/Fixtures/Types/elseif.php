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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Types;

// An elseif reads its condition as an if does:
// preg_match('/(a)(b)?/', 'ab', $m) -> 1, $m = ['ab', 'a', 'b'] (PHP 8.4.28).

function call_in_an_elseif(string $s): void
{
    if ('' === $s) {
        return;
    } elseif (preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\StringLength;

// Read with maxStringLength="20": Psalm keeps a literal string shorter than
// 20 bytes, and the plugin writes a value as a literal only where Psalm
// would keep it. preg_match('/((?:aaaaa){4})/', str_repeat('a', 20), $m)
// -> 1, $m = [str_repeat('a', 20), str_repeat('a', 20)] (PHP 8.4.26).

function psalm_keeps_a_literal_below_the_limit(): void
{
    $nineteen = 'aaaaaaaaaaaaaaaaaaa';

    /**
     * @psalm-check-type-exact $nineteen = 'aaaaaaaaaaaaaaaaaaa'
     * @psalm-trace $nineteen
     */
}

function psalm_drops_a_literal_at_the_limit(): void
{
    $twenty = 'aaaaaaaaaaaaaaaaaaaa';

    /**
     * @psalm-check-type-exact $twenty = non-falsy-string
     * @psalm-trace $twenty
     */
}

function value_one_byte_below_the_limit(string $s): void
{
    if (preg_match('/((?:aaaaaa){3}a)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'aaaaaaaaaaaaaaaaaaa', 1: 'aaaaaaaaaaaaaaaaaaa'}
         * @psalm-trace $m
         */
    }
}

function value_at_the_limit(string $s): void
{
    if (preg_match('/((?:aaaaa){4})/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-falsy-string, 1: non-falsy-string}
         * @psalm-trace $m
         */
    }
}

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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Budget;

// Read with maxShapedArraySize="4": a shape of more keys than Psalm keeps is
// left to the stub.

function four_keys_are_kept(string $s): void
{
    if (preg_match('/(a)(b)(c)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'abc', 1: 'a', 2: 'b', 3: 'c'}
         * @psalm-trace $m
         */
    }
}

function five_keys_are_left_to_the_stub(string $s): void
{
    if (preg_match('/(a)(b)(c)(d)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function a_name_counts_as_a_key(string $s): void
{
    if (preg_match('/(?<x>a)(b)(c)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function optional_keys_count_too(string $s): void
{
    if (preg_match('/(a)(b)(c)(d)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

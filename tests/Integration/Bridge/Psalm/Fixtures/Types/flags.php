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

// preg_match('/(a)(b)?/', 'a', $m, PREG_OFFSET_CAPTURE) -> [['a', 0], ['a', 0]];
// with PREG_UNMATCHED_AS_NULL -> ['a', 'a', null]; with both -> [['a', 0], ['a', 0], [null, -1]] (PHP 8.4.26).

function offset_capture(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m, \PREG_OFFSET_CAPTURE)) {
        /**
         * @psalm-check-type-exact $m = array{0: list{'a'|'ab', int<0, max>}, 1: list{'a', int<0, max>}, 2?: list{'b', int<-1, max>}}
         * @psalm-trace $m
         */
    }
}

function unmatched_as_null(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m, \PREG_UNMATCHED_AS_NULL)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2: 'b'|null}
         * @psalm-trace $m
         */
    }
}

function both_flags(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
        /**
         * @psalm-check-type-exact $m = array{0: list{'a'|'ab', int<0, max>}, 1: list{'a', int<0, max>}, 2: list{'b'|null, int<-1, max>}}
         * @psalm-trace $m
         */
    }
}

function explicit_zero_flags(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m, 0)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function flags_and_offset(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m, \PREG_UNMATCHED_AS_NULL, 1)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2: 'b'|null}
         * @psalm-trace $m
         */
    }
    // After an if without an else, either branch may have run: on 'xa' from
    // offset 1 the match is kept (['a', 'a', null]), else [] (PHP 8.4.26).
    /**
     * @psalm-check-type-exact $m = array{0?: 'a'|'ab', 1?: 'a', 2?: 'b'|null}
     * @psalm-trace $m
     */

}

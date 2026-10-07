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

// The shapes below are CaptureShape::matchShape()'s, read as Psalm types,
// with no numeric-string: Psalm 6.19's type combiner reads numeric-string|'a'
// as numeric-string, so a group of digits is non-falsy-string where every
// value is truthy, else non-empty-string.

function named_groups_of_digits(string $s): void
{
    // preg_match(..., '2026-10', $m) -> ['2026-10', 'year' => '2026', '2026', 'month' => '10', '10']; on '2026-' the month keys are left out
    if (preg_match('/(?<year>\d{4})-(?<month>\d\d)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-falsy-string, year: non-falsy-string, 1: non-falsy-string, month?: non-falsy-string, 2?: non-falsy-string}
         * @psalm-trace $m
         */
    }
}

function digits_that_may_be_zero(string $s): void
{
    if (preg_match('/(\d+)-(x)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-falsy-string, 1: non-empty-string, 2: 'x'}
         * @psalm-trace $m
         */
    }
}

function digits_beside_a_fallback(string $s): void
{
    // preg_match('/(\d+)/', 'x', $m) -> 0: $v is 'none', which the type keeps (PHP 8.4.26).
    $v = preg_match('/(\d+)/', $s, $m) ? $m[1] : 'none';

    /**
     * @psalm-check-type-exact $v = non-empty-string
     *
     * @psalm-trace $v
     */
    if ('none' === $v) {
        return;
    }
}

function unknown_text(string $s): void
{
    if (preg_match('/(\w*)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: string, 1: string}
         * @psalm-trace $m
         */
    }
}

function caseless_letters(string $s): void
{
    // preg_match('/(a)/i', 'A', $m) -> ['A', 'A']
    if (preg_match('/(a)/i', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-falsy-string, 1: non-falsy-string}
         * @psalm-trace $m
         */
    }
}

function shared_names(string $s): void
{
    // preg_match('/(?J)(?<n>a)|(?<n>b)/', 'a', $m) -> ['a', 'n' => 'a', 'a']; on 'b' -> ['b', 'n' => 'b', '', 'b']
    // The pattern splits into two cases; Psalm gets the union of their shapes.
    if (preg_match('/(?J)(?<n>a)|(?<n>b)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'b', n: 'a'|'b', 1: ''|'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function split_pattern_union_of_the_cases(string $s): void
{
    // preg_match('/(a)|(b)/', 'a', $m) -> ['a', 'a']; on 'b' -> ['b', '', 'b']
    if (preg_match('/(a)|(b)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'b', 1: ''|'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function mark_verbs(string $s): void
{
    // preg_match('/a(*MARK:x)|b(*MARK:y)/', 'a', $m) -> ['a', 'MARK' => 'x']
    if (preg_match('/a(*MARK:x)|b(*MARK:y)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'b', MARK?: 'x'|'y'}
         * @psalm-trace $m
         */
    }
}

function group_named_mark_beside_a_verb(string $s): void
{
    // preg_match('/(?<MARK>a)(*MARK:m)/', 'a', $m) -> ['a', 'MARK' => 'm', 'a']
    if (preg_match('/(?<MARK>a)(*MARK:m)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a', MARK: 'a'|'m', 1: 'a'}
         * @psalm-trace $m
         */
    }

    if (preg_match('/(?<MARK>a)(*MARK:m)/', $s, $n, \PREG_OFFSET_CAPTURE)) {
        /**
         * @psalm-check-type-exact $n = array{0: list{'a', int<0, max>}, MARK: list{'a', int<0, max>}|'m', 1: list{'a', int<0, max>}}
         * @psalm-trace $n
         */
    }
}

function optional_groups(string $s): void
{
    // preg_match('/(a)?(b)?/', 'b', $m) -> ['b', '', 'b']; on 'x' -> ['']
    if (preg_match('/(a)?(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: ''|'a'|'ab'|'b', 1?: ''|'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }

    // preg_match('/(a)?b/', 'b', $m) -> ['b']: a trailing unset group is left out
    if (preg_match('/(a)?b/', $s, $n)) {
        /**
         * @psalm-check-type-exact $n = array{0: 'ab'|'b', 1?: 'a'}
         * @psalm-trace $n
         */
    }
}

function branch_reset(string $s): void
{
    if (preg_match('/(?|(a)|(b))/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'b', 1: 'a'|'b'}
         * @psalm-trace $m
         */
    }
}

function no_auto_capture(string $s): void
{
    // preg_match('/(a)(?<x>b)/n', 'ab', $m) -> ['ab', 'x' => 'b', 'b']
    if (preg_match('/(a)(?<x>b)/n', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'ab', x: 'b', 1: 'b'}
         * @psalm-trace $m
         */
    }
}

function no_group(string $s): void
{
    if (preg_match('/abc/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'abc'}
         * @psalm-trace $m
         */
    }
}

function utf8_literal(string $s): void
{
    if (preg_match('/(é)/u', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'é', 1: 'é'}
         * @psalm-trace $m
         */
    }
}

function group_no_match_sets(string $s): void
{
    // preg_match('/(?!(b))(a)/', 'a', $m) -> ['a', '', 'a']
    if (preg_match('/(?!(b))(a)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a', 1: '', 2: 'a'}
         * @psalm-trace $m
         */
    }

    // preg_match('/(?!(b))a/', 'a', $m, PREG_UNMATCHED_AS_NULL) -> ['a', null]
    if (preg_match('/(?!(b))a/', $s, $n, \PREG_UNMATCHED_AS_NULL)) {
        /**
         * @psalm-check-type-exact $n = array{0: 'a', 1: null}
         * @psalm-trace $n
         */
    }
}

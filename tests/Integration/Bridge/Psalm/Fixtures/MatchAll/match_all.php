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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\MatchAll;

// preg_match_all() writes its shape on every call that returns an int, a
// call that finds no match included: preg_match_all('/(a)(b)?/', 'x', $m) ->
// [[], [], []]; on 'a ab' -> [['a', 'ab'], ['a', 'a'], ['', 'b']]; with
// PREG_SET_ORDER on 'a ab' -> [['a', 'a'], ['ab', 'a', 'b']]; a subject that
// is not UTF-8 under /u returns false but writes [[], []] for /(a)/u (PHP 8.4.26).
//
// It returns false and leaves [] for an offset past the subject and for a
// match that ends before it starts (\K in a lookahead): the plugin types the
// call only for a pattern without \K and an offset absent or a constant <= 0.

function pattern_order_by_default(string $s): void
{
    preg_match_all('/(a)(b)?/', $s, $m);

    /**
     * @psalm-check-type-exact $m = array{0: list<'a'|'ab'>, 1: list<'a'>, 2: list<''|'b'>}
     * @psalm-trace $m
     */
}

function pattern_order_named(string $s): void
{
    preg_match_all('/(a)(b)?/', $s, $m, \PREG_PATTERN_ORDER);

    /**
     * @psalm-check-type-exact $m = array{0: list<'a'|'ab'>, 1: list<'a'>, 2: list<''|'b'>}
     * @psalm-trace $m
     */
}

function pattern_order_unmatched_as_null(string $s): void
{
    preg_match_all('/(a)(b)?/', $s, $m, \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL);

    /**
     * @psalm-check-type-exact $m = array{0: list<'a'|'ab'>, 1: list<'a'>, 2: list<'b'|null>}
     * @psalm-trace $m
     */
}

function set_order(string $s): void
{
    preg_match_all('/(a)(b)?/', $s, $m, \PREG_SET_ORDER);

    /**
     * @psalm-check-type-exact $m = list<array{0: 'a'|'ab', 1: 'a', 2?: 'b'}>
     * @psalm-trace $m
     */
}

function set_order_with_offsets(string $s): void
{
    preg_match_all('/(a)(b)?/', $s, $m, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

    /**
     * @psalm-check-type-exact $m = list<array{0: list{'a'|'ab', int<0, max>}, 1: list{'a', int<0, max>}, 2?: list{'b', int<-1, max>}}>
     * @psalm-trace $m
     */
}

function marks_in_pattern_order(string $s): void
{
    preg_match_all('/a(*MARK:x)|b(*MARK:y)/', $s, $m);

    /**
     * @psalm-check-type-exact $m = array{0: list<'a'|'b'>, MARK?: array<int, 'x'|'y'>}
     * @psalm-trace $m
     */
}

function set_order_of_a_split_pattern_merges_the_cases_key_by_key(string $s): void
{
    // preg_match_all('/(a)|(b)/', 'ab', $m, PREG_SET_ORDER) -> [['a', 'a'], ['b', '', 'b']]
    preg_match_all('/(a)|(b)/', $s, $m, \PREG_SET_ORDER);

    /**
     * @psalm-check-type-exact $m = list<array{0: 'a'|'b', 1: ''|'a', 2?: 'b'}>
     * @psalm-trace $m
     */
}

/**
 * @param non-empty-string $pattern
 */
function match_all_pattern_not_constant(string $pattern, string $s): void
{
    preg_match_all($pattern, $s, $m);

    /**
     * @psalm-check-type-exact $m = array<array-key, list<string>>
     * @psalm-trace $m
     */
}

function negative_offset(string $s): void
{
    // preg_match_all('/(a)/', 'abc', $m, 0, -10) -> 1, [['a'], ['a']]: PHP clamps the offset to 0 (PHP 8.4.26).
    preg_match_all('/(a)/', $s, $m, \PREG_PATTERN_ORDER, -10);

    /**
     * @psalm-check-type-exact $m = array{0: list<'a'>, 1: list<'a'>}
     * @psalm-trace $m
     */
}

function zero_offset_named(string $s): void
{
    // All named: Psalm 6.19.1 wrongly reports positional arguments before a named one.
    preg_match_all(pattern: '/(a)/', subject: $s, matches: $m, offset: 0);

    /**
     * @psalm-check-type-exact $m = array{0: list<'a'>, 1: list<'a'>}
     * @psalm-trace $m
     */
}

function offset_not_constant(string $s, int $offset): void
{
    // preg_match_all('/(a)/', 'abc', $m, 0, 10) -> false, $m = [] (PHP 8.4.26).
    preg_match_all('/(a)/', $s, $m, \PREG_PATTERN_ORDER, $offset);

    /**
     * @psalm-check-type-exact $m = array<array-key, list<string>>
     * @psalm-trace $m
     */
}

function constant_offset_past_the_subject(string $s): void
{
    // preg_match_all('/(a)/', 'abc', $m, 0, 10) -> false, $m = [] (PHP 8.4.26).
    preg_match_all('/(a)/', $s, $m, \PREG_PATTERN_ORDER, 10);

    /**
     * @psalm-check-type-exact $m = array<array-key, list<string>>
     * @psalm-trace $m
     */
}

function match_ending_before_it_starts(string $s): void
{
    // preg_match_all('/a(?=b\K)/', 'xab', $m) -> false, $m = [], warning
    // "Get subpatterns list failed" (PHP 8.4.26).
    @preg_match_all('/a(?=b\K)/', $s, $m);

    /**
     * @psalm-check-type-exact $m = array<array-key, list<string>>
     * @psalm-trace $m
     */
}

function keep_out_anywhere(string $s): void
{
    preg_match_all('/(a)\K(b)/', $s, $m);

    /**
     * @psalm-check-type-exact $m = array<array-key, list<string>>
     * @psalm-trace $m
     */
}

/**
 * @psalm-suppress RiskyTruthyFalsyComparison
 */
function call_as_a_condition(string $s): void
{
    // A call that finds no match writes its shape too, not []:
    // preg_match_all('/(a)/', 'x', $m) -> 0, $m = [[], []] (PHP 8.4.26).
    if (!preg_match_all('/(a)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: list<'a'>, 1: list<'a'>}
         * @psalm-trace $m
         */
    }
}

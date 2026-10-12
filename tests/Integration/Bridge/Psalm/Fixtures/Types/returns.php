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

// The callback of preg_replace_callback() receives what preg_match() writes
// on a match: preg_replace_callback('/(\d+)-(x)?/', $f, '12- 3-x') calls $f
// with ['12-', '12'] then ['3-x', '3', 'x']; with PREG_OFFSET_CAPTURE,
// preg_replace_callback('/(a)/', $f, 'a', -1, $c, PREG_OFFSET_CAPTURE) calls
// it with [['a', 0], ['a', 0]] (PHP 8.4.26).
//
// preg_split() leaves out the empty pieces under PREG_SPLIT_NO_EMPTY, and
// writes [piece, offset] pairs under PREG_SPLIT_OFFSET_CAPTURE, at least one
// without PREG_SPLIT_NO_EMPTY: preg_split('/,/', '', -1, PREG_SPLIT_NO_EMPTY)
// -> [], preg_split('/,/', '', -1, PREG_SPLIT_OFFSET_CAPTURE) -> [['', 0]],
// preg_split('/,/', 'a,,b', -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE)
// -> [['a', 0], ['b', 3]] (PHP 8.4.26).

function replace_callback_typed_parameter(string $s): void
{
    preg_replace_callback('/(\d+)-(x)?/', static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array{0: non-falsy-string, 1: non-empty-string, 2?: 'x'}
         * @psalm-trace $m
         */
        return $m[1];
    }, $s);
}

function replace_callback_untyped_parameter(string $s): void
{
    preg_replace_callback('/(\d+)-(x)?/', static function ($m): string {
        /**
         * @psalm-check-type-exact $m = array{0: non-falsy-string, 1: non-empty-string, 2?: 'x'}
         * @psalm-trace $m
         */
        return $m[1];
    }, $s);
}

function replace_callback_with_flags(string $s): void
{
    preg_replace_callback('/(a)/', static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array{0: list{'a', int<0, max>}, 1: list{'a', int<0, max>}}
         * @psalm-trace $m
         */
        return $m[1][0];
    }, $s, -1, $count, \PREG_OFFSET_CAPTURE);
}

function replace_callback_named_arguments(string $s): void
{
    preg_replace_callback(callback: static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array{0: 'a', 1: 'a'}
         * @psalm-trace $m
         */
        return $m[1];
    }, subject: $s, pattern: '/(a)/');
}

function replace_callback_around_a_quoted_text(string $x, string $s): void
{
    preg_replace_callback('/(\d+)' . preg_quote($x, '/') . '/', static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array{0: non-empty-string, 1: non-empty-string}
         * @psalm-trace $m
         */
        return $m[1];
    }, $s);
}

function replace_callback_pattern_held_by_a_variable(string $s): void
{
    $pattern = '/(a)(b)?/';
    preg_replace_callback($pattern, static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array{0: list{'a'|'ab', int<0, max>}, 1: list{'a', int<0, max>}, 2: list{'b'|null, int<-1, max>}}
         * @psalm-trace $m
         */
        return '';
    }, $s, -1, $count, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
}

function replace_callback_flags_written_by_the_call(string $s): void
{
    // An argument that writes something is analysed by Psalm once, in its turn: the plugin does not read it.
    preg_replace_callback('/(a)/', static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
        return '';
    }, $s, -1, $count, $flags = 0);
}

/**
 * @param list<string> $subjects
 */
function replace_callback_in_a_loop(array $subjects): void
{
    // Psalm analyses a loop's body more than once: the pattern has its type on the next pass.
    foreach ($subjects as $s) {
        preg_replace_callback('/(a)/', static function (array $m): string {
            /**
             * @psalm-check-type-exact $m = array{0: 'a', 1: 'a'}
             * @psalm-trace $m
             */
            return $m[1];
        }, $s);
    }
}

function replace_callback_with_a_flag_it_refuses(string $s): void
{
    // preg_match() would refuse the flag; this call reads it as no flag:
    // preg_replace_callback('/(a)/', $f, 'a', -1, $c, PREG_SET_ORDER) calls $f with ['a', 'a'] (PHP 8.4.26).
    // The plugin types only the flags preg_match() takes.
    preg_replace_callback('/(a)/', static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
        return '';
    }, $s, -1, $count, \PREG_SET_ORDER);
}

function replace_callback_around_another_call(string $x, string $s): void
{
    preg_replace_callback('/(a)' . strtolower($x) . '/', static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
        return '';
    }, $s);

    preg_replace_callback('/(a)' . preg_quote(trim($x), '/') . '/', static function (array $n): string {
        /**
         * @psalm-check-type-exact $n = array<array-key, string>
         * @psalm-trace $n
         */
        return '';
    }, $s);
}

/**
 * @param non-empty-string $pattern
 */
function replace_callback_pattern_not_constant(string $pattern, string $s): void
{
    preg_replace_callback($pattern, static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
        return $m[0];
    }, $s);
}

function replace_callback_array_of_patterns(string $s): void
{
    preg_replace_callback(['/(a)/', '/(b)/'], static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
        return $m[0];
    }, $s);
}

/**
 * @param int-mask<256, 512> $flags
 */
function replace_callback_flags_not_constant(int $flags, string $s): void
{
    preg_replace_callback('/(a)/', static function (array $m): string {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
        return '';
    }, $s, -1, $count, $flags);
}

function split_without_empty_pieces(string $s): void
{
    $parts = preg_split('/,/', $s, -1, \PREG_SPLIT_NO_EMPTY);

    /**
     * @psalm-check-type-exact $parts = false|list<non-empty-string>
     * @psalm-trace $parts
     */
}

function split_with_offsets(string $s): void
{
    $parts = preg_split('/,/', $s, -1, \PREG_SPLIT_OFFSET_CAPTURE);

    /**
     * @psalm-check-type-exact $parts = false|non-empty-list<list{string, int<0, max>}>
     * @psalm-trace $parts
     */
}

function split_with_offsets_without_empty_pieces(string $s): void
{
    $parts = preg_split('/,/', $s, -1, \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_OFFSET_CAPTURE);

    /**
     * @psalm-check-type-exact $parts = false|list<list{non-empty-string, int<0, max>}>
     * @psalm-trace $parts
     */
}

function split_with_delimiters(string $s): void
{
    $parts = preg_split('/(,)/', $s, -1, \PREG_SPLIT_DELIM_CAPTURE);

    /**
     * @psalm-check-type-exact $parts = false|non-empty-list<string>
     * @psalm-trace $parts
     */
}

/**
 * @param int-mask<1, 2, 4> $flags
 */
function split_flags_not_constant(int $flags, string $s): void
{
    $parts = preg_split('/,/', $s, -1, $flags);

    /**
     * @psalm-check-type-exact $parts = false|list<list{string, int}|string>
     * @psalm-trace $parts
     */
}

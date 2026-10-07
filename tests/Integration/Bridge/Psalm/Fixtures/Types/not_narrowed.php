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

// What the plugin leaves to Psalm's preg_match() stub. Psalm reads a custom
// assertion of a call where the call is the condition itself or negated; a
// silenced call or a result kept in a variable is not narrowed. A call
// compared with anything is not narrowed either: preg_match() returns 0, not
// false, when nothing matches, so "!== false" holds on a call that left [].
// A pattern or flags whose value is not one constant, and unpacked
// arguments, are not read at all.

function identical_to_one(string $s): void
{
    if (1 === preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function not_identical_to_zero(string $s): void
{
    if (0 !== preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function greater_than_zero(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m) > 0) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function identical_to_false_with_an_early_exit(string $s): void
{
    if (false === preg_match('/(a)(b)?/', $s, $m)) {
        return;
    }

    // preg_match('/(a)(b)?/', 'x', $m) -> 0, $m = []: past the exit, no match is possible too (PHP 8.4.26).
    /**
     * @psalm-check-type-exact $m = array<array-key, string>
     * @psalm-trace $m
     */
}

function not_identical_to_false(string $s): void
{
    // preg_match('/(a)(b)?/', 'x', $m) -> 0, $m = [] (PHP 8.4.26): 0 !== false.
    if (false !== preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

/**
 * Psalm itself reports that the call never returns true.
 *
 * @psalm-suppress RedundantConditionGivenDocblockType
 */
function not_identical_to_true(string $s): void
{
    // preg_match('/(a)(b)?/', 'a', $m) -> 1, $m = ['a', 'a'] (PHP 8.4.26): 1 !== true.
    if (true !== preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

/**
 * Psalm itself reports that the call never returns true.
 *
 * @psalm-suppress DocblockTypeContradiction
 */
function identical_to_true_else_branch(string $s): void
{
    // preg_match('/(a)(b)?/', 'a', $m) -> 1, $m = ['a', 'a'] (PHP 8.4.26): the else branch runs on a match.
    if (true === preg_match('/(a)(b)?/', $s, $m)) {
        return;
    }
    /**
     * @psalm-check-type-exact $m = array<array-key, string>
     * @psalm-trace $m
     */

}

function silenced_call(string $s): void
{
    if (@preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function result_kept_in_a_variable(string $s): void
{
    $found = preg_match('/(a)(b)?/', $s, $m);
    if ($found) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function result_ignored(string $s): void
{
    preg_match('/(a)(b)?/', $s, $m);

    /**
     * @psalm-check-type-exact $m = array<array-key, string>
     * @psalm-trace $m
     */
}

/**
 * @param non-empty-string $pattern
 */
function pattern_not_constant(string $pattern, string $s): void
{
    if (preg_match($pattern, $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function pattern_one_of_two_literals(bool $first, string $s): void
{
    $pattern = $first ? '/(a)/' : '/(b)/';
    if (preg_match($pattern, $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

/**
 * @param int-mask<256, 512> $flags
 */
function flags_not_constant(int $flags, string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m, $flags)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, list{null|string, int<-1, max>}|null|string>
         * @psalm-trace $m
         */
    }
}

/**
 * @param list{0|256} $more
 */
function unpacked_arguments(array $more, string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m, ...$more)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, list{string, int<-1, max>}|string>
         * @psalm-trace $m
         */
    }
}

function call_on_the_left_of_a_comparison(string $s): void
{
    // preg_match('/(a)(b)?/', 'x', $m) -> 0, $m = [] (PHP 8.4.26): 0 !== false, the operand order aside.
    if (preg_match('/(a)(b)?/', $s, $m) !== false) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

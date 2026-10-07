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

// Where preg_match() returned 1, $matches holds the pattern's shape; where it
// did not, a pattern that compiles leaves []: no match writes [], and a match
// error (a backtrack limit, a subject that is not UTF-8 under /u) writes []
// too. preg_match('/(a)(b)?/', 'a', $m) -> ['a', 'a']; on 'ab' -> ['ab', 'a', 'b'];
// on 'x' -> [] (PHP 8.4.26).

function bare_call(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
    // After an if without an else, either branch may have run: on 'a' the
    // match is kept (['a', 'a']), on 'x' it is [] (PHP 8.4.26).
    /**
     * @psalm-check-type-exact $m = array{0?: 'a'|'ab', 1?: 'a', 2?: 'b'}
     * @psalm-trace $m
     */

}

function negated_call_with_early_return(string $s): void
{
    if (!preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<never, never>
         * @psalm-trace $m
         */
        return;
    }

    /**
     * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
     * @psalm-trace $m
     */
}

function ternary_condition(string $s): void
{
    $first = preg_match('/(a)(b)?/', $s, $m) ? $m[1] : null;

    /**
     * @psalm-check-type-exact $first = 'a'|null
     * @psalm-trace $first
     */
}

function assignment_in_the_condition(string $s): void
{
    if ($found = preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function call_on_the_left_of_and(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m) && '' !== $s) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function negated_call_on_the_left_of_or(string $s): void
{
    if (!preg_match('/(a)(b)?/', $s, $m) || '' === $s) {
        return;
    }

    /**
     * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
     * @psalm-trace $m
     */
}

function call_cast_to_bool(string $s): void
{
    if ((bool) preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function negation_compared_with_false(string $s): void
{
    // !preg_match(...) is a bool: comparing it with false reads the call's truth.
    if (false === !preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function named_arguments_in_any_order(string $s): void
{
    if (preg_match(subject: $s, pattern: '/(a)(b)?/', matches: $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function named_flags_first(string $s): void
{
    if (preg_match(flags: \PREG_UNMATCHED_AS_NULL, subject: $s, matches: $m, pattern: '/(a)(b)?/')) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2: 'b'|null}
         * @psalm-trace $m
         */
    }
}

function variable_holding_one_literal(string $s): void
{
    $pattern = '/(a)(b)?/';
    if (preg_match($pattern, $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function fully_qualified_call(string $s): void
{
    if (\preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

function other_delimiters(string $s): void
{
    if (preg_match('#(a)(b)?#', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }

    if (preg_match('{(a)(b)?}', $s, $n)) {
        /**
         * @psalm-check-type-exact $n = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $n
         */
    }
}

function call_on_the_left_of_or_with_early_return(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m) || '' === $s) {
        return;
    }

    // Past the exit, no match and a non-empty subject:
    // preg_match('/(a)(b)?/', 'x', $m) -> 0, $m = [] (PHP 8.4.26).
    /**
     * @psalm-check-type-exact $m = array<never, never>
     * @psalm-trace $m
     */
}

function function_name_in_any_case(string $s): void
{
    // PHP resolves function names case-insensitively:
    // PREG_MATCH('/(a)(b)?/', 'ab', $m) -> 1, $m = ['ab', 'a', 'b'] (PHP 8.4.26).
    if (PREG_MATCH('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

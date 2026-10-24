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

// preg_quote() of literals is a literal: preg_quote('a.b', '/') -> 'a\.b',
// preg_quote('1+1=2') -> '1\+1\=2' (PHP 8.4.26). A pattern built from
// literals and such calls is a literal, read as any other.
//
// preg_quote() of anything else adds no group where its text is read as
// literal characters: preg_match() returning 1 types $matches from the
// rest of the pattern. Where the call is false, $matches keeps Psalm's type:
// the quoted text may hold the delimiter, and a pattern that does not
// compile leaves $matches as it was.

function quoted_literal(): void
{
    $quoted = preg_quote('1+1=2');

    /*
     * @psalm-check-type-exact $quoted = '1\+1\=2'
     * @psalm-trace $quoted
     */
}

function quoted_literal_with_a_delimiter(string $s): void
{
    // preg_match('/^(a\.b)$/', 'a.b', $m) -> 1, $m = ['a.b', 'a.b'] (PHP 8.4.26).
    if (preg_match('/^('.preg_quote('a.b', '/').')$/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array{0: 'a.b', 1: 'a.b'}
         * @psalm-trace $m
         */
    }
}

function subject_of_a_quoted_literal(string $s): void
{
    if (preg_match('/^'.preg_quote('a.b', '/').'$/D', $s)) {
        /*
         * @psalm-check-type-exact $s = 'a.b'
         * @psalm-trace $s
         */
    }
}

function quoted_variable(string $x, string $s): void
{
    // preg_match('/^(\d+)-' . preg_quote('x.y', '/') . '$/', '12-x.y', $m) -> 1, $m = ['12-x.y', '12'] (PHP 8.4.26).
    if (preg_match('/^(\d+)-'.preg_quote($x, '/').'$/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array{0: non-falsy-string, 1: non-empty-string}
         * @psalm-trace $m
         */
    }
}

function quoted_variable_inside_a_group(string $x, string $s): void
{
    // The quoted text may be empty: preg_match('/(' . preg_quote('', '/') . ')/', 'a', $m) -> 1, $m = ['', ''] (PHP 8.4.26).
    if (preg_match('/('.preg_quote($x, '/').')/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array{0: string, 1: string}
         * @psalm-trace $m
         */
    }
}

function quoted_variable_where_the_call_is_false(string $x, string $s): void
{
    // An unquoted "/" ends the pattern: preg_match('/(a)' . preg_quote('/x') . '/', 'a', $m)
    // fails to compile, returns false and leaves $m as it was (PHP 8.4.26).
    if (!preg_match('/(a)'.preg_quote($x).'/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
        return;
    }

    /*
     * @psalm-check-type-exact $m = array{0: non-falsy-string, 1: 'a'}
     * @psalm-trace $m
     */
}

function quoted_variable_in_a_class(string $x, string $s): void
{
    // Inside a class the quoted text is a set of characters, not a string.
    if (preg_match('/[a'.preg_quote($x, '/').'](b)/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_inside_braces(string $x, string $s): void
{
    // Digits make a quantifier: preg_match('/(a){' . preg_quote('0') . '}b/', 'b', $m) -> 1, $m = ['b'] (PHP 8.4.26).
    if (preg_match('/(a){'.preg_quote($x, '/').'}b/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_before_a_quantifier(string $x, string $s): void
{
    // An empty quoted text leaves the quantifier to the group:
    // preg_match('/(a)' . preg_quote('') . '{0}b/', 'b', $m) -> 1, $m = ['b'] (PHP 8.4.26).
    if (preg_match('/(a)'.preg_quote($x, '/').'{0}b/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_opening_a_group(string $x, string $s): void
{
    // An empty quoted text makes a group that does not capture:
    // preg_match('/(' . preg_quote('') . '?:a)/', 'a', $m) -> 1, $m = ['a'] (PHP 8.4.26).
    if (preg_match('/('.preg_quote($x, '/').'?:a)/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_after_an_escape(string $x, string $s): void
{
    // \Q quotes what follows: preg_match('/\\' . preg_quote('Q') . '(a)/', '(a)', $m) -> 1, $m = ['(a)'] (PHP 8.4.26).
    if (preg_match('/\\'.preg_quote($x, '/').'(a)/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_after_a_hex_escape(string $x, string $s): void
{
    // Its digits continue the escape: preg_match('/(a)\x' . preg_quote('41', '/') . '/', 'aA', $m) -> 1, $m = ['aA', 'a'] (PHP 8.4.26).
    if (preg_match('/(a)\x'.preg_quote($x, '/').'/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_in_a_quoted_run(string $x, string $s): void
{
    // preg_quote('\E') -> '\\E', which ends \Q: preg_match('/\Q' . preg_quote('\E') . '(a)\E/', '\a', $m)
    // -> 1, $m = ['\a', 'a'] (PHP 8.4.26).
    if (preg_match('/\Q'.preg_quote($x, '/').'(a)\E/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_in_an_invalid_pattern(string $x, string $s): void
{
    // Never reported: the pattern is not known.
    if (preg_match('/('.preg_quote($x, '/').'/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_in_a_lookbehind(string $x, string $s): void
{
    // A quoted text of each length makes a valid lookbehind, any string does not:
    // the call is left to Psalm, and nothing is reported.
    if (preg_match('/(?<='.preg_quote($x, '/').')(a)/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_opening_the_pattern(string $x, string $s): void
{
    // The delimiter must open a literal part.
    if (preg_match(preg_quote($x).'(a)/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function quoted_variable_without_matches(string $x, string $s): void
{
    // The subject is not narrowed: the pattern is not known.
    if (preg_match('/(a)'.preg_quote($x, '/').'/', $s)) {
        /*
         * @psalm-check-type-exact $s = string
         * @psalm-trace $s
         */
    }
}

function quoted_variable_after_preg_match_all(string $x, string $s): void
{
    // preg_match_all() writes $matches only for a pattern that compiles.
    preg_match_all('/(a)'.preg_quote($x, '/').'/', $s, $m);

    /*
     * @psalm-check-type-exact $m = array<array-key, list<string>>
     * @psalm-trace $m
     */
}

function concatenation_of_another_call(string $x, string $s): void
{
    if (preg_match('/(a)'.strtolower($x).'/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

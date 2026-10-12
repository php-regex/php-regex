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

// A group that can capture only a few strings holds them as literals, and
// so does a subject the pattern matches only when it is one of a few
// strings, up to 16 of them. Without /u, a caseless pattern, a shorthand
// class or a POSIX class reads the character tables of the locale PHP runs
// under: such a subject keeps the facts of its pattern.

function bounded_repeat_group(string $s): void
{
    // preg_match('/x(a{1,3})/', 'xaa', $m) -> ['xaa', 'aa'] (PHP 8.4.26).
    if (preg_match('/x(a{1,3})/', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array{0: 'xa'|'xaa'|'xaaa', 1: 'a'|'aa'|'aaa'}
         * @psalm-trace $m
         */
    }
}

function caseless_group(string $s): void
{
    // preg_match('/(ab)/i', 'aB', $m) -> ['aB', 'aB'] (PHP 8.4.26).
    if (preg_match('/(ab)/i', $s, $m)) {
        /*
         * @psalm-check-type-exact $m = array{0: non-falsy-string, 1: non-falsy-string}
         * @psalm-trace $m
         */
    }
}

function subject_one_of_two_words(string $s): void
{
    // preg_match('/^(?:yes|no)$/D', 'no') -> 1, on "no\n" -> 0 under D (PHP 8.4.26).
    if (preg_match('/^(?:yes|no)$/D', $s)) {
        /*
         * @psalm-check-type-exact $s = 'no'|'yes'
         * @psalm-trace $s
         */
    }
    // preg_match('/^(?:yes|no)$/D', 'maybe') -> 0 (PHP 8.4.26): any other string.
    /*
     * @psalm-check-type-exact $s = string
     * @psalm-trace $s
     */

}

function subject_ending_at_the_very_end(string $s): void
{
    // \z matches at the very end only: preg_match('/^(?:yes|no)\z/', "no\n") -> 0 (PHP 8.4.26).
    if (preg_match('/^(?:yes|no)\z/', $s)) {
        /*
         * @psalm-check-type-exact $s = 'no'|'yes'
         * @psalm-trace $s
         */
    }
}

function subject_of_a_class_repeated(string $s): void
{
    // preg_match('/^[ab]{2}$/D', 'ba') -> 1 (PHP 8.4.26).
    if (preg_match('/^[ab]{2}$/D', $s)) {
        /*
         * @psalm-check-type-exact $s = 'aa'|'ab'|'ba'|'bb'
         * @psalm-trace $s
         */
    }
}

function subject_caseless_in_utf_mode(string $s): void
{
    // Under /iu, k folds with U+212A KELVIN SIGN:
    // preg_match('/^(?:ok)$/Diu', "o\u{212A}") -> 1 (PHP 8.4.26, PCRE2 10.49).
    if (preg_match('/^(?:ok)$/Diu', $s)) {
        /*
         * @psalm-check-type-exact $s = 'OK'|'Ok'|'OK'|'oK'|'ok'|'oK'
         * @psalm-trace $s
         */
    }
}

function subject_caseless_without_utf_mode(string $s): void
{
    // The locale's tables decide what folds without /u: the subject keeps its facts.
    if (preg_match('/^(?:on|off)$/Di', $s)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

function subject_of_a_shorthand_without_utf_mode(string $s): void
{
    // After setlocale(LC_CTYPE, 'fr_FR.ISO8859-1'), preg_match('/^[\w-]$/D', "\xE9") -> 1;
    // under the C locale -> 0 (PHP 8.4.26, macOS).
    if (preg_match('/^[\w-]$/D', $s)) {
        /*
         * @psalm-check-type-exact $s = non-empty-string
         * @psalm-trace $s
         */
    }
}

function subject_past_sixteen_strings(string $s): void
{
    // 20 strings, from 'a' to twenty a's.
    if (preg_match('/^a{1,20}$/D', $s)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

function subject_not_anchored(string $s): void
{
    // preg_match('/(?:yes|no)/', 'not now') -> 1 (PHP 8.4.26).
    if (preg_match('/(?:yes|no)/', $s)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

function subject_of_branches_each_anchored(string $s): void
{
    // preg_match('/^yes$|^no$/D', 'no') -> 1, on 'say no' -> 0 (PHP 8.4.26).
    if (preg_match('/^yes$|^no$/D', $s)) {
        /**
         * @psalm-check-type-exact $s = 'no'|'yes'
         * @psalm-trace $s
         */
    }
}

function subject_of_a_branch_not_anchored(string $s): void
{
    // preg_match('/^yes$|no$/D', 'say no') -> 1 (PHP 8.4.26).
    if (preg_match('/^yes$|no$/D', $s)) {
        /**
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

function subject_of_an_atomic_group(string $s): void
{
    // The solver does not read an atomic group as a set of strings, though
    // the call below returns 1 on 'abc' and on 'ac' only (PHP 8.4.26).
    if (preg_match('/^(?>ab|a)c$/D', $s)) {
        /**
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

/**
 * @param 'maybe'|'yes' $s
 */
function subject_already_a_literal(string $s): void
{
    // Psalm would take the strings out of $s where the call is false, and a
    // match error (a backtrack limit) returns false on 'yes' too: the subject
    // keeps its literals on both sides.
    if (preg_match('/^(?:yes|no)$/D', $s)) {
        /*
         * @psalm-check-type-exact $s = 'maybe'|'yes'
         * @psalm-trace $s
         */
    }
    /*
     * @psalm-check-type-exact $s = 'maybe'|'yes'
     * @psalm-trace $s
     */

}

function subject_with_an_offset(string $s): void
{
    // From an offset, \G matches where it does not from the start:
    // preg_match('/\G(?:yes|no)$/D', 'xyes', $m, 0, 1) -> 1, without the offset -> 0 (PHP 8.4.26).
    if (preg_match('/\G(?:yes|no)$/D', $s, offset: 1)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

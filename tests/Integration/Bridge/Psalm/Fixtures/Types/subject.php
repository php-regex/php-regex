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

// Where preg_match() returned 1, a string subject is narrowed: to
// non-empty-string when the pattern never matches '', to non-falsy-string
// when it matches neither '' nor '0'. Where it did not, the subject keeps
// its type: any string may fail to match. A call that types $matches
// leaves its subject as it is: Psalm reads a false condition as the
// negation of what holds where it is true, and on two variables that
// negation would lose the [] of $matches.

function subject_never_empty(string $s): void
{
    // preg_match('/\d/', '0') -> 1, preg_match('/\d/', '') -> 0 (PHP 8.4.26).
    if (preg_match('/\d/', $s)) {
        /*
         * @psalm-check-type-exact $s = non-empty-string
         * @psalm-trace $s
         */
    }
}

function subject_never_falsy_from_one_byte(string $s): void
{
    // preg_match('/a+/', '0') -> 0, preg_match('/a+/', '') -> 0 (PHP 8.4.26).
    if (preg_match('/a+/', $s)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

function subject_never_falsy_from_two_bytes(string $s): void
{
    // preg_match('/ab/', 'ab') -> 1; neither '' nor '0' holds two bytes (PHP 8.4.26).
    if (preg_match('/ab/', $s)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

function subject_may_be_empty(string $s): void
{
    // preg_match('/x*/', '') -> 1 (PHP 8.4.26).
    if (preg_match('/x*/', $s)) {
        /*
         * @psalm-check-type-exact $s = string
         * @psalm-trace $s
         */
    }
}

function lookbehind_reads_before_the_match(string $s): void
{
    // The match is empty, yet the subject holds the "a" the lookbehind reads:
    // preg_match('/(?<=a)/', '') -> 0, preg_match('/(?<=a)/', '0') -> 0, on 'a' -> 1 (PHP 8.4.26).
    if (preg_match('/(?<=a)/', $s)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

function digits_anchored_at_both_ends(string $s): void
{
    // preg_match('/^\d+$/', '0') -> 1, and on "12\n" -> 1, is_numeric("12\n") -> true
    // (PHP 8.4.26). No numeric-string: Psalm 6.20 combines numeric-string|'none'
    // into numeric-string, which a later "? $s : 'none'" would make false.
    if (preg_match('/^\d+$/', $s)) {
        /*
         * @psalm-check-type-exact $s = non-empty-string
         * @psalm-trace $s
         */
    }
}

function negated_call_with_early_return(string $s): void
{
    if (!preg_match('/a+/', $s)) {
        // preg_match('/a+/', '') -> 0 (PHP 8.4.26): any string may get here.
        /*
         * @psalm-check-type-exact $s = string
         * @psalm-trace $s
         */
        return;
    }

    /*
     * @psalm-check-type-exact $s = non-falsy-string
     * @psalm-trace $s
     */
}

function non_empty_subject_narrowed_further(string $s): void
{
    if ('' === $s) {
        return;
    }

    if (preg_match('/a+/', $s)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }
}

function subject_with_an_offset(string $s, int $offset): void
{
    // From offset 1, \G$ matches '0' at its end, which it does not from 0:
    // preg_match('/\G$/', '0', $m, 0, 1) -> 1, preg_match('/\G$/', '0') -> 0;
    // past the end of '' the call fails: preg_match('/\G$/', '', $m, 0, 1) -> false (PHP 8.4.26).
    if (preg_match('/\G$/', $s, offset: $offset)) {
        /*
         * @psalm-check-type-exact $s = string
         * @psalm-trace $s
         */
    }

    if (preg_match('/\G$/', $s, offset: 1)) {
        /*
         * @psalm-check-type-exact $s = non-empty-string
         * @psalm-trace $s
         */
    }
}

/**
 * @param int|string $s
 *
 * @psalm-suppress PossiblyInvalidArgument
 */
function subject_not_a_string($s): void
{
    // An int subject is read as a string: a narrowing to a string type would drop the int.
    if (preg_match('/a+/', $s)) {
        /*
         * @psalm-check-type-exact $s = int|string
         * @psalm-trace $s
         */
    }
}

function comparisons_read_no_condition(string $s): void
{
    // preg_match('/a+/', 'x') -> 0 (PHP 8.4.26): 0 !== false.
    if (false !== preg_match('/a+/', $s)) {
        /*
         * @psalm-check-type-exact $s = string
         * @psalm-trace $s
         */
    }

    if (1 === preg_match('/a+/', $s)) {
        /*
         * @psalm-check-type-exact $s = string
         * @psalm-trace $s
         */
    }
}

function subject_kept_where_matches_is_typed(string $s): void
{
    if (preg_match('/(a+)/', $s, $m)) {
        /*
         * @psalm-check-type-exact $s = string
         * @psalm-trace $s
         */
    }
    /*
     * @psalm-check-type-exact $m = array<never, never>
     * @psalm-trace $m
     */

}

/**
 * @param array{2: string}   $m
 * @param int-mask<256, 512> $flags
 */
function subject_read_from_the_matches_variable(array $m, int $flags): void
{
    // $m holds the matches once the call returns: the subject read from it is gone.
    if (preg_match('/(\d)/', $m[2], $m, $flags)) {
        /*
         * @psalm-check-type-exact $m = array<array-key, list{null|string, int<-1, max>}|null|string>
         * @psalm-trace $m
         */
    }
}

/**
 * @param array{s: string} $a
 */
function matches_written_to_a_property(string $s, array $a, \stdClass $o): void
{
    // A subject variable is apart from the property the matches go to.
    if (preg_match('/a+/', $s, $o->m)) {
        /*
         * @psalm-check-type-exact $s = non-falsy-string
         * @psalm-trace $s
         */
    }

    // An element may be read from what the call writes: it is left alone.
    if (preg_match('/a+/', $a['s'], $o->m)) {
        /*
         * @psalm-check-type-exact $a = array{s: string}
         * @psalm-trace $a
         */
    }
}

/**
 * @param non-empty-string $pattern
 */
function pattern_not_constant(string $pattern, string $s): void
{
    if (preg_match($pattern, $s)) {
        /*
         * @psalm-check-type-exact $s = string
         * @psalm-trace $s
         */
    }
}

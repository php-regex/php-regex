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

// Two calls of a && or a || write the same $m: where the right one ran, $m
// holds what it wrote, where it did not, what the left one wrote. For
// ('x', 'ab'), preg_match('/(x)/', 'x', $m) -> 1 and
// preg_match('/(a)(b)?/', 'ab', $m) -> 1 leave $m = ['ab', 'a', 'b']; for
// ('y', 'ab'), preg_match('/(x)/', 'y', $m) -> 0 leaves $m = [] (PHP 8.4.28).
// Psalm reads the left one's condition of the value the right one replaces:
// past the condition, and where the right one may not have run, $m keeps
// Psalm's type.

function written_twice_past_an_early_return(string $s, string $t): void
{
    if (preg_match('/(x)/', $s, $m) && !preg_match('/(a)(b)?/', $t, $m)) {
        return;
    }

    /**
     * @psalm-check-type-exact $m = array<array-key, string>
     * @psalm-trace $m
     */
}

function written_twice_in_the_else_of_an_and(string $s, string $t): void
{
    if (preg_match('/(x)/', $s, $m) && !preg_match('/(a)(b)?/', $t, $m)) {
        // Both ran: $m is [].
    } else {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

function written_twice_in_an_or(string $s, string $t): void
{
    if (!preg_match('/(x)/', $s, $m) || preg_match('/(a)(b)?/', $t, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

// Where both are true, the right one ran last: $m is its shape.
function written_twice_in_an_and(string $s, string $t): void
{
    if (preg_match('/(x)/', $s, $m) && preg_match('/(a)(b)?/', $t, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

// A right operand that writes another variable leaves the left one's
// condition: $m is its shape where both are true.
function written_elsewhere_in_an_and(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m) && ($trimmed = trim($s)) !== $s) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

// An assignment and a destructuring write $m again too: where the right
// operand ran, $m holds what it assigned.

/**
 * @param array<array-key, string> $fallback
 */
function written_again_by_an_assignment(string $s, array $fallback): void
{
    if (preg_match('/(x)/', $s, $m) && ($m = $fallback)) {
        /**
         * @psalm-check-type-exact $m = non-empty-array<array-key, string>
         * @psalm-trace $m
         */
    }
}

/**
 * @param array{array<array-key, string>, int} $pair
 */
function written_again_by_a_destructuring(string $s, array $pair): void
{
    if (preg_match('/(x)/', $s, $m) && ([$m, $count] = $pair) && $count > 1) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
}

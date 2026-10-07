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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Parity;

// What Psalm's preg_* stubs say besides the type of $matches: purity, a
// falsable return, the pattern parameter's type, templated flags,
// and taint flows. Nothing here reads $matches, so the plugin may add only
// its own issues, and must keep every taint Psalm finds.

/**
 * @psalm-taint-source input
 */
function request_input(): string
{
    return '';
}

/**
 * @psalm-pure
 */
function pure_match(string $s): bool
{
    return 1 === preg_match('/(a)(b)?/', $s, $m);
}

/**
 * @psalm-pure
 */
function pure_match_all(string $s): int
{
    return (int) preg_match_all('/(a)(b)?/', $s, $m);
}

function falsable_match_all_returned_as_int(string $s): int
{
    return preg_match_all('/(a)(b)?/', $s, $m);
}

function falsable_match_returned_as_int(string $s): int
{
    return preg_match('/(a)(b)?/', $s, $m);
}

function return_types(string $s): void
{
    $found = preg_match('/(a)(b)?/', $s, $m);
    $count = preg_match_all('/(a)(b)?/', $s, $n, \PREG_SET_ORDER);

    /** @psalm-trace $found */
    /** @psalm-trace $count */
}

function pattern_parameter_type(string $pattern, string $s): void
{
    preg_match($pattern, $s, $m);
    preg_match_all($pattern, $s, $n);
}

function flags_of_the_wrong_type(string $s): void
{
    preg_match('/(a)/', $s, $m, 'x');
    preg_match_all('/(a)/', $s, $n, 'x');
}

function arguments_php_refuses(string $s): void
{
    // PHP throws on both: "preg_match(): Argument #4 ($flags) must be a PREG_* constant" and
    // "preg_match() expects at most 5 arguments, 6 given" (PHP 8.4.26). The plugin leaves them to Psalm.
    preg_match('/(a)/', $s, $m, \PREG_SET_ORDER);
    preg_match('/(a)/', $s, $n, 0, 0, 1);
}

function invalid_pattern(string $s): void
{
    preg_match_all('/(/', $s, $m); // InvalidRegexPattern
}

function tainted_subject_through_replace(): void
{
    echo preg_replace('/a/', 'b', request_input());
}

function tainted_subject_through_replace_callback(): void
{
    echo preg_replace_callback('/(a)/', static fn (array $m): string => 'b', request_input());
}

function tainted_subject_through_split(): void
{
    foreach (preg_split('/,/', request_input()) ?: [] as $part) {
        echo $part;
    }
}

function tainted_subject_through_grep(): void
{
    foreach (preg_grep('/a/', [request_input()]) as $kept) {
        echo $kept;
    }
}

function tainted_subject_through_match(): void
{
    if (preg_match('/(a+)/', request_input(), $m)) {
        echo $m[1];
    }
}

function tainted_subject_through_match_all(): void
{
    preg_match_all('/(a+)/', request_input(), $m);
    echo implode(',', $m[1]);
}

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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Invalid;

// A line ending in "// InvalidRegexPattern: <pattern>" carries exactly one
// InvalidRegexPattern issue, on that pattern argument; no other line carries
// one.

function every_global_preg_function(string $s): void
{
    preg_match('/(/', $s); // InvalidRegexPattern: '/(/'
    preg_match_all('/a{2,1}/', $s); // InvalidRegexPattern: '/a{2,1}/'
    preg_replace('/[a/', 'b', $s); // InvalidRegexPattern: '/[a/'
    preg_replace_callback('/(?<n>a)(?<n>b)/', static fn (array $m): string => $m[0], $s); // InvalidRegexPattern: '/(?<n>a)(?<n>b)/'
    preg_replace_callback_array([
        '/a/' => static fn (): string => 'b',
        '/a/Q' => static fn (): string => 'b', // InvalidRegexPattern: '/a/Q'
    ], $s);
    preg_split('/(/', $s); // InvalidRegexPattern: '/(/'
    preg_grep('/[a/', [$s]); // InvalidRegexPattern: '/[a/'
    preg_filter('/a{2,1}/', 'b', $s); // InvalidRegexPattern: '/a{2,1}/'
}

function named_pattern_argument(string $s): void
{
    preg_match(subject: $s, pattern: '/(/'); // InvalidRegexPattern: '/(/'
}

function pattern_held_by_a_variable(string $s): void
{
    $pattern = '/(/';
    preg_match($pattern, $s); // InvalidRegexPattern: $pattern
}

function valid_patterns_raise_nothing(string $s): void
{
    preg_match('/(a)(b)?/', $s);
    preg_replace('/a/', 'b', $s);
    preg_split('/,/', $s);
}

/**
 * @param non-empty-string $pattern
 */
function pattern_not_constant_is_not_judged(string $pattern, string $s): void
{
    preg_match($pattern, $s);
}

function a_pattern_the_plugin_reports_is_not_narrowed(string $s): void
{
    // A pattern that does not compile leaves $matches as it was:
    // $m = 'keep'; @preg_match('/(/', 'x', $m) -> false, $m === 'keep' (PHP 8.4.26).
    // The else branch is not [] then, and the plugin asserts nothing.
    if (preg_match('/(/', $s, $m)) { // InvalidRegexPattern: '/(/'
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
    }
    /**
     * @psalm-check-type-exact $m = array<array-key, string>
     * @psalm-trace $m
     */

}

/**
 * @param non-empty-string $p
 */
function each_constant_element_of_a_pattern_list(string $s, string $p): void
{
    // preg_replace(['/a/', '/[a/', $p], 'b', 'a') -> null: "missing terminating ]
    // for character class at offset 2" (PHP 8.4.26). An element that is not
    // constant is not judged.
    preg_replace([
        '/a/',
        '/[a/', // InvalidRegexPattern: '/[a/'
        $p,
    ], 'b', $s);
}

/**
 * @psalm-suppress InvalidArgument
 */
function pattern_list_where_one_pattern_is_taken(string $s): void
{
    // preg_match(['/(/'], 'a') -> TypeError: "Argument #1 ($pattern) must be of
    // type string, array given" (PHP 8.4.26): no pattern is compiled.
    preg_match(['/(/'], $s);
}

function unpacked_arguments_are_not_read(string $s): void
{
    // Spread, the replacement takes the second place: only '/a/' is a
    // pattern, and it compiles (PHP 8.4.26).
    preg_replace(...['/a/', 'x', $s]);
}

/**
 * @psalm-suppress InvalidNamedArgument
 */
function unknown_named_argument(string $s): void
{
    // preg_match(pattern: '/(/', subject: 'a', bogus: 1) -> Error: "Unknown
    // named parameter $bogus" (PHP 8.4.26): the call never runs.
    preg_match(pattern: '/(/', subject: $s, bogus: 1);
}

/**
 * @psalm-suppress TooFewArguments
 */
function pattern_argument_missing(string $s): void
{
    // preg_match(subject: 'a') -> ArgumentCountError: "Argument #1 ($pattern)
    // not passed" (PHP 8.4.26).
    preg_match(subject: $s);
}

function callables_are_not_read(string $s): void
{
    $byName = 'preg_match';
    $byName('/(/', $s);

    $closure = preg_match(...);
    $closure('/(/', $s);
}

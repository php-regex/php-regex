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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Wrappers;

use Composer\Pcre\Preg;
use Illuminate\Support\Str;
use Nette\Utils\Strings;
use Spatie\Regex\Regex;

// A line ending in "// attempt" carries one RegexRedos issue; one ending in
// "// loop: <function>" one RegexRedosSearch issue, the cost of the loop of
// that preg function; "// loop: lazy" what the preset prices a lazy
// Strings::matchAll() as. No other line carries a finding.

/**
 * The preg calls the wrappers stand for: each finding of a wrapper is
 * worded as the one of the preg call it runs.
 *
 * @param array<string, callable(array<array-key, string>): string> $map
 */
function the_preg_calls(string $s, array $map): void
{
    preg_match('/^(\w+\s?)+$/', $s); // attempt
    preg_match_all('/(?:a+\d)?/', $s); // loop: preg_match_all
    preg_match_all('/(?:a+\d)?/u', $s); // loop: preg_match_all
    preg_replace('/(?:a+\d)?/', '', $s); // loop: preg_replace
    preg_replace_callback('/(?:a+\d)?/', static fn (array $m): string => '', $s); // loop: preg_replace_callback
    preg_replace_callback_array(['/(?:a+\d)?/' => static fn (array $m): string => ''], $s); // loop: preg_replace_callback_array
    preg_split('/(?:a+\d)?/', $s); // loop: preg_split
}

function one_attempt(string $s): void
{
    Preg::match('/^(\w+\s?)+$/', $s); // attempt
    Strings::match($s, '/^(\w+\s?)+$/'); // attempt
    Regex::match('/^(\w+\s?)+$/', $s); // attempt
    Str::isMatch('/^(\w+\s?)+$/', $s); // attempt
    // The subject of a wrapper is not told apart: a constant one is reported.
    Preg::match('/^(\w+\s?)+$/', 'constant subject'); // attempt
    // The first match ends the search.
    Preg::match('/(?:a+\d)?/', $s);
    Strings::match($s, '/(?:a+\d)?/');
    Regex::match('/(?:a+\d)?/', $s);
    Str::match('/(?:a+\d)?/', $s);
    // Linear.
    Preg::match('/^[a-z]+$/', $s);
}

function the_loop_of_composer_pcre(string $s, int $limit): void
{
    Preg::matchAll('/(?:a+\d)?/', $s); // loop: preg_match_all
    Preg::isMatchAll('/(?:a+\d)?/', $s); // loop: preg_match_all
    Preg::replace('/(?:a+\d)?/', '', $s); // loop: preg_replace
    Preg::replace(['/(?:a+\d)?/', '/x/'], '', $s); // loop: preg_replace
    Preg::replace('/(?:a+\d)?/', '', $s, -1); // loop: preg_replace
    Preg::replace('/(?:a+\d)?/', '', $s, $limit); // loop: preg_replace
    Preg::replace('/(?:a+\d)?/', '', $s, 1);
    Preg::replace(limit: 2, subject: $s, replacement: '', pattern: '/(?:a+\d)?/');
    Preg::replaceCallback('/(?:a+\d)?/', static fn (array $m): string => '', $s); // loop: preg_replace_callback
    Preg::replaceCallback('/(?:a+\d)?/', static fn (array $m): string => '', $s, 3);
    Preg::replaceCallbackArray(['/(?:a+\d)?/' => static fn (array $m): string => ''], $s); // loop: preg_replace_callback_array
    Preg::replaceCallbackArray(['/(?:a+\d)?/' => static fn (array $m): string => ''], $s, 1);
    Preg::split('/(?:a+\d)?/', $s); // loop: preg_split
    Preg::split('/(?:a+\d)?/', $s, 0); // loop: preg_split
    Preg::split('/(?:a+\d)?/', $s, 2);
}

function the_loop_of_nette_utils(string $s, bool $lazy): void
{
    Strings::matchAll($s, '/(?:a+\d)?/'); // loop: preg_match_all
    Strings::matchAll($s, '/(?:a+\d)?/', lazy: false); // loop: preg_match_all
    Strings::matchAll($s, '/(?:a+\d)?/', lazy: true); // loop: lazy
    Strings::matchAll($s, '/(?:a+\d)?/', lazy: $lazy);
    Strings::matchAll($s, '/(?:a+\d)?/', utf8: true); // loop: preg_match_all
    Strings::split($s, '/(?:a+\d)?/'); // loop: preg_split
    Strings::split($s, '/(?:a+\d)?/', limit: 2);
    Strings::replace($s, '/(?:a+\d)?/', 'x'); // loop: preg_replace
    Strings::replace($s, ['/(?:a+\d)?/' => 'x']); // loop: preg_replace
    Strings::replace($s, '/(?:a+\d)?/', 'x', 1);
    Strings::replace($s, '/(?:a+\d)?/', static fn (array $m): string => ''); // loop: preg_replace_callback
}

function the_loop_of_spatie_regex_and_of_laravel(string $s): void
{
    Regex::matchAll('/(?:a+\d)?/', $s); // loop: preg_match_all
    Regex::replace('/(?:a+\d)?/', '', $s); // loop: preg_replace
    Regex::replace('/(?:a+\d)?/', static fn (array $m): string => '', $s); // loop: preg_replace_callback
    Regex::replace('/(?:a+\d)?/', '', $s, 1);
    Str::matchAll('/(?:a+\d)?/', $s); // loop: preg_match_all
    Str::replaceMatches('/(?:a+\d)?/', '', $s); // loop: preg_replace
    Str::replaceMatches('/(?:a+\d)?/', static fn (array $m): string => '', $s); // loop: preg_replace_callback
    Str::replaceMatches('/(?:a+\d)?/', '', $s, 4);
}

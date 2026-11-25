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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Redos;

/**
 * @param array<string, string> $map
 */
function redos(string $input, int $limit, array $map): void
{
    preg_match('/^(\w+\s?)+$/', $input); // proven exponential
    preg_match('/\d*\d*\d*$/', $input); // proven polynomial, degree 3
    preg_match('/^(a+)+\1$/', $input); // judged by the heuristics
    preg_match('/(\d+)(\d+)$/', $input); // proven quadratic, medium
    preg_match('/\s+$/', $input); // one attempt linear, the search quadratic
    preg_match_all('/(?:a+\d)?/', $input); // the retry after an empty match
    preg_split('/(?:a+\d)?/', $input);
    preg_replace_callback_array(['/(?:a+\d)?/' => static fn (array $m): string => ''], $input);
    preg_replace(['/x/', '/(?:a+\d)?/'], '', $input);
    preg_replace('/(?:a+\d)?/', '', $input, $limit); // a limit no type bounds
    preg_match('/(?:a+\d)?/', $input); // the first match ends the search
    preg_grep('/(?:a+\d)?/', [$input]);
    preg_replace('/(?:a+\d)?/', '', $input, 1); // the limit bounds the loop
    preg_match('/^(a+)+$/', 'constant subject'); // no attacker-controlled subject
    preg_replace('/^(a+)+$/', '', ['a', 'b']);
    preg_match('/^[a-z]+$/', $input); // linear
    preg_match('/^(a+)+$/', $map['key'] ?? ''); // a subject Psalm cannot reduce to literals
}

/**
 * @param list<'aaa'> $words
 */
function words(array $words, string $input): void
{
    preg_replace('/^(a+)+$/', '', $words); // a list of literals of any length
    preg_replace('/(?:a+\d)?/', '', $input, -1); // a limit that bounds nothing
}

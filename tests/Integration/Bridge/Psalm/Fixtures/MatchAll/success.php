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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\MatchAll;

// Where preg_match_all() returned a count of 1 or more, each list holds
// that many entries: preg_match_all('/#([a-z]+)/', '#ab #c', $m) -> 2,
// $m = [['#ab', '#c'], ['ab', 'c']]; on 'x' -> 0, $m = [[], []] (PHP 8.4.26).
// Psalm reports the call as a bare condition (RiskyTruthyFalsyComparison):
// a cast to bool reads the same.

function success_in_pattern_order(string $s): void
{
    if ((bool) preg_match_all('/#([a-z]+)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-empty-list<non-falsy-string>, 1: non-empty-list<non-falsy-string>}
         * @psalm-trace $m
         */
    } else {
        /**
         * @psalm-check-type-exact $m = array{0: list<non-falsy-string>, 1: list<non-falsy-string>}
         * @psalm-trace $m
         */
    }
}

/**
 * @psalm-suppress RiskyTruthyFalsyComparison
 */
function success_in_set_order(string $s): void
{
    if (preg_match_all('/#([a-z]+)/', $s, $m, \PREG_SET_ORDER)) {
        /**
         * @psalm-check-type-exact $m = non-empty-list<array{0: non-falsy-string, 1: non-falsy-string}>
         * @psalm-trace $m
         */
    }
}

function success_after_an_early_return(string $s): void
{
    if (!(bool) preg_match_all('/#([a-z]+)/', $s, $m)) {
        return;
    }

    /**
     * @psalm-check-type-exact $m = array{0: non-empty-list<non-falsy-string>, 1: non-empty-list<non-falsy-string>}
     * @psalm-trace $m
     */
}

function success_with_marks(string $s): void
{
    // The marks sit under MARK only for the matches that set one:
    // preg_match_all('/a(*MARK:m)|b/', 'b', $m) -> 1, $m = [['b']] (PHP 8.4.26).
    if ((bool) preg_match_all('/a(*MARK:m)|b/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-empty-list<'a'|'b'>, MARK?: array<int, 'm'>}
         * @psalm-trace $m
         */
    }
}

function success_read_through_a_comparison(string $s): void
{
    // preg_match_all('/#([a-z]+)/', 'x', $m) -> 0 (PHP 8.4.26): 0 !== false.
    if (false !== preg_match_all('/#([a-z]+)/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: list<non-falsy-string>, 1: list<non-falsy-string>}
         * @psalm-trace $m
         */
    }
}

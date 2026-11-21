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

// An arm of match (true) is compared with true: Psalm reads the call's
// condition through a negation or an &&. preg_match('/(a)(b)?/', 'ab', $m) -> 1,
// $m[1] = 'a'; on 'x' -> 0, the default arm (PHP 8.4.28).

function call_negated_twice_in_a_match_arm(string $s): void
{
    $first = match (true) {
        !!preg_match('/(a)(b)?/', $s, $m) => $m[1],
        default => null,
    };

    /**
     * @psalm-check-type-exact $first = 'a'|null
     * @psalm-trace $first
     */
}

function call_on_the_left_of_and_in_a_match_arm(string $s): void
{
    $first = match (true) {
        preg_match('/(a)(b)?/', $s, $m) && '' !== $s => $m[1],
        default => null,
    };

    /**
     * @psalm-check-type-exact $first = 'a'|null
     * @psalm-trace $first
     */
}

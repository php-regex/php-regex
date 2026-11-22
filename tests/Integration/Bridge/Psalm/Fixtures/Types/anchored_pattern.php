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

// A pattern anchored at both ends that allows a few characters only: Psalm
// itself reads such a call as removing taints from its subject, beside what
// the plugin reads of it. Where the call returned 1, $matches is the shape:
// preg_match('/^(\d+)$/D', '12', $m) -> ['12', '12'], on '0' -> ['0', '0'],
// on "12\n" -> 0 under D (PHP 8.4.28).

function anchored_pattern_true_branch(string $s): void
{
    if (preg_match('/^(\d+)$/D', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-empty-string, 1: non-empty-string}
         * @psalm-trace $m
         */
    }
}

function anchored_pattern_past_an_early_return(string $s): void
{
    if (!preg_match('/^(\d+)$/D', $s, $m)) {
        return;
    }

    /**
     * @psalm-check-type-exact $m = array{0: non-empty-string, 1: non-empty-string}
     * @psalm-trace $m
     */
}

function anchored_pattern_assigned_in_the_condition(string $s): void
{
    if ($found = preg_match('/^(\d+)$/D', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-empty-string, 1: non-empty-string}
         * @psalm-trace $m
         */
    }
}

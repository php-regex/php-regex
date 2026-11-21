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

// A result assigned in the condition: past an early return where it is
// false, $matches is the shape, on every release.
// preg_match('/(a)(b)?/', 'a', $m) -> 1, $m = ['a', 'a'];
// preg_match('/^(\d+)$/D', '42', $m) -> 1, $m = ['42', '42'] (PHP 8.4.28).

function assigned_condition_early_return(string $s): void
{
    if (!($found = preg_match('/(a)(b)?/', $s, $m))) {
        return;
    }

    /**
     * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
     * @psalm-trace $m
     */
}

function assigned_condition_anchored_early_return(string $s): void
{
    if (!($found = preg_match('/^(\d+)$/D', $s, $m))) {
        return;
    }

    /**
     * @psalm-check-type-exact $m = array{0: non-empty-string, 1: non-empty-string}
     * @psalm-trace $m
     */
}

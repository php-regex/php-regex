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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Taint;

// A subject from the request, matched by a pattern anchored at both ends that
// allows letters and digits only: where the call returned 1, the subject
// holds no HTML. preg_match('/^[a-z0-9]+$/D', '<b>') -> 0, on 'ab12' -> 1,
// on "ab\n" -> 0 under D (PHP 8.4.28). Whatever Psalm reads of the taint, the
// plugin's types sit beside it and take nothing from it.

function query(): string
{
    $x = $_GET['x'] ?? '';

    return \is_string($x) ? $x : '';
}

function subject_narrowed(): void
{
    $x = query();
    if (preg_match('/^[a-z0-9]+$/D', $x)) {
        /**
         * @psalm-check-type-exact $x = non-empty-string
         * @psalm-trace $x
         */
        echo $x;
    }
}

function matches_typed(): void
{
    $x = query();
    if (preg_match('/^([a-z0-9]+)$/D', $x, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: non-empty-string, 1: non-empty-string}
         * @psalm-trace $m
         */
        echo $x;
    }
}

function result_assigned_in_the_condition(): void
{
    $x = query();
    if ($ok = preg_match('/^[a-z0-9]+$/D', $x)) {
        /**
         * @psalm-check-type-exact $x = non-empty-string
         * @psalm-trace $x
         */
        echo $x;
    }
}

function pattern_not_anchored(): void
{
    // preg_match('/[a-z0-9]+/', '<b>') -> 1 (PHP 8.4.28): the subject may hold HTML.
    $x = query();
    if (preg_match('/[a-z0-9]+/', $x)) {
        echo $x;
    }
}

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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\ConditionReadByPsalm;

// A Psalm that reads the plugin's assertions on the call itself reads them
// in a switch as anywhere else. preg_match('/(a)/', 'a', $m) -> 1,
// $m = ['a', 'a'] (PHP 8.4.28).

function call_in_a_switch_case(string $kind, string $s): void
{
    switch ($kind) {
        case 'x':
            if (!preg_match('/(a)/', $s, $m)) {
                return;
            }

            /**
             * @psalm-check-type-exact $m = array{0: 'a', 1: 'a'}
             * @psalm-trace $m
             */
            break;
    }
}

function call_as_the_case_of_a_switch_on_true(string $s): void
{
    switch (true) {
        case (bool) preg_match('/(a)/', $s, $m):
            /**
             * @psalm-check-type-exact $m = array{0: 'a', 1: 'a'}
             * @psalm-trace $m
             */
            break;
    }
}

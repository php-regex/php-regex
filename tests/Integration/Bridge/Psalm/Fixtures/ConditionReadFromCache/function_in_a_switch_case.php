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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\ConditionReadFromCache;

// A Psalm that reads a preg_match() condition from its cache of assertions
// alone keeps no cache in a function written inside a switch either: there,
// $matches keeps the stub's type. preg_match('/(a)/', 'a', $m) -> 1, $m = ['a', 'a'] (PHP 8.4.28).

function function_written_in_a_switch_case(string $kind): void
{
    switch ($kind) {
        case 'x':
            $read = static function (string $s): void {
                if (preg_match('/(a)/', $s, $m)) {
                    /**
                     * @psalm-check-type-exact $m = array<array-key, string>
                     * @psalm-trace $m
                     */
                }
            };
            $read('a');
            break;
    }
}

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
// through a whole || negated. preg_match('/(a)/', 'a', $m) -> 1, $m = ['a', 'a'] (PHP 8.4.28).

function call_in_a_whole_or_negated(string $s, bool $x): void
{
    if (!(!preg_match('/(a)/', $s, $m) || $x)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a', 1: 'a'}
         * @psalm-trace $m
         */
    }
}

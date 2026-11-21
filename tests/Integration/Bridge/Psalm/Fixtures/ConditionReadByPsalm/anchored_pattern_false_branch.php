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

// A Psalm that reads the plugin's assertions on the call itself reads what
// holds where it is false, [], whatever the pattern:
// preg_match('/^(\d+)$/D', 'x', $m) -> 0, $m = [] (PHP 8.4.28).

function anchored_pattern_false_branch(string $s): void
{
    if (!preg_match('/^(\d+)$/D', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<never, never>
         * @psalm-trace $m
         */
        return;
    }
}

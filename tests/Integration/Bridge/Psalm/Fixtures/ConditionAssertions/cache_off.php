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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\ConditionAssertions;

// preg_match('/(a)/', 'a', $m) -> 1, $m = ['a', 'a'] (PHP 8.4.28).

function cache_off_typed_call(string $s): void
{
    if (preg_match('/(a)/', $s, $m)) {
        /** @psalm-trace $m */
    }
}

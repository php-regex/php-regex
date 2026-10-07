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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Types\ImportedFunction;

use function preg_match as matches_pattern;

// A call through an alias "use function" imports reaches PHP's function:
// matches_pattern('/(a)(b)?/', 'a', $m) -> 1, $m = ['a', 'a'] (PHP 8.4.26).

function call_through_an_imported_alias(string $s): void
{
    if (matches_pattern('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

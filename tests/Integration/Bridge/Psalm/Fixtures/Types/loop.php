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

// Psalm analyses a loop body more than once: each pass reads the condition
// again. preg_match('/(a)(b)?/', 'ab', $m) -> 1, $m = ['ab', 'a', 'b'];
// on 'x' -> 0, $m = [] (PHP 8.4.28).

/**
 * @param list<string> $lines
 */
function call_in_a_loop_body(array $lines): void
{
    foreach ($lines as $line) {
        if (preg_match('/(a)(b)?/', $line, $m)) {
            /**
             * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
             * @psalm-trace $m
             */
        }
    }
}

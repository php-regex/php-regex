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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Types\UserFunction;

// A function of the same name in the caller's namespace is not PHP's: an
// unqualified call resolves to it, and the plugin leaves it alone, its
// pattern included.

/**
 * @param-out array{user: true} $matches
 */
function preg_match(string $pattern, string $subject, mixed &$matches = null): bool
{
    $matches = ['user' => true];

    return '' !== $pattern && '' !== $subject;
}

function unqualified_call_reaches_the_users_function(string $s): void
{
    if (preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{user: true}
         * @psalm-trace $m
         */
    }

    // Not a regex at all for this function: no pattern error.
    preg_match('/(/', $s);
}

function fully_qualified_call_reaches_php(string $s): void
{
    if (\preg_match('/(a)(b)?/', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array{0: 'a'|'ab', 1: 'a', 2?: 'b'}
         * @psalm-trace $m
         */
    }
}

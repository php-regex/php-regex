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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Target;

// One fixture, read under several targets: PsalmPluginTargetTest says, for
// each, which line is reported and what each trace holds. Each pattern sits
// alone on its line.

function keep_in_a_lookahead(string $s): void
{
    // PHP 8.5 refuses \K in a lookaround; 8.4 accepts it.
    if (preg_match('/(?=a\Ka)(a)/', $s, $m)) {
        /** @psalm-trace $m */
    }
}

function ascii_option(string $s): void
{
    // (?a...) is PCRE2 10.43: PHP 8.4 bundles 10.44, PHP 8.2 bundles 10.40.
    if (preg_match('/(?aD)(x)/', $s, $m)) {
        /** @psalm-trace $m */
    }
}

function no_auto_capture(string $s): void
{
    // The n modifier is PHP 8.2.
    if (preg_match('/(a)(?<x>b)/n', $s, $m)) {
        /** @psalm-trace $m */
    }
}

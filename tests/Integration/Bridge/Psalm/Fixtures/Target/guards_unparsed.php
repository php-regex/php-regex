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

// A file PHP cannot parse: its guards are not read. Read under Psalm's PHP
// 8.2 by PsalmPluginTargetMarkerTest.

function target_unparsed(string $s): void
{
    if (PHP_VERSION_ID >= 80400) {
        preg_match('/(?aD)(x)/', $s);
    }
}

function target_broken(): void
{
    $a = ;
}

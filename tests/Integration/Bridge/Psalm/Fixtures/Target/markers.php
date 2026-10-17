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

// (?a...) is PCRE2 10.43: PHP 8.2 bundles 10.40, and refuses it. Read under
// Psalm's PHP 8.2 by PsalmPluginTargetMarkerTest.

function target_markers(string $s): void
{
    preg_match('/(?aD)(x)/', $s); // @regex-target php>=8.4
    preg_match('/(?aD)(y)/', $s);
    preg_match('/(?aD)(z)/', $s); // @regex-target php>=eight
    preg_match('/(?aD)(w)/', $s); // @regex-target php>=8.2.5
}

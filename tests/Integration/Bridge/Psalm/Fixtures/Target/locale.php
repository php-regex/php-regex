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

// Read under C and under a Latin-1 locale: PsalmPluginLocaleTest says which
// reports it.

function words(string $s): bool
{
    // "\W" leaves the Latin-1 letters out under a Latin-1 locale: the class
    // no longer holds every byte, and 8000 copies of it are too large.
    return 1 === preg_match('/(?:[\Wa-zA-Z0-9_]){8000}/', $s);
}

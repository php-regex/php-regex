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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Range;

function keep_in_a_lookbehind(string $s): bool
{
    // PHP 8.4 compiles "\K" in a lookbehind; PHP 8.5 compiles without
    // PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK and refuses it.
    return 1 === preg_match('/(?<=a\Kb)c/', $s);
}

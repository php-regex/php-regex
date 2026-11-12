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

// PHP 8.5 refuses "\K" in a lookbehind: the guard and the first marker keep
// their calls off it, the call written outside is judged for the whole
// range, and the second marker keeps no version of a range from 8.1.

function narrowed_keep_in_a_lookbehind(string $s): void
{
    if (\PHP_VERSION_ID < 80500) {
        preg_match('/(?<=a\Kb)d/', $s);
    }

    preg_match('/(?<=a\Kb)e/', $s); // @regex-target php<8.5
    preg_match('/(?<=a\Kb)f/', $s);
    preg_match('/(?<=a\Kb)g/', $s); // @regex-target php<8.0
}

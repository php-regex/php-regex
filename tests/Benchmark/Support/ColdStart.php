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

namespace PHPRegex\Tests\Benchmark\Support;

use PHPRegex\Toolkit\Regex;

/**
 * The state a cold measurement starts from: a facade with no AST cache and
 * the library's process-wide caches emptied. The statics clearCaches() does
 * not register stay filled, so a cold number is "mostly cold".
 */
final class ColdStart
{
    public static function regex(): Regex
    {
        $regex = Regex::create(['cache' => null]);
        $regex->clearCaches();

        return $regex;
    }
}

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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\Text\Path;

/**
 * Paths compared as the filesystem reads them, not as they are spelled:
 * resolved, and with "/" whatever the host wrote.
 */
final class Paths
{
    private function __construct() {}

    /**
     * Whether two spellings name the same existing file.
     */
    public static function same(string $a, string $b): bool
    {
        $a = realpath($a);
        $b = realpath($b);

        return false !== $a && false !== $b && Path::normalize($a) === Path::normalize($b);
    }
}

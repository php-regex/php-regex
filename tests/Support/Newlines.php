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

/**
 * The line ends of a console output read back as "\n": Symfony Console
 * ends each line it writes with PHP_EOL, "\r\n" on Windows, where the
 * library writes "\n". A test that asserts the lines, not the host, reads
 * the output through here.
 */
final class Newlines
{
    private function __construct() {}

    public static function lf(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }
}

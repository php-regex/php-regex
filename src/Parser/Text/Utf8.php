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

namespace PHPRegex\Parser\Text;

/**
 * The bytes of a UTF-8 character, read from the byte it starts with.
 *
 * @internal
 */
final class Utf8
{
    private function __construct() {}

    /**
     * How many bytes the character at $at takes: the length its first byte
     * announces, 1 for a byte that starts no sequence (a continuation byte,
     * the end of the text), so a walk always moves on. Whether the bytes
     * after it continue it is not checked: the text is UTF-8.
     */
    public static function width(string $text, int $at): int
    {
        $byte = \ord($text[$at] ?? "\0");

        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }
}

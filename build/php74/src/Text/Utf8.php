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
    private function __construct()
    {
    }

    /**
     * Whether the byte continues a UTF-8 character, 0x80 to 0xBF, rather
     * than starting one.
     */
    public static function continues(string $byte): bool
    {
        return 0x80 === (\ord($byte) & 0xC0);
    }

    /**
     * How many bytes UTF-8 writes $codePoint in.
     */
    public static function encodedLength(int $codePoint): int
    {
        switch (true) {
            case true === $codePoint < 0x80:
                return 1;
            case true === $codePoint < 0x800:
                return 2;
            case true === $codePoint < 0x10000:
                return 3;
            default:
                return 4;
        }
    }

    /**
     * How many bytes the character at $at takes: the length its first byte
     * announces, 1 for a byte that starts no sequence (a continuation byte,
     * the end of the text), so a walk always moves on. Whether the bytes
     * after it continue it is not checked: the text is UTF-8.
     */
    public static function width(string $text, int $at): int
    {
        $byte = \ord($text[$at] ?? "\0");

        switch (true) {
            case true === $byte >= 0xF0:
                return 4;
            case true === $byte >= 0xE0:
                return 3;
            case true === $byte >= 0xC0:
                return 2;
            default:
                return 1;
        }
    }
}

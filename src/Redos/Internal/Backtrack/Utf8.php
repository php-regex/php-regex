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

namespace PHPRegex\Redos\Internal\Backtrack;

/**
 * Code points to and from the subject's bytes: UTF-8 under /u, one byte
 * per character without it.
 *
 * @internal
 */
final class Utf8
{
    /**
     * @param list<int> $codePoints
     */
    public static function encode(array $codePoints, bool $unicode): string
    {
        $text = '';
        foreach ($codePoints as $codePoint) {
            $text .= self::character($codePoint, $unicode);
        }

        return $text;
    }

    public static function character(int $codePoint, bool $unicode): string
    {
        if (!$unicode || $codePoint < 0x80) {
            return \chr($codePoint & 0xFF);
        }

        if ($codePoint < 0x800) {
            return \chr(0xC0 | ($codePoint >> 6)).\chr(0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint < 0x10000) {
            return \chr(0xE0 | ($codePoint >> 12)).\chr(0x80 | (($codePoint >> 6) & 0x3F)).\chr(0x80 | ($codePoint & 0x3F));
        }

        return \chr(0xF0 | ($codePoint >> 18)).\chr(0x80 | (($codePoint >> 12) & 0x3F))
            .\chr(0x80 | (($codePoint >> 6) & 0x3F)).\chr(0x80 | ($codePoint & 0x3F));
    }

    /**
     * The characters of a string: code points of valid UTF-8 under /u,
     * bytes otherwise; null when the string is not valid UTF-8 under /u.
     *
     * @return list<int>|null
     */
    public static function decode(string $text, bool $unicode): ?array
    {
        if (!$unicode) {
            return '' === $text ? [] : array_values(array_map('ord', str_split($text)));
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        $codePoints = [];
        $length = \strlen($text);
        for ($offset = 0; $offset < $length;) {
            $byte = \ord($text[$offset]);
            [$size, $codePoint] = match (true) {
                $byte < 0x80 => [1, $byte],
                $byte < 0xE0 => [2, $byte & 0x1F],
                $byte < 0xF0 => [3, $byte & 0x0F],
                default => [4, $byte & 0x07],
            };
            for ($next = 1; $next < $size; $next++) {
                $codePoint = ($codePoint << 6) | (\ord($text[$offset + $next]) & 0x3F);
            }

            $codePoints[] = $codePoint;
            $offset += $size;
        }

        return $codePoints;
    }
}

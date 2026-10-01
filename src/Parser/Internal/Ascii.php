<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Internal;

/**
 * The character classes PCRE reads in ASCII, whatever the locale of the
 * process: ctype_* answers for the current LC_CTYPE, where a Latin-1 locale
 * makes "\xE4" a letter. Each check is false for the empty string, as
 * ctype_* is.
 *
 * @internal
 */
final class Ascii
{
    private const DIGITS = '0123456789';

    private const LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const HEX_LETTERS = 'abcdefABCDEF';

    private const SPACES = " \t\n\r\v\f";

    public static function isDigit(string $text): bool
    {
        return self::consistsOf($text, self::DIGITS);
    }

    public static function isAlpha(string $text): bool
    {
        return self::consistsOf($text, self::LETTERS);
    }

    public static function isAlnum(string $text): bool
    {
        return self::consistsOf($text, self::LETTERS.self::DIGITS);
    }

    public static function isSpace(string $text): bool
    {
        return self::consistsOf($text, self::SPACES);
    }

    public static function isHexDigit(string $text): bool
    {
        return self::consistsOf($text, self::DIGITS.self::HEX_LETTERS);
    }

    private static function consistsOf(string $text, string $characters): bool
    {
        return '' !== $text && \strlen($text) === strspn($text, $characters);
    }
}

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

namespace PHPRegex\Parser\Internal;

/**
 * The character classes PCRE reads in ASCII, whatever the locale of the
 * process: ctype_* answers for the current LC_CTYPE, where a Latin-1 locale
 * makes "\xE4" a letter. Each check is false for the empty string, as
 * ctype_* is. Case is mapped the same way: PHP 8.1 lowers and uppers through
 * the locale, PHP 8.2 maps the ASCII letters only.
 *
 * @internal
 */
final class Ascii
{
    private const DIGITS = '0123456789';

    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const LETTERS = self::LOWER.self::UPPER;

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

    /**
     * The text without the leading bytes C's isspace() accepts, as PHP skips
     * them before the delimiter; NUL is not one of them.
     */
    public static function trimLeadingSpaces(string $text): string
    {
        return ltrim($text, self::SPACES);
    }

    public static function isHexDigit(string $text): bool
    {
        return self::consistsOf($text, self::DIGITS.self::HEX_LETTERS);
    }

    /**
     * The text with A to Z lowered and every other byte kept.
     */
    public static function lower(string $text): string
    {
        return strtr($text, self::UPPER, self::LOWER);
    }

    /**
     * The text with a to z uppered and every other byte kept.
     */
    public static function upper(string $text): string
    {
        return strtr($text, self::LOWER, self::UPPER);
    }

    public static function lowerFirst(string $text): string
    {
        return '' === $text ? '' : self::lower($text[0]).substr($text, 1);
    }

    public static function upperFirst(string $text): string
    {
        return '' === $text ? '' : self::upper($text[0]).substr($text, 1);
    }

    private static function consistsOf(string $text, string $characters): bool
    {
        return '' !== $text && \strlen($text) === strspn($text, $characters);
    }
}

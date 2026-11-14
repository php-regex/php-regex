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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Text\Ascii;

/**
 * Reads the code point an escape stands for.
 *
 * Every form PCRE offers for naming a character ends up here: "\x41",
 * "\x{1F600}", "A", "\o{101}", "\101", "\N{LATIN SMALL LETTER A}" and
 * "\cX". None of it depends on the pattern around the escape, which is why
 * it does not live in the parser.
 *
 * A form that cannot be read gives -1: the escape is kept in the tree with
 * its spelling, and the validator is the one that decides whether that is an
 * error.
 *
 * @internal
 */
final class CodePointReader
{
    public const UNKNOWN = -1;

    public static function fromLiteral(string $representation, CharLiteralType $type): int
    {
        switch (true) {
            case $type === CharLiteralType::Unicode():
                return self::fromHexEscape($representation);
            case $type === CharLiteralType::UnicodeNamed():
                return self::fromNamedEscape($representation);
            case $type === CharLiteralType::Octal():
            case $type === CharLiteralType::OctalLegacy():
                return self::fromOctalEscape($representation);
            default:
                throw new \UnhandledMatchError('Unhandled match case of type '.\get_class($type));
        }
    }

    /**
     * "\x41", "\xA", "A", "\x{1F600}", "\u{1F600}"; spaces and tabs may pad the
     * braced digits, "\x{ 41 }", as PCRE2 10.48 allows.
     */
    public static function fromHexEscape(string $representation): int
    {
        $matches = [];

        if (1 === LibraryPcre::match('/^\\\\x([0-9a-fA-F]{1,2})$/', $representation, $matches)) {
            return (int) hexdec($matches[1]);
        }

        if (1 === LibraryPcre::match('/^\\\\u([0-9a-fA-F]{4})$/', $representation, $matches)) {
            return (int) hexdec($matches[1]);
        }

        if (1 === LibraryPcre::match('/^\\\\[xu]\\{[ \t]*+([0-9a-fA-F]++)[ \t]*+\\}$/', $representation, $matches)) {
            return self::digits($matches[1], 16);
        }

        return self::UNKNOWN;
    }

    /**
     * "\N{U+0041}", or "\N{LATIN SMALL LETTER A}" when intl can name it.
     */
    public static function fromNamedEscape(string $representation): int
    {
        $matches = [];
        if (1 !== LibraryPcre::match('/^\\\\N\\{(.+)}$/', $representation, $matches)) {
            return self::UNKNOWN;
        }

        $name = $matches[1];

        $hex = [];
        // No digit but padding is U+0000 from PCRE2 10.43: "\N{U+ }".
        if (1 === LibraryPcre::match('/^[ \t]*+U\+(?:([0-9a-fA-F]++)[ \t]*+|[ \t]++)$/', $name, $hex)) {
            return self::digits($hex[1] ?? '0', 16);
        }

        if (class_exists(\IntlChar::class)) {
            $char = \IntlChar::charFromName($name);
            if (null !== $char) {
                return (int) \IntlChar::ord($char);
            }
        }

        return self::UNKNOWN;
    }

    /**
     * "\o{101}" and the legacy "\101".
     */
    public static function fromOctalEscape(string $representation): int
    {
        $matches = [];

        if (1 === LibraryPcre::match('/^\\\\o\\{[ \t]*+([0-7]++)[ \t]*+\\}$/', $representation, $matches)) {
            return self::digits($matches[1], 8);
        }

        if (1 === LibraryPcre::match('/^\\\\([0-7]{1,3})$/', $representation, $matches)) {
            return (int) octdec($matches[1]);
        }

        return self::UNKNOWN;
    }

    /**
     * The character "\cX" names, which is the letter with bit 6 flipped.
     *
     * @param string $char the letter following "\c"
     */
    public static function fromControlChar(string $char): int
    {
        if ('' === $char) {
            return self::UNKNOWN;
        }

        return \ord(Ascii::upper($char)) ^ 64;
    }

    /**
     * The value of hexadecimal or octal digits, PHP_INT_MAX for one past it:
     * no int holds the float hexdec() and octdec() give there, and a code
     * point that large is refused all the same.
     *
     * @param 8|16 $base
     */
    private static function digits(string $digits, int $base): int
    {
        // The digits sure to fit, one bit kept for the sign: 15 hexadecimal
        // and 21 octal on a 64-bit PHP.
        $fitting = 16 === $base ? 2 * \PHP_INT_SIZE - 1 : intdiv(8 * \PHP_INT_SIZE - 1, 3);
        $significant = ltrim($digits, '0');

        if (\strlen($significant) > $fitting) {
            return \PHP_INT_MAX;
        }

        return (int) (16 === $base ? hexdec($digits) : octdec($digits));
    }
}

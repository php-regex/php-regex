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

use PhpRegex\Parser\Node\CharLiteralType;

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
        return match ($type) {
            CharLiteralType::Unicode => self::fromHexEscape($representation),
            CharLiteralType::UnicodeNamed => self::fromNamedEscape($representation),
            CharLiteralType::Octal,
            CharLiteralType::OctalLegacy => self::fromOctalEscape($representation),
        };
    }

    /**
     * "\x41", "\xA", "A", "\x{1F600}", "\u{1F600}"; spaces and tabs may pad the
     * braced digits, "\x{ 41 }", as PCRE2 10.48 allows.
     */
    public static function fromHexEscape(string $representation): int
    {
        $matches = [];

        if (preg_match('/^\\\\x([0-9a-fA-F]{1,2})$/', $representation, $matches)) {
            return (int) hexdec($matches[1]);
        }

        if (preg_match('/^\\\\u([0-9a-fA-F]{4})$/', $representation, $matches)) {
            return (int) hexdec($matches[1]);
        }

        if (preg_match('/^\\\\[xu]\\{[ \t]*+([0-9a-fA-F]++)[ \t]*+\\}$/', $representation, $matches)) {
            return (int) hexdec($matches[1]);
        }

        return self::UNKNOWN;
    }

    /**
     * "\N{U+0041}", or "\N{LATIN SMALL LETTER A}" when intl can name it.
     */
    public static function fromNamedEscape(string $representation): int
    {
        $matches = [];
        if (!preg_match('/^\\\\N\\{(.+)}$/', $representation, $matches)) {
            return self::UNKNOWN;
        }

        $name = $matches[1];

        $hex = [];
        if (1 === preg_match('/^[ \t]*+U\+([0-9a-fA-F]++)[ \t]*+$/', $name, $hex)) {
            return (int) hexdec($hex[1]);
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

        if (preg_match('/^\\\\o\\{[ \t]*+([0-7]++)[ \t]*+\\}$/', $representation, $matches)) {
            return (int) octdec($matches[1]);
        }

        if (preg_match('/^\\\\([0-7]{1,3})$/', $representation, $matches)) {
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

        return \ord(strtoupper($char)) ^ 64;
    }
}

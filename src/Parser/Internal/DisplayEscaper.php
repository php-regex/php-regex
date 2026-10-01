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
 * Escapes bytes that would break the layout of a terminal or a rendered
 * document, without rewriting characters that are safe to print.
 *
 * @internal
 */
final class DisplayEscaper
{
    private const CONTROL_BYTES = "\0..\37\177";

    private const CONTROL_AND_HIGH_BYTES = "\0..\37\177..\377";

    /**
     * Escape control characters, and non-ASCII bytes only when they do not
     * form valid UTF-8.
     *
     * Escaping every byte above 0x7E would turn a pattern such as
     * /《...》/u into octal escapes, which PCRE reads as different
     * characters: under /u, "\343" is U+00E3 rather than the first byte of a
     * multi-byte character. Printable UTF-8 is therefore left untouched.
     */
    public static function escape(string $text): string
    {
        return addcslashes($text, self::isUtf8($text) ? self::CONTROL_BYTES : self::CONTROL_AND_HIGH_BYTES);
    }

    /**
     * Spell a sample string the way a terminal can show it: quoted, with the
     * bytes that would break the layout written as escapes.
     *
     * @param string $open  markup put before the opening quote
     * @param string $close markup put after the closing quote
     */
    public static function quote(string $value, string $open = '', string $close = ''): string
    {
        if ('' === $value) {
            return '"" (empty string)';
        }

        $escaped = '';
        $length = \strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $byte = \ord($value[$i]);
            $escaped .= match ($byte) {
                0x0A => '\\n',
                0x0D => '\\r',
                0x09 => '\\t',
                0x5C => '\\\\',
                0x22 => '\\"',
                default => ($byte < 0x20 || $byte > 0x7E)
                    ? \sprintf('\\x%02X', $byte)
                    : $value[$i],
            };
        }

        return $open.'"'.$escaped.'"'.$close;
    }

    private static function isUtf8(string $text): bool
    {
        return 1 === preg_match('//u', $text);
    }
}

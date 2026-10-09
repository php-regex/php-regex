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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Parser\Internal\Ascii;

/**
 * The value of a constant PHP string literal, read as PHP reads it.
 *
 * Single quotes unescape \\ and \' alone. Double quotes unescape \n \t \r
 * \v \e \f \\ \$ \", an octal \0 to \777, a hexadecimal \x0 to \xFF and
 * \u{...}; any other backslash is kept as written, so the regex escapes of
 * "/\d+\.x/" reach the pattern.
 *
 * @internal
 */
final class PhpStringLiteral
{
    private const SIMPLE = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];

    /**
     * The value of a quoted literal token; null for any other token.
     */
    public static function decode(string $token): ?string
    {
        $quote = $token[0] ?? '';
        if (\strlen($token) < 2 || ("'" !== $quote && '"' !== $quote) || $quote !== $token[-1]) {
            return null;
        }

        $body = substr($token, 1, -1);

        return "'" === $quote ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : self::decodeDoubleQuoted($body);
    }

    private static function decodeDoubleQuoted(string $body): string
    {
        $result = '';
        $length = \strlen($body);
        for ($i = 0; $i < $length;) {
            $next = $body[$i + 1] ?? '';
            if ('\\' !== $body[$i] || '' === $next) {
                $result .= $body[$i++];

                continue;
            }

            if (isset(self::SIMPLE[$next])) {
                $result .= self::SIMPLE[$next];
                $i += 2;
            } elseif ($next >= '0' && $next <= '7') {
                $digits = self::run($body, $i + 1, 3, static fn (string $char): bool => $char >= '0' && $char <= '7');
                // PHP keeps the low byte of \400 and above.
                $result .= \chr((int) octdec($digits) & 0xFF);
                $i += 1 + \strlen($digits);
            } elseif ('x' === $next && '' !== ($digits = self::run($body, $i + 2, 2, Ascii::isHexDigit(...)))) {
                $result .= \chr((int) hexdec($digits));
                $i += 2 + \strlen($digits);
            } elseif ('u' === $next && null !== ($codepoint = self::unicode($body, $i + 2))) {
                $result .= self::utf8((int) hexdec($codepoint));
                $i += 4 + \strlen($codepoint);
            } else {
                // An unknown escape, \d or \/ in a pattern, is kept.
                $result .= '\\'.$next;
                $i += 2;
            }
        }

        return $result;
    }

    /**
     * The characters from $start that pass $accepts, at most $max.
     *
     * @param \Closure(string): bool $accepts
     */
    private static function run(string $body, int $start, int $max, \Closure $accepts): string
    {
        $run = '';
        for ($i = $start; $i < $start + $max && isset($body[$i]) && $accepts($body[$i]); $i++) {
            $run .= $body[$i];
        }

        return $run;
    }

    /**
     * A code point in UTF-8, as PHP writes it, a surrogate included.
     */
    private static function utf8(int $codepoint): string
    {
        return match (true) {
            $codepoint < 0x80 => \chr($codepoint),
            $codepoint < 0x800 => \chr(0xC0 | ($codepoint >> 6)).\chr(0x80 | ($codepoint & 0x3F)),
            $codepoint < 0x10000 => \chr(0xE0 | ($codepoint >> 12)).\chr(0x80 | (($codepoint >> 6) & 0x3F)).\chr(0x80 | ($codepoint & 0x3F)),
            default => \chr(0xF0 | (($codepoint >> 18) & 0x07)).\chr(0x80 | (($codepoint >> 12) & 0x3F)).\chr(0x80 | (($codepoint >> 6) & 0x3F)).\chr(0x80 | ($codepoint & 0x3F)),
        };
    }

    /**
     * The hexadecimal digits of a "{...}" opening at $start; null when it is
     * not one.
     */
    private static function unicode(string $body, int $start): ?string
    {
        $close = '{' === ($body[$start] ?? '') ? strpos($body, '}', $start) : false;
        if (false === $close) {
            return null;
        }

        $digits = substr($body, $start + 1, $close - $start - 1);

        return '' !== $digits && Ascii::isHexDigit($digits) ? $digits : null;
    }
}

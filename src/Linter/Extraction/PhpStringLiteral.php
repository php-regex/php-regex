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
 * \v \e \f \\ \$ \", an octal \0 to \777, a hexadecimal \x0 to \xFF (or \X) and
 * \u{...}; any other backslash is kept as written, so the regex escapes of
 * "/\d+\.x/" reach the pattern. A heredoc reads the same escapes but \",
 * a nowdoc none.
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

        return "'" === $quote ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : self::decodeDoubleQuoted($body, true);
    }

    /**
     * The value of a heredoc or nowdoc, from its opening token, its raw body
     * and its closing token: the newline before the closing marker is
     * dropped, the marker's indentation leaves every line, then a heredoc
     * reads the double-quoted escapes but \", which keeps its backslash, and
     * a nowdoc none.
     *
     * Null when PHP refuses the body: a line indented less than the marker,
     * or tabs and spaces mixed.
     */
    public static function decodeHeredoc(string $opening, string $body, string $closing): ?string
    {
        $indentation = \strlen($closing) - \strlen(ltrim($closing, " \t"));
        $indent = substr($closing, 0, $indentation);
        if (str_contains($indent, ' ') && str_contains($indent, "\t")) {
            return null;
        }

        if (str_ends_with($body, "\r\n")) {
            $body = substr($body, 0, -2);
        } elseif (str_ends_with($body, "\n") || str_ends_with($body, "\r")) {
            $body = substr($body, 0, -1);
        }

        if ($indentation > 0) {
            $body = self::removeIndentation($body, $indentation, $indent[0]);
            if (null === $body) {
                return null;
            }
        }

        // The quote sits around the label of a nowdoc only: <<<'RE'.
        return str_contains($opening, "'") ? $body : self::decodeDoubleQuoted($body, false);
    }

    /**
     * @param string $char the indentation character, a space or a tab
     */
    private static function removeIndentation(string $body, int $indentation, string $char): ?string
    {
        $result = '';
        $length = \strlen($body);
        $i = 0;

        while (true) {
            // A line ends at \n, \r or \r\n; the last one at the end of the body.
            $lineEnd = $i;
            while ($lineEnd < $length && "\n" !== $body[$lineEnd] && "\r" !== $body[$lineEnd]) {
                $lineEnd++;
            }

            $newline = 0;
            if ($lineEnd < $length) {
                $newline = "\r" === $body[$lineEnd] && "\n" === ($body[$lineEnd + 1] ?? '') ? 2 : 1;
            }

            // A whitespace-only line may be indented less; any other may not,
            // and tabs and spaces may not mix.
            for ($skip = 0; $skip < $indentation && $i < $lineEnd; $skip++, $i++) {
                if ($char !== $body[$i]) {
                    return null;
                }
            }

            $result .= substr($body, $i, $lineEnd - $i + $newline);
            if (0 === $newline) {
                return $result;
            }

            $i = $lineEnd + $newline;
        }
    }

    /**
     * @param bool $quoteEscape whether \" is an escape: in double quotes, not in a heredoc
     */
    private static function decodeDoubleQuoted(string $body, bool $quoteEscape): string
    {
        $result = '';
        $length = \strlen($body);
        for ($i = 0; $i < $length;) {
            $next = $body[$i + 1] ?? '';
            if ('\\' !== $body[$i] || '' === $next) {
                $result .= $body[$i++];

                continue;
            }

            if (isset(self::SIMPLE[$next]) && ($quoteEscape || '"' !== $next)) {
                $result .= self::SIMPLE[$next];
                $i += 2;
            } elseif ($next >= '0' && $next <= '7') {
                $digits = self::run($body, $i + 1, 3, static fn (string $char): bool => $char >= '0' && $char <= '7');
                // PHP keeps the low byte of \400 and above.
                $result .= \chr((int) octdec($digits) & 0xFF);
                $i += 1 + \strlen($digits);
            } elseif (('x' === $next || 'X' === $next) && '' !== ($digits = self::run($body, $i + 2, 2, Ascii::isHexDigit(...)))) {
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

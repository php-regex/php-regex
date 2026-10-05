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
 * Escapes bytes that would break the layout of a terminal or a rendered
 * document, without rewriting characters that are safe to print.
 *
 * @internal
 */
final class DisplayEscaper
{
    /**
     * The escapes PCRE reads back as the same byte; every other control byte
     * is spelled in hex, as "\b" and "\v" mean something else to it.
     */
    private const LETTER_ESCAPES = ["\t" => '\\t', "\n" => '\\n', "\r" => '\\r'];

    /**
     * The characters that open a callout string "(?C\"...\")", each with the
     * one that closes it.
     */
    private const CALLOUT_DELIMITERS = ['`' => '`', "'" => "'", '"' => '"', '^' => '^', '%' => '%', '#' => '#', '$' => '$', '{' => '}'];

    /**
     * The escape of each byte to rewrite, keyed by whether the text is
     * valid UTF-8.
     *
     * @var array<int, array<string, string>>
     */
    private static array $escapes = [];

    /**
     * Escape control characters, and non-ASCII bytes only when they do not
     * form valid UTF-8.
     *
     * Escaping every byte above 0x7E would turn a pattern such as
     * /《...》/u into hex escapes, which PCRE reads as different characters:
     * under /u, "\xE3" is U+00E3 rather than the first byte of a multi-byte
     * character. Printable UTF-8 is therefore left untouched.
     *
     * The text reads back as the same bytes in context: a backslash that
     * escapes a rewritten byte gives way to the byte's escape, and inside
     * \Q..\E, where an escape would be literal text, the quote is closed
     * around it. Where PCRE reads no escape, in a comment "(?#...)", a
     * verb argument "(*MARK:...)" or a callout string "(?C\"...\")", the
     * bytes are only spelled, and the operand of "\c" is never read as the
     * start of an escape. A "[" that opens the text as its delimiter opens
     * no class.
     */
    public static function escape(string $text): string
    {
        $escapes = self::escapes(self::isUtf8($text));
        $length = \strlen($text);
        $escaped = '';
        $quoted = false;
        $classStart = null;
        $delimiter = self::bracketDelimiterOffset($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            $next = $text[$i + 1] ?? '';

            if ($quoted) {
                if ('\\' === $char && 'E' === $next) {
                    $quoted = false;
                    $escaped .= '\\E';
                    $i++;
                } else {
                    $escaped .= isset($escapes[$char]) ? '\\E'.$escapes[$char].'\\Q' : $char;
                }

                continue;
            }

            if ('\\' === $char && '' !== $next) {
                if ('c' === $next && $i + 2 < $length) {
                    $escaped .= '\\c'.($escapes[$text[$i + 2]] ?? $text[$i + 2]);
                    $i += 2;

                    continue;
                }

                $i++;
                $quoted = 'Q' === $next;
                $escaped .= $escapes[$next] ?? '\\'.$next;

                continue;
            }

            if ($i === $delimiter) {
                $escaped .= $char;

                continue;
            }

            $end = null;
            if (null !== $classStart) {
                if (']' === $char && $i > $classStart) {
                    $classStart = null;
                } elseif ('[' === $char) {
                    $end = self::posixClassEnd($text, $i);
                }
            } elseif ('[' === $char) {
                $classStart = self::classBodyStart($text, $i + 1);
            } else {
                $end = self::unescapedRunEnd($text, $i);
            }

            if (null !== $end) {
                $escaped .= strtr(substr($text, $i, $end - $i + 1), $escapes);
                $i = $end;

                continue;
            }

            $escaped .= $escapes[$char] ?? $char;
        }

        return $escaped;
    }

    /**
     * Spell plain text, such as a subject string or a decoded literal, with
     * no pattern context: a backslash is a byte like any other, doubled so
     * that it never reads as the start of an escape, and the bytes that
     * would break the layout are written as escapes. Printable UTF-8 is left
     * untouched, as in escape().
     */
    public static function escapeText(string $text): string
    {
        return strtr($text, ['\\' => '\\\\'] + self::escapes(self::isUtf8($text)));
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

    /**
     * @return array<string, string>
     */
    private static function escapes(bool $utf8): array
    {
        if (!isset(self::$escapes[(int) $utf8])) {
            $escapes = [];
            for ($byte = 0x00; $byte <= ($utf8 ? 0x7F : 0xFF); $byte++) {
                if ($byte < 0x20 || $byte >= 0x7F) {
                    $escapes[\chr($byte)] = self::LETTER_ESCAPES[\chr($byte)] ?? \sprintf('\\x%02X', $byte);
                }
            }

            self::$escapes[(int) $utf8] = $escapes;
        }

        return self::$escapes[(int) $utf8];
    }

    /**
     * Where the body of a class opened before $offset starts: a "]" there is
     * a literal. PCRE skips "\E" and empty "\Q\E" around the "^" that
     * negates the class.
     */
    private static function classBodyStart(string $text, int $offset): int
    {
        $offset = self::skipEmptyQuotes($text, $offset);
        if ('^' === ($text[$offset] ?? '')) {
            $offset = self::skipEmptyQuotes($text, $offset + 1);
        }

        return $offset;
    }

    private static function skipEmptyQuotes(string $text, int $offset): int
    {
        while (true) {
            if ('\\Q\\E' === substr($text, $offset, 4)) {
                $offset += 4;
            } elseif ('\\E' === substr($text, $offset, 2)) {
                $offset += 2;
            } else {
                return $offset;
            }
        }
    }

    /**
     * Where a POSIX class such as "[:alpha:]" opened at $offset inside a
     * class ends, the way PCRE finds its terminator; null when the "[" is a
     * literal.
     */
    private static function posixClassEnd(string $text, int $offset): ?int
    {
        $terminator = $text[$offset + 1] ?? '';
        if (':' !== $terminator && '.' !== $terminator && '=' !== $terminator) {
            return null;
        }

        $length = \strlen($text);
        for ($i = $offset + 2; $i < $length; $i++) {
            $next = $text[$i + 1] ?? '';
            if ('\\' === $text[$i] && (']' === $next || '\\' === $next)) {
                $i++;
            } elseif (('[' === $text[$i] && $terminator === $next) || ']' === $text[$i]) {
                return null;
            } elseif ($terminator === $text[$i] && ']' === $next) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Where the "[" that opens a delimited pattern such as "[a+]i" stands,
     * past the whitespace PHP skips before the delimiter; null when the text
     * opens with no "[", or with one that no "]" closes before the
     * modifiers. As PHP finds the closing delimiter, a nested "[" waits for
     * one more "]", and a backslash skips the byte after it.
     */
    private static function bracketDelimiterOffset(string $text): ?int
    {
        $start = strspn($text, " \t\n\r\v\f");
        if ('[' !== ($text[$start] ?? '')) {
            return null;
        }

        $length = \strlen($text);
        $depth = 0;
        for ($i = $start; $i < $length; $i++) {
            if ('\\' === $text[$i]) {
                $i++;
            } elseif ('[' === $text[$i]) {
                $depth++;
            } elseif (']' === $text[$i] && 0 === --$depth) {
                return $length === $i + 1 + strspn($text, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ \n\r", $i + 1) ? $start : null;
            }
        }

        return null;
    }

    /**
     * Where a comment "(?#...)", a verb argument "(*MARK:...)", "(*:...)",
     * or a callout string "(?C\"...\")" opened at $offset ends: at the first
     * ")" for the first two, as PCRE reads no escape there, and at the
     * closing delimiter for a callout string, where a doubled delimiter
     * stands for one. Null when no such run opens at $offset.
     */
    private static function unescapedRunEnd(string $text, int $offset): ?int
    {
        if ('(?C' === substr($text, $offset, 3) && isset(self::CALLOUT_DELIMITERS[$text[$offset + 3] ?? ''])) {
            return self::calloutStringEnd($text, $offset + 4, self::CALLOUT_DELIMITERS[$text[$offset + 3]]);
        }

        if ('(?#' === substr($text, $offset, 3)) {
            $start = $offset + 3;
        } elseif ('(*' === substr($text, $offset, 2)) {
            $name = strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ_', $offset + 2);
            if (':' !== ($text[$offset + 2 + $name] ?? '')) {
                return null;
            }

            $start = $offset + 3 + $name;
        } else {
            return null;
        }

        $close = strpos($text, ')', $start);

        return false === $close ? \strlen($text) - 1 : $close;
    }

    /**
     * Where a callout string read from $offset ends: at the closing
     * delimiter that is not doubled, or at the end of the text.
     */
    private static function calloutStringEnd(string $text, int $offset, string $close): int
    {
        $length = \strlen($text);
        for ($i = $offset; $i < $length; $i++) {
            if ($close === $text[$i]) {
                if ($close !== ($text[$i + 1] ?? '')) {
                    return $i;
                }

                $i++;
            }
        }

        return $length - 1;
    }

    private static function isUtf8(string $text): bool
    {
        return 1 === preg_match('//u', $text);
    }
}

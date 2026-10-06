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
     * The delimiters PHP closes with another character.
     */
    private const BRACKETS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /**
     * The characters that move or hide text when printed raw, as the engine
     * classifies them: the C1 controls U+0080 to U+009F, every format
     * character (general category Cf: bidirectional controls, zero-width
     * characters, tag characters...), the line separator U+2028 and the
     * paragraph separator U+2029.
     */
    private const HIDDEN_CLASS = '/[\x{80}-\x{9F}\p{Cf}\p{Zl}\p{Zp}]/u';

    /**
     * The white space x skips outside a class, by whether the pattern is
     * read as UTF.
     */
    private const SKIPPED = [
        ["\t", "\n", "\x0B", "\x0C", "\r", ' ', "\x85"],
        ["\t", "\n", "\x0B", "\x0C", "\r", ' ', "\u{85}", "\u{200E}", "\u{200F}", "\u{2028}", "\u{2029}"],
    ];

    /**
     * The line breaks that end a "#" comment under each newline convention;
     * under ANY, NEL and, in UTF, U+2028 and U+2029 end one too.
     */
    private const LINE_BREAKS = [
        'CR' => ["\r"],
        'LF' => ["\n"],
        'CRLF' => ["\r\n"],
        'ANYCRLF' => ["\r\n", "\r", "\n"],
        'ANY' => ["\r\n", "\r", "\n", "\x0B", "\x0C"],
        'NUL' => ["\0"],
    ];

    /**
     * The spellings of a text that is no valid UTF-8: every byte above
     * ASCII in hex.
     */
    private const RAW_BYTES = 0;

    /**
     * The spellings of valid UTF-8 read byte by byte: a hidden character is
     * one "\xHH" per byte.
     */
    private const UTF8_BYTES = 1;

    /**
     * The spellings of valid UTF-8 read as code points: a hidden character
     * is "\x{HEX}".
     */
    private const UTF8_CODE_POINTS = 2;

    /**
     * The escape of each byte or hidden character to rewrite, keyed by how
     * the text is read: RAW_BYTES, UTF8_BYTES or UTF8_CODE_POINTS.
     *
     * @var array<int, array<string, string>>
     */
    private static array $escapes = [];

    /**
     * The hidden characters, in UTF-8, read once from the engine.
     *
     * @var list<string>|null
     */
    private static ?array $hidden = null;

    /**
     * Escape control characters, the characters that move or hide text
     * (C1 controls, format characters such as the bidirectional controls
     * and the zero-width space, the line and paragraph separators), and
     * non-ASCII bytes when they do not form valid UTF-8.
     *
     * Escaping every byte above 0x7E would turn a pattern such as
     * /《...》/u into hex escapes, which PCRE reads as different characters:
     * under /u, "\xE3" is U+00E3 rather than the first byte of a multi-byte
     * character. Printable UTF-8 is therefore left untouched.
     *
     * A delimited pattern is read in its own mode: under u or a leading
     * (*UTF), a hidden character is spelled "\x{HEX}"; otherwise, as PCRE
     * reads it byte by byte, each of its bytes is "\xHH". Text with no
     * delimiter is spelled as code points. Under x, or once "(?x)" is in
     * force, the pattern is shown on one line: the "#" comments are dropped
     * to the line break the newline convention sets, the white space PCRE
     * skips is written as a space, or as "(?#)" inside braces, where a space
     * means something ("\E" when a delimiter is one of "(?#)"), and white
     * space that matches is spelled in hex outside a class. A space stays
     * after a backslash that would otherwise escape the closing delimiter.
     *
     * The text reads back as the same bytes in context: a backslash that
     * escapes a rewritten byte gives way to the byte's escape, and inside
     * \Q..\E, where an escape would be literal text, the quote is closed
     * around it. Read byte by byte under x, a backslash escapes the "\xC2"
     * of a next-line control, and the NEL byte after it is skipped white
     * space. Without x, a tab PCRE reads as padding inside the braces of a
     * quantifier or of "\x{...}", "\o{...}", "\g{...}", "\k{...}" or
     * "\N{...}" is written as a space. Where PCRE reads no escape, in a
     * comment "(?#...)", a verb argument "(*MARK:...)" or a callout string
     * "(?C\"...\")", the bytes are only spelled, and the operand of "\c" is
     * never read as the start of an escape. The delimiters and the
     * modifiers are not part of the pattern: a "[" that opens the text as
     * its delimiter opens no class, and the white space PHP skips before
     * the opening delimiter and among the modifiers is written as spaces.
     */
    public static function escape(string $text): string
    {
        [$bodyStart, $bodyEnd, $modifiers] = self::delimited($text);
        $body = substr($text, $bodyStart, $bodyEnd - $bodyStart);
        $utf = null === $modifiers || str_contains($modifiers, 'u') || StartOptions::turnUtfOn($body);
        $escapes = self::escapes(self::isUtf8($text) ? ($utf ? self::UTF8_CODE_POINTS : self::UTF8_BYTES) : self::RAW_BYTES);
        if (null === $modifiers) {
            return self::escapeBody($body, $escapes, $utf, false, StartOptions::newline($body), '(?#)');
        }

        $open = $text[$bodyStart - 1];
        $close = $text[$bodyEnd];
        // "(?#)" inside braces would hold the delimiter; "\E" holds none.
        $separator = false !== strpbrk($open.$close, '(?#)') ? '\\E' : '(?#)';

        // The white space PHP skips around the delimiters is written as
        // spaces, which PHP skips as well.
        return strtr(substr($text, 0, $bodyStart - 1), "\t\n\r\v\f", '     ').strtr($open, $escapes)
            .self::escapeBody($body, $escapes, $utf, str_contains($modifiers, 'x'), StartOptions::newline($body), $separator)
            .strtr($close, $escapes).strtr($modifiers, "\n\r", '  ');
    }

    /**
     * Spell a piece of a pattern body, a line of it or a snippet, in the
     * mode of the pattern it was cut from: under UTF a hidden character is
     * its code point "\x{HEX}", otherwise each of its bytes is "\xHH". The
     * piece is never reflowed, x or not.
     */
    public static function escapeFragment(string $fragment, bool $utf): string
    {
        $escapes = self::escapes(self::isUtf8($fragment) ? ($utf ? self::UTF8_CODE_POINTS : self::UTF8_BYTES) : self::RAW_BYTES);

        return self::escapeBody($fragment, $escapes, $utf, false, 'LF', '(?#)');
    }

    /**
     * Spell plain text, such as a subject string or a decoded literal, with
     * no pattern context: a backslash is a byte like any other, doubled so
     * that it never reads as the start of an escape, and the bytes that
     * would break the layout are written as escapes, a hidden character as
     * its code point "\x{HEX}". Printable UTF-8 is left untouched, as in
     * escape().
     */
    public static function escapeText(string $text): string
    {
        return strtr($text, ['\\' => '\\\\'] + self::escapes(self::isUtf8($text) ? self::UTF8_CODE_POINTS : self::RAW_BYTES));
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
     * The characters that move or hide text when printed raw, in UTF-8, in
     * code point order: every code point from U+0080 the engine reads as a
     * C1 control, a format character (Cf), a line separator (Zl) or a
     * paragraph separator (Zp). The code points are read a plane at a time,
     * once per process.
     *
     * @return list<string>
     */
    public static function hiddenCharacters(): array
    {
        if (null === self::$hidden) {
            $hidden = [];
            for ($start = 0x80; $start <= 0x10FFFF; $start += 0x10000) {
                $end = min($start + 0xFFFF, 0x10FFFF);
                // The surrogates U+D800 to U+DFFF are no characters in UTF-8.
                $codePoints = $start <= 0xDFFF && $end >= 0xD800 ? [...range($start, 0xD7FF), ...range(0xE000, $end)] : range($start, $end);
                LibraryPcre::matchAll(self::HIDDEN_CLASS, (string) mb_convert_encoding(pack('N*', ...$codePoints), 'UTF-8', 'UTF-32BE'), $matches);
                foreach ($matches[0] ?? [] as $character) {
                    $hidden[] = $character;
                }
            }

            self::$hidden = $hidden;
        }

        return self::$hidden;
    }

    /**
     * Spell the pattern between its delimiters.
     *
     * @param array<string, string> $escapes
     * @param bool                  $utf       whether PCRE reads the pattern as UTF
     * @param bool                  $extended  whether x is set for the whole pattern
     * @param string                $newline   the newline convention, which ends a "#" comment
     * @param string                $separator what keeps skipped white space holding a line break apart inside braces
     */
    private static function escapeBody(string $body, array $escapes, bool $utf, bool $extended, string $newline, string $separator): string
    {
        $length = \strlen($body);
        $escaped = '';
        $quoted = false;
        $classStart = null;
        // 0 without x, 1 under x, 2 under xx; each group restores the level
        // in force where it opened.
        $level = $extended ? 1 : 0;
        $levels = [];
        // Inside "{...}", a space or a tab may belong to a quantifier or an
        // escape such as "\x{...}", where a line break does not.
        $braced = false;
        // The "{" that opens the operand of "\x", "\o", "\g", "\k" or "\N",
        // and the first "}" from there.
        $operandBrace = null;
        $nextClose = null;
        // The "}" that closes braces where PCRE reads a tab as padding.
        $paddedEnd = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            [$unit, $spelling] = self::unitAt($body, $i, $escapes);

            if ($quoted) {
                if ('\\' === $char && 'E' === ($body[$i + 1] ?? '')) {
                    $quoted = false;
                    $escaped .= '\\E';
                    $i++;
                } else {
                    $escaped .= null !== $spelling ? '\\E'.$spelling.'\\Q' : $unit;
                    $i += \strlen($unit) - 1;
                }

                continue;
            }

            if ('\\' === $char && $i + 1 < $length) {
                $extendedOutsideClass = $level > 0 && null === $classStart;
                $letter = $body[$i + 1];
                $escaped .= self::escapeSequence($body, $i, $escapes, $extendedOutsideClass, $extendedOutsideClass && !$utf);
                $quoted = 'Q' === $letter;
                $end = self::escapeSequenceEnd($body, $i, $escapes, $extendedOutsideClass && !$utf);
                // Inside a class "\g" and "\k" are the letters g and k, and
                // the braces after them are literal.
                if ($end === $i + 1 && str_contains(null === $classStart ? 'xogkN' : 'xoN', $letter) && '{' === ($body[$end + 1] ?? '')) {
                    $operandBrace = $end + 1;
                    // The "}" is found once for all the "{" before it.
                    if (null === $nextClose || $nextClose < $operandBrace) {
                        $close = strpos($body, '}', $operandBrace);
                        $nextClose = false === $close ? $length : $close;
                    }
                    $paddedEnd = $nextClose;
                }
                $i = $end;
                $braced = false;

                continue;
            }

            if (null !== $classStart) {
                $end = null;
                if (']' === $char && $i > $classStart) {
                    $classStart = null;
                } elseif ('[' === $char) {
                    $end = self::posixClassEnd($body, $i);
                }

                if (null !== $end) {
                    $escaped .= strtr(substr($body, $i, $end - $i + 1), $escapes);
                    $i = $end;
                } else {
                    // Under xx a tab in a class is skipped, as a space is, and
                    // inside the braces of an escape it is padding.
                    $escaped .= "\t" === $char && (2 === $level || (null !== $paddedEnd && $i < $paddedEnd)) ? ' ' : $spelling ?? $unit;
                    $i += \strlen($unit) - 1;
                }

                continue;
            }

            if ('[' === $char) {
                $classStart = self::classBodyStart($body, $i + 1);
                $escaped .= $char;
                $braced = false;

                continue;
            }

            $end = self::unescapedRunEnd($body, $i);
            if (null !== $end) {
                $escaped .= strtr(substr($body, $i, $end - $i + 1), $escapes);
                // A callout string ends before the ")" that closes its group.
                if (')' !== $body[$end]) {
                    $levels[] = $level;
                }
                $i = $end;
                $braced = false;

                continue;
            }

            if ('(' === $char) {
                $span = '?' === ($body[$i + 1] ?? '') ? strspn($body, InlineFlags::LETTERS.'r^-', $i + 2) : 0;
                $after = $body[$i + 2 + $span] ?? '';
                $flags = $span > 0 && (')' === $after || ':' === $after) ? InlineFlags::read(substr($body, $i + 2, $span), InlineFlags::LETTERS.'r') : null;
                if (null === $flags || ':' === $after) {
                    $levels[] = $level;
                }

                if (null !== $flags) {
                    $level = self::extendedLevel($flags, $level);
                    $escaped .= substr($body, $i, $span + 3);
                    $i += $span + 2;
                    $braced = false;

                    continue;
                }
            } elseif (')' === $char && [] !== $levels) {
                $level = array_pop($levels);
            }

            if ($level > 0) {
                $skipped = self::skippedEnd($body, $i, $utf, $newline);
                if ($skipped > $i) {
                    $run = substr($body, $i, $skipped - $i);
                    // Nothing is written for what ends the pattern, unless a
                    // backslash ends the text, the operand of "\c" that would
                    // escape the closing delimiter; elsewhere a space keeps
                    // two items apart, as "\1" from "0".
                    if ($skipped < $length) {
                        $escaped .= '' === trim($run, " \t") ? strtr($run, "\t", ' ') : ($braced ? $separator : ' ');
                    } elseif (1 === (\strlen($escaped) - \strlen(rtrim($escaped, '\\'))) % 2) {
                        $escaped .= ' ';
                    }
                    $i = $skipped - 1;

                    continue;
                }

                if (!$utf && "\xC2\x85" === $unit) {
                    // Read byte by byte, the NEL after "\xC2" is skipped white space.
                    $escaped .= '\\xC2';

                    continue;
                }
            }

            if ('{' === $char) {
                $braced = true;
                if ($i !== $operandBrace) {
                    $paddedEnd = self::quantifierEnd($body, $i);
                }
            } elseif ('}' === $char || (!Ascii::isAlnum($char) && !str_contains(",_+- \t", $char))) {
                $braced = false;
            }

            if ("\t" === $char && null !== $paddedEnd && $i < $paddedEnd) {
                $escaped .= ' ';

                continue;
            }

            $escaped .= $spelling ?? $unit;
            $i += \strlen($unit) - 1;
        }

        return $escaped;
    }

    /**
     * Spell the escape sequence a backslash opens at $offset: the escape of
     * the character after it takes the backslash's place, the operand of
     * "\c" is spelled on its own, and the white space PCRE skips inside
     * "\p{...}" is written as a space. With $hexSpaces, as under x outside a
     * class, an escaped tab, line feed or carriage return is spelled in hex.
     *
     * @param array<string, string> $escapes
     * @param bool                  $nelSkipped whether the NEL byte is white space x skips, read byte by byte outside a class
     */
    private static function escapeSequence(string $body, int $offset, array $escapes, bool $hexSpaces, bool $nelSkipped): string
    {
        $next = $body[$offset + 1];
        if ('c' === $next && $offset + 2 < \strlen($body)) {
            [$operand, $spelling] = self::unitAt($body, $offset + 2, $escapes);

            return '\\c'.($spelling ?? $operand);
        }

        if (('p' === $next || 'P' === $next) && '{' === ($body[$offset + 2] ?? '')) {
            $end = self::escapeSequenceEnd($body, $offset, $escapes, $nelSkipped);

            return strtr(substr($body, $offset, $end - $offset + 1), ["\t" => ' ', "\n" => ' ', "\x0B" => ' ', "\x0C" => ' ', "\r" => ' '] + $escapes);
        }

        [$operand, $spelling] = self::operandAt($body, $offset + 1, $escapes, $nelSkipped);
        if ($hexSpaces && isset(self::LETTER_ESCAPES[$operand])) {
            return \sprintf('\\x%02X', \ord($operand));
        }

        return $spelling ?? '\\'.$operand;
    }

    /**
     * Where the escape sequence a backslash opens at $offset ends: after its
     * operand, the operand of "\c" included, or at the "}" that closes
     * "\p{...}".
     *
     * @param array<string, string> $escapes
     * @param bool                  $nelSkipped whether the NEL byte is white space x skips, read byte by byte outside a class
     */
    private static function escapeSequenceEnd(string $body, int $offset, array $escapes, bool $nelSkipped): int
    {
        $next = $body[$offset + 1];
        if ('c' === $next && $offset + 2 < \strlen($body)) {
            return $offset + 1 + \strlen(self::unitAt($body, $offset + 2, $escapes)[0]);
        }

        if (('p' === $next || 'P' === $next) && '{' === ($body[$offset + 2] ?? '')) {
            $close = strpos($body, '}', $offset + 3);

            return false === $close ? \strlen($body) - 1 : $close;
        }

        return $offset + \strlen(self::operandAt($body, $offset + 1, $escapes, $nelSkipped)[0]);
    }

    /**
     * The operand of a backslash at $offset, with its escape, which takes
     * the backslash's place: read byte by byte under x, the backslash takes
     * the "\xC2" of a next-line control alone, and the NEL byte after it is
     * white space x skips.
     *
     * @param array<string, string> $escapes
     *
     * @return array{string, ?string}
     */
    private static function operandAt(string $body, int $offset, array $escapes, bool $nelSkipped): array
    {
        if ($nelSkipped && "\xC2\x85" === substr($body, $offset, 2)) {
            return ["\xC2", '\\xC2'];
        }

        return self::unitAt($body, $offset, $escapes);
    }

    /**
     * Where the quantifier "{...}" opened at $offset closes, as PCRE reads
     * one: a minimum, a maximum or both, around a comma, with spaces and
     * tabs allowed after "{", around the comma and before "}". Null when the
     * braces are a literal.
     */
    private static function quantifierEnd(string $body, int $offset): ?int
    {
        $at = $offset + 1 + strspn($body, " \t", $offset + 1);
        $digits = strspn($body, '0123456789', $at);
        $at += $digits;
        $at += strspn($body, " \t", $at);
        if (',' === ($body[$at] ?? '')) {
            $at += 1 + strspn($body, " \t", $at + 1);
            $maximum = strspn($body, '0123456789', $at);
            $digits += $maximum;
            $at += $maximum;
            $at += strspn($body, " \t", $at);
        }

        return $digits > 0 && '}' === ($body[$at] ?? '') ? $at : null;
    }

    /**
     * The character at $offset, with its escape, null when it is printed as
     * it is: a hidden character whole, any other byte alone.
     *
     * @param array<string, string> $escapes
     *
     * @return array{string, ?string}
     */
    private static function unitAt(string $text, int $offset, array $escapes): array
    {
        $byte = $text[$offset];
        if (\ord($byte) >= 0xC2) {
            foreach ([4, 3, 2] as $width) {
                $character = substr($text, $offset, $width);
                if (isset($escapes[$character])) {
                    return [$character, $escapes[$character]];
                }
            }
        }

        return [$byte, $escapes[$byte] ?? null];
    }

    /**
     * The x level a "(?...)" setting leaves: "(?x)" sets x alone, "(?xx)"
     * adds the white space of classes, and turning x off, "^" included,
     * turns both off.
     */
    private static function extendedLevel(InlineFlags $flags, int $level): int
    {
        if (str_contains($flags->unset, 'x')) {
            return 0;
        }

        return match (substr_count($flags->set, 'x')) {
            0 => $level,
            1 => 1,
            default => 2,
        };
    }

    /**
     * Where the white space and the "#" comments that x skips from $offset
     * end; $offset itself when there is none.
     */
    private static function skippedEnd(string $body, int $offset, bool $utf, string $newline): int
    {
        $length = \strlen($body);
        while ($offset < $length) {
            if ('#' === $body[$offset]) {
                $offset = self::commentEnd($body, $offset + 1, $utf, $newline);

                continue;
            }

            foreach (self::SKIPPED[(int) $utf] as $space) {
                if (substr($body, $offset, \strlen($space)) === $space) {
                    $offset += \strlen($space);

                    continue 2;
                }
            }

            break;
        }

        return $offset;
    }

    /**
     * Where a "#" comment read from $offset ends: past the first line break
     * of the newline convention, or at the end of the pattern.
     */
    private static function commentEnd(string $body, int $offset, bool $utf, string $newline): int
    {
        $breaks = self::LINE_BREAKS[$newline] ?? self::LINE_BREAKS['LF'];
        if ('ANY' === $newline) {
            $breaks = [...$breaks, ...($utf ? ["\u{85}", "\u{2028}", "\u{2029}"] : ["\x85"])];
        }

        // One pass to the first byte a break can start with; every stop
        // that starts none moves on from the byte after it.
        $firstBytes = implode('', array_map(static fn (string $break): string => $break[0], $breaks));
        $length = \strlen($body);
        for ($at = $offset + strcspn($body, $firstBytes, $offset); $at < $length; $at += 1 + strcspn($body, $firstBytes, $at + 1)) {
            foreach ($breaks as $break) {
                if (substr($body, $at, \strlen($break)) === $break) {
                    return $at + \strlen($break);
                }
            }
        }

        return $length;
    }

    /**
     * Where the pattern between the delimiters starts and ends, and the
     * modifiers after it, as PHP reads them: past the white space before
     * the opening delimiter, up to the first closing delimiter no backslash
     * escapes, where a bracket waits for the one that matches it. The whole
     * text, with no modifiers, when it is no delimited pattern.
     *
     * @return array{int, int, ?string}
     */
    private static function delimited(string $text): array
    {
        $length = \strlen($text);
        $start = strspn($text, " \t\n\r\v\f");
        $open = $text[$start] ?? '\\';
        if ('\\' === $open || "\0" === $open || Ascii::isAlnum($open)) {
            return [0, $length, null];
        }

        $close = self::BRACKETS[$open] ?? $open;
        $depth = 0;
        for ($i = $start + 1; $i < $length; $i++) {
            if ('\\' === $text[$i]) {
                $i++;
            } elseif ($close === $text[$i] && 0 === $depth--) {
                $modifiers = substr($text, $i + 1);

                return \strlen($modifiers) === strspn($modifiers, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ \n\r") ? [$start + 1, $i, $modifiers] : [0, $length, null];
            } elseif ($open === $text[$i]) {
                $depth++;
            }
        }

        return [0, $length, null];
    }

    /**
     * @return array<string, string>
     */
    private static function escapes(int $mode): array
    {
        if (!isset(self::$escapes[$mode])) {
            $escapes = [];
            for ($byte = 0x00; $byte <= (self::RAW_BYTES === $mode ? 0xFF : 0x7F); $byte++) {
                if ($byte < 0x20 || $byte >= 0x7F) {
                    $escapes[\chr($byte)] = self::LETTER_ESCAPES[\chr($byte)] ?? \sprintf('\\x%02X', $byte);
                }
            }

            if (self::RAW_BYTES !== $mode) {
                foreach (self::hiddenCharacters() as $character) {
                    $escapes[$character] = self::UTF8_CODE_POINTS === $mode ? \sprintf('\\x{%X}', mb_ord($character, 'UTF-8')) : self::hexBytes($character);
                }
            }

            self::$escapes[$mode] = $escapes;
        }

        return self::$escapes[$mode];
    }

    private static function hexBytes(string $bytes): string
    {
        $hex = '';
        foreach (str_split($bytes) as $byte) {
            $hex .= \sprintf('\\x%02X', \ord($byte));
        }

        return $hex;
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
        return 1 === LibraryPcre::match('//u', $text);
    }
}

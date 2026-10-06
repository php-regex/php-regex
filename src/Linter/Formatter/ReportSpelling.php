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

namespace PHPRegex\Linter\Formatter;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Internal\StartOptions;

/**
 * How a report spells the text it quotes.
 *
 * The machine formats (JSON, Checkstyle, JUnit) carry the text as it was
 * found: only the bytes that are no part of a UTF-8 character are written
 * "\xHH", so that the report stays valid UTF-8. The formats a person reads
 * (console, GitHub annotations) carry the display form, where a character
 * that moves or hides text is escaped in the pattern's own mode.
 *
 * @internal
 */
final class ReportSpelling
{
    /**
     * A byte that is no part of a valid UTF-8 character is captured; a valid
     * multi-byte character is matched whole and kept.
     */
    private const UTF8_OR_STRAY_BYTE = '/[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|([\x80-\xFF])/';

    /**
     * A character that moves or hides text when printed raw, as PCRE
     * classifies it: a C1 control, a format character (general category
     * Cf: the bidirectional controls, the zero-width space and joiners, the
     * byte order mark, the soft hyphen, the tag characters), a line or a
     * paragraph separator.
     */
    private const HIDDEN_CHARACTER = '/\A[\x{80}-\x{9F}\p{Cf}\p{Zl}\p{Zp}]\z/u';

    /**
     * The control bytes that drive the terminal or the log in text: NUL,
     * an escape character, a lone carriage return, DEL. Tab, line feed and
     * a carriage return before a line feed lay the text out.
     */
    private const LAYOUT_CONTROL = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\r(?!\n)/';

    /**
     * The control bytes of a field of one line: every C0 control and DEL.
     */
    private const FIELD_CONTROL = '/[\x00-\x1F\x7F]/';

    /**
     * The characters XML 1.0 cannot hold, even as a character reference:
     * the C0 controls other than tab, line feed and carriage return, and
     * the noncharacters U+FFFE and U+FFFF.
     */
    private const XML_FORBIDDEN = '/[\x00-\x08\x0B\x0C\x0E-\x1F]|\xEF\xBF([\xBE\xBF])/';

    /**
     * The text as found, every byte that is no part of a UTF-8 character
     * written "\xHH".
     */
    public static function source(string $text): string
    {
        return JsonDocument::spellInvalidBytes($text);
    }

    /**
     * The source text as an XML attribute value: tab, line feed and
     * carriage return as character references, which attribute value
     * normalization keeps, and the characters XML cannot hold in hex.
     */
    public static function xmlAttribute(string $text): string
    {
        return strtr(self::xmlEscape($text), ["\t" => '&#9;', "\n" => '&#10;', "\r" => '&#13;']);
    }

    /**
     * The source text as XML character data: a carriage return as a
     * character reference, which line-end normalization keeps, and the
     * characters XML cannot hold in hex.
     */
    public static function xmlText(string $text): string
    {
        return str_replace("\r", '&#13;', self::xmlEscape($text));
    }

    /**
     * A caret snippet ("Line N: excerpt", then the caret under the
     * character at fault) in display form: the excerpt spelled in the mode
     * of the pattern it was cut from, the caret moved under the same
     * character.
     */
    public static function displaySnippet(string $snippet, ?string $pattern): string
    {
        if (1 !== LibraryPcre::match('/\A(Line \d+: )([^\n]*)\n( *)\^\z/', $snippet, $match)) {
            return implode("\n", array_map(static fn (string $line): string => self::displayFragment($line, $pattern), explode("\n", $snippet)));
        }

        [, $label, $excerpt, $indent] = $match;
        $caretOffset = max(0, \strlen($indent) - \strlen($label));
        $before = substr($excerpt, 0, $caretOffset);
        $column = \strlen(self::displayFragment($before, $pattern)) + max(0, $caretOffset - \strlen($excerpt));

        return $label.self::displayFragment($excerpt, $pattern)."\n".str_repeat(' ', \strlen($label) + $column).'^';
    }

    /**
     * One line of a pattern in display form, spelled in the mode of the
     * whole pattern: under u or a leading (*UTF), a hidden character is
     * "\x{HEX}", otherwise each of its bytes is "\xHH". The line is never
     * reflowed, x or not.
     */
    public static function displayFragment(string $fragment, ?string $pattern): string
    {
        return DisplayEscaper::escapeFragment($fragment, self::isUtf($pattern));
    }

    /**
     * Text a person reads, such as a message or a hint, in display form: a
     * character that moves or hides text (a C1 control, a format character
     * such as a bidirectional control or a zero-width space, the line and
     * paragraph separators) is its code point "\x{HEX}", and a byte that is
     * no part of a UTF-8 character is "\xHH". A C0 control (NUL included)
     * and DEL are "\xHH", except tab, line feed and a carriage return before
     * a line feed, which lay the text out. Any other ASCII, a backslash
     * included, is kept as written.
     */
    public static function displayText(string $text): string
    {
        return self::spellControls(self::spellHidden($text), self::LAYOUT_CONTROL);
    }

    /**
     * A field of one line, such as a file name or a location, in display
     * form: as displayText(), with tab, line feed and carriage return
     * spelled "\xHH" too, so that the field cannot lay out a line of its
     * own.
     */
    public static function displayField(string $text): string
    {
        return self::spellControls(self::spellHidden($text), self::FIELD_CONTROL);
    }

    /**
     * Every character that moves or hides text written "\x{HEX}", and every
     * byte that is no part of a UTF-8 character "\xHH".
     */
    private static function spellHidden(string $text): string
    {
        if (1 !== LibraryPcre::match('/[\x80-\xFF]/', $text)) {
            return $text;
        }

        return LibraryPcre::replaceCallback(
            self::UTF8_OR_STRAY_BYTE,
            static fn (array $match): string => isset($match[1]) && '' !== $match[1]
                ? \sprintf('\x%02X', \ord($match[1]))
                : (1 === LibraryPcre::match(self::HIDDEN_CHARACTER, $match[0]) ? \sprintf('\x{%X}', mb_ord($match[0], 'UTF-8')) : $match[0]),
            $text,
        ) ?? $text;
    }

    /**
     * Every control byte the expression matches written "\xHH".
     */
    private static function spellControls(string $text, string $controls): string
    {
        return LibraryPcre::replaceCallback(
            $controls,
            static fn (array $match): string => \sprintf('\x%02X', \ord($match[0])),
            $text,
        ) ?? $text;
    }

    private static function xmlEscape(string $text): string
    {
        $text = LibraryPcre::replaceCallback(
            self::XML_FORBIDDEN,
            static fn (array $match): string => match ($match[1] ?? '') {
                "\xBE" => '\\x{FFFE}',
                "\xBF" => '\\x{FFFF}',
                default => \sprintf('\\x%02X', \ord($match[0])),
            },
            self::source($text),
        ) ?? $text;

        return htmlspecialchars($text, \ENT_XML1 | \ENT_QUOTES);
    }

    /**
     * Whether PCRE reads the pattern as UTF: u among its modifiers, or a
     * leading (*UTF). Text that is no delimited pattern is read as code
     * points, as DisplayEscaper reads it.
     */
    private static function isUtf(?string $pattern): bool
    {
        if (null === $pattern) {
            return true;
        }

        try {
            [$body, $modifiers] = PatternParser::extractPatternAndFlags($pattern);
        } catch (ParserException) {
            return true;
        }

        return str_contains($modifiers, 'u') || StartOptions::turnUtfOn($body);
    }
}

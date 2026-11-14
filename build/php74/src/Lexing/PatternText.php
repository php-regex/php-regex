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

namespace PHPRegex\Parser\Lexing;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\PhpVersionGates;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\StartOptions;

/**
 * The text one tokenizing reads, and how PCRE reads it: as bytes or as
 * UTF-8, under which newline convention, with or without
 * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, and where it stands in the whole
 * pattern when it is a part of it, such as the body of an alphabetic
 * assertion.
 *
 * Nothing of it changes while the text is read: each tokenizing builds its
 * own. It keeps one cache, where the last search for a byte found it, which
 * every reader of the text shares.
 *
 * @internal
 */
final class PatternText
{
    /**
     */
    public int $length;

    /**
     * Whether the text is not valid UTF-8, and read byte by byte, the way
     * PCRE compiles it outside UTF mode.
     *
     */
    public bool $byteMode;

    /**
     * Whether PCRE reads the pattern as UTF-8, one character being several
     * bytes: under "u", or "(*UTF)" at its start.
     *
     */
    public bool $utf;
    /**
     * Modifier letters accepted inside "(?...)": what the parser takes, plus
     * the PCRE2 10.43 "r". Whether "r" is allowed on the running PHP version
     * is the parser's call; here it only decides where /x starts.
     */
    private const INLINE_FLAG_LETTERS = InlineFlags::LETTERS.'r';

    /**
     * Whether the pattern read is compiled with
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL: PHP 7.3 and 7.4 without "X". An
     * escape PCRE2 refuses as it reads it is then the character after the
     * backslash.
     *
     */
    public bool $badEscapeIsLiteral;

    /**
     * The newline convention set by the options the whole pattern opens
     * with, "LF" when they set none, and whether PCRE reads the whole
     * pattern as UTF-8: under "x" a "#" comment ends at that newline.
     *
     */
    public string $newline;

    /**
     */
    public bool $newlineUtf;

    /**
     * By byte, where the search for it in the text started, and the first
     * one it found there, false when none follows: a run of "\p{" that no
     * "}" closes, or of "(?C" that no ")" closes, is not searched to the end
     * once per opener.
     *
     * @var array<string, array{0: int, 1: int|false}>
     */
    private array $nextFound = [];
    /**
     */
    public TargetSyntax $syntax;
    /**
     */
    public string $pattern;
    /**
     */
    public int $offset;

    /**
     * @param string $pattern      the text read
     * @param string $flags        the modifiers it is compiled with
     * @param string $wholePattern the whole pattern the text stands in, the text itself unless a part of it is read
     * @param int    $offset       where the text starts in the whole pattern
     *
     * @throws LexerException when PCRE reads the text as UTF-8 and it is not
     */
    public function __construct(
        TargetSyntax $syntax,
        string $pattern,
        string $flags,
        string $wholePattern,
        int $offset
    ) {
        $this->syntax = $syntax;
        $this->pattern = $pattern;
        $this->offset = $offset;
        // Patterns that are not valid UTF-8 are tokenized byte by byte, the
        // way PCRE compiles them outside UTF mode. In UTF mode, set by /u or
        // by "(*UTF)" among the settings that open the pattern, PCRE itself
        // refuses them, at the first byte that starts no UTF-8 character.
        $this->byteMode = 1 !== LibraryPcre::match('//u', $pattern);
        $this->utf = false !== strpos($flags, 'u')
            || StartOptions::turnUtfOn($pattern);
        if ($this->byteMode && $this->utf) {
            throw LexerException::withContext('Input string is not valid UTF-8.', ErrorCode::EncodingInvalidUtf8(), self::firstInvalidUtf8Offset($pattern), $pattern);
        }

        $this->length = \strlen($pattern);
        $this->badEscapeIsLiteral = PhpVersionGates::badEscapeIsLiteral($syntax->phpVersionId, $flags);
        $this->newline = StartOptions::newline($wholePattern);
        $this->newlineUtf = $this->utf || StartOptions::turnUtfOn($wholePattern);
    }

    /**
     * Whether $byte stands at or after $from in the text. What the last
     * search for it found is kept: a search from between its start and the
     * byte it found, or from past its start when it found none, is not run
     * again.
     */
    public function follows(string $byte, int $from): bool
    {
        $found = $this->nextFound[$byte] ?? null;
        if (null === $found || $from < $found[0] || (false !== $found[1] && $from > $found[1])) {
            $found = $this->nextFound[$byte] = [$from, strpos($this->pattern, $byte, $from)];
        }

        return false !== $found[1];
    }

    /**
     * Whether a "\p{" or "\P{" that no "}" closes stands at $at: the escape
     * "\p" or "\P" then, and "{" the text after it.
     */
    public function opensUnclosedProperty(int $at): bool
    {
        return '\\' === $this->pattern[$at]
            && \in_array($this->pattern[$at + 1] ?? '', ['p', 'P'], true)
            && '{' === ($this->pattern[$at + 2] ?? '')
            && !$this->follows('}', $at + 3);
    }

    /**
     * Where the first newline at or after $from starts in the text, and its
     * length in bytes, under the newline convention the leading options of
     * the pattern set; null when none follows.
     *
     * "(*LF)", the default, ends a line at "\n", "(*CR)" at "\r", "(*NUL)"
     * at NUL, "(*CRLF)" at "\r\n" only, "(*ANYCRLF)" at any of "\r", "\n"
     * and "\r\n", and "(*ANY)" at any of those, "\v", "\f" and NEL, which
     * is the byte 0x85 outside UTF mode, and in UTF mode the character
     * U+0085 and U+2028 and U+2029 too.
     *
     * @return array{0: int, 1: int}|null
     */
    public function nextNewline(int $from): ?array
    {
        switch (true) {
            case 'CR' === $this->newline:
            case 'CRLF' === $this->newline:
                $stops = "\r";
                break;
            case 'NUL' === $this->newline:
                $stops = "\0";
                break;
            case 'ANYCRLF' === $this->newline:
                $stops = "\r\n";
                break;
            case 'ANY' === $this->newline:
                $stops = $this->newlineUtf ? "\n\v\f\r\xC2\xE2" : "\n\v\f\r\x85";
                break;
            default:
                $stops = "\n";
                break;
        }

        for ($at = $from + strcspn($this->pattern, $stops, $from); $at < $this->length; $at += 1 + strcspn($this->pattern, $stops, $at + 1)) {
            $length = $this->newlineLength($at);
            if ($length > 0) {
                return [$at, $length];
            }
        }

        return null;
    }

    /**
     * Read the modifiers that follow the "(?" ending at $at, if the group
     * carries any at all.
     *
     * @return array{0: InlineFlags, 1: bool, 2: int}|null the modifiers,
     *                                                     whether the group
     *                                                     is scoped with ':',
     *                                                     and the offset past
     *                                                     that ':' or ')'
     */
    public function inlineFlags(int $at): ?array
    {
        $matches = [];
        if (1 !== LibraryPcre::match('/\G(\^?[a-zA-Z]*(?:-[a-zA-Z]+)?)([:)])/A', $this->pattern, $matches, 0, $at)) {
            return null;
        }

        $flags = InlineFlags::read(InlineFlags::withoutAsciiOptions($matches[1]), self::INLINE_FLAG_LETTERS);

        return null === $flags ? null : [$flags, ':' === $matches[2], $at + \strlen($matches[0])];
    }

    /**
     * The length of the newline at $at, which holds one of the bytes a
     * newline of the convention in force starts with; 0 when none starts
     * there.
     */
    private function newlineLength(int $at): int
    {
        $text = (string) substr($this->pattern, $at, 3);
        if (0 === strncmp($text, "\r\n", strlen("\r\n")) && \in_array($this->newline, ['CRLF', 'ANYCRLF', 'ANY'], true)) {
            return 2;
        }

        if ('CRLF' === $this->newline) {
            return 0;
        }

        switch (true) {
            case "\xC2" === $text[0]:
                return 0 === strncmp($text, "\xC2\x85", strlen("\xC2\x85")) ? 2 : 0;
            case "\xE2" === $text[0]:
                return "\xE2\x80\xA8" === $text || "\xE2\x80\xA9" === $text ? 3 : 0;
            default:
                return 1;
        }
    }

    /**
     * Where the longest run of well-formed UTF-8 at the start of $text ends.
     * Overlong forms, surrogates and code points past U+10FFFF are no UTF-8.
     */
    private static function firstInvalidUtf8Offset(string $text): int
    {
        LibraryPcre::match(
            '/\A(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            .'|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+/',
            $text,
            $valid,
        );

        return \strlen($valid[0] ?? '');
    }
}

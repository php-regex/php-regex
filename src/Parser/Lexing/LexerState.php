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

use PHPRegex\Parser\Text\Utf8;

/**
 * Where one tokenizing stands in the text it reads, and the modes it reads
 * in there: in a class, in a quoted run or a comment, under "x" or "(?xx)".
 *
 * The lexer holds one for its life and starts it again on each text; the
 * readers of its modes are handed the same one, and move it as the lexer
 * does. Its fields are read and written in place, not through methods: the
 * loop over the characters of the text reads them at each one.
 *
 * It also keeps what outlives one text: where the bodies of the whole
 * pattern end, as far as they were read, and whether the bodies of the
 * alphabetic assertions are read in place.
 *
 * @internal
 */
final class LexerState
{
    /**
     * The text read, how PCRE reads it, and where it stands in the whole
     * pattern.
     */
    public PatternText $text;

    /**
     * The text read, its length and whether it is read byte by byte, as the
     * text holds them.
     */
    public string $pattern = '';

    public int $length = 0;

    public bool $byteMode = false;

    public int $position = 0;

    public bool $inCharClass = false;

    public bool $inQuoteMode = false;

    public bool $inCommentMode = false;

    /**
     * Where the POSIX item read whole, character by character, ends: its
     * "]" when it opens a class, past it in a class.
     */
    public ?int $classTextEnd = null;

    public bool $extendedMode = false;

    /**
     * Whether "(?xx)" is in force, under which PCRE also skips the spaces and
     * tabs of a class. PHP has no "xx" pattern modifier: only the inline
     * setting turns it on.
     */
    public bool $extendedMoreMode = false;

    /**
     * Values of $extendedMode and $extendedMoreMode saved by each open group,
     * restored when it closes.
     *
     * @var array<array{0: bool, 1: bool}>
     */
    public array $extendedModeStack = [];

    /**
     * Where each class still open opens, the innermost last.
     *
     * @var array<int>
     */
    public array $charClassStartPositions = [];

    /**
     * The quoted run read last: where the reading of it started, where its
     * text ends, and what ends it, "\E" or "" at the end of the text. A run
     * in a class is read one character at a time, each without searching
     * the rest of the run again.
     *
     * @var array{0: int, 1: int, 2: string}|null
     */
    public ?array $quotedRun = null;

    /**
     * The opener of the first body that never closes, "(*pla:" or "(?*",
     * read in place.
     */
    public ?string $unclosedBody = null;

    /**
     * Where each escape read as its character stands.
     *
     * @var array<int, true>
     */
    public array $literalEscapes = [];

    /**
     * Where the token tokenizing stopped on starts, when it is refused for
     * itself, whatever stands around it.
     */
    public ?int $refusedTokenStart = null;

    /**
     * Whether the body of every alphabetic assertion is read in place, as
     * PCRE reads it, rather than as one token: its opener, then the tokens
     * of the body, then its ")".
     */
    public bool $bodiesInPlace = false;

    /**
     * Where the bodies of the whole pattern end, as far as they were read:
     * built at the first body the lexer reads ahead.
     */
    public ?BodyEnds $bodies = null;

    /**
     * Stands at the start of $text, outside any class, group, quoted run or
     * comment, under "x" when $flags holds it.
     *
     * @param bool $extendedMore whether "(?xx)" holds where the text starts
     */
    public function start(PatternText $text, string $flags, bool $extendedMore): void
    {
        $this->text = $text;
        $this->pattern = $text->pattern;
        $this->length = $text->length;
        $this->byteMode = $text->byteMode;
        $this->extendedMode = str_contains($flags, 'x');
        $this->extendedMoreMode = $extendedMore;
        $this->position = 0;
        $this->extendedModeStack = [];
        $this->inCharClass = false;
        $this->inQuoteMode = false;
        $this->inCommentMode = false;
        $this->classTextEnd = null;
        $this->charClassStartPositions = [];
        $this->quotedRun = null;
        $this->unclosedBody = null;
        $this->literalEscapes = [];
        $this->refusedTokenStart = null;
    }

    /**
     * The length in bytes of the character at $at: one byte in byte mode;
     * else, the text being valid UTF-8, its first byte and the bytes that
     * continue it.
     */
    public function characterLength(int $at): int
    {
        return $this->byteMode ? 1 : Utf8::width($this->pattern, $at);
    }
}

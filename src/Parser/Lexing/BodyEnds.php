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
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Syntax\BadEscape;
use PHPRegex\Parser\Syntax\ExtendedClassReader;
use PHPRegex\Parser\Syntax\PosixItem;
use PHPRegex\Parser\Text\Utf8;

/**
 * Where the bodies the lexer reads ahead of their tokens end: the body of an
 * alphabetic assertion or a script run, read as PCRE reads any group body,
 * and the Perl extended class "(?[...])".
 *
 * It reads the text, and the modes "x" and "xx" in force where a body
 * opens. It keeps where the bodies it read end in the whole pattern, so that
 * a body nested in bodies is not read again at each level the parser
 * tokenizes, until the lexer starts on another pattern; and the regex that
 * reads one item of a body, by reading mode.
 *
 * @internal
 */
final class BodyEnds
{
    /**
     * Text quoted by \Q...\E, possibly up to the end of the pattern.
     */
    private const QUOTED_RUN = '\\\\Q (?: (?!\\\\E) [\\s\\S] )*+ (?: \\\\E | \\z )';

    /**
     * One item of a group body in which a ")" does not close the group: a
     * quoted run, an escape ("\c" with the character it takes, whatever it
     * is, and "\p{...}" to its "}"), a "(?#...)" comment, a callout, a verb
     * that ends at its first ")" as "(*MARK:a(b)" does, or text without
     * parentheses. Classes and nested groups are read by the caller. Each
     * ends where the lexer ends the token it reads there.
     */
    private const GROUP_BODY_ITEM = self::QUOTED_RUN
        .' | \\\\ [pP] \\{ [^}]++ \\} | \\\\ c [\\s\\S] | \\\\ (?!Q) [\\s\\S]'
        .' | \\( \\? \\# [^)]*+ \\)'
        .' | \\( \\? C (?: (?: '.Lexer::CALLOUT_STRING.' ) (?= \\) ) | [^)]*+ ) \\)'
        .' | \\( \\* (?! [a-z_]++ : ) [^)]*+ \\)'
        .self::GROUP_BODY_TEXT;

    /**
     * Text without parentheses, the last item of a group body.
     */
    private const GROUP_BODY_TEXT = ' | [^()\\\\\\[]++';

    /**
     * The same text under "x", where "#" starts a comment that runs to the
     * end of the line, which the caller reads.
     */
    private const GROUP_BODY_TEXT_EXTENDED = ' | [^()\\\\\\[\\#]++';

    /**
     * The letters a POSIX class name is made of, "[:alpha:]".
     */
    private const POSIX_NAME_LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * The regex reading one item of a body, by reading mode: a string built
     * once, which PCRE keeps compiled across the lexers.
     *
     * @var array<string, string>
     */
    private array $regexes = [];

    /**
     * Where the bodies opened in the whole pattern end, as far as they were
     * read: by reading mode, then by the offset of their "(" in the whole
     * pattern, the offset past their ")", null for a body that never
     * closes, and the end of the text they were read in.
     *
     * @var array<int, array<int, array{0: int|null, 1: int}>>
     */
    private array $ends = [];

    /**
     * Forget where the bodies end: the lexer starts on another pattern.
     */
    public function forget(): void
    {
        $this->ends = [];
    }

    /**
     * Where the alphabetic assertion or script run opened at $position by
     * $opener, "(*name:" or "(?*", ends past its ")"; null when its body
     * never closes.
     *
     * The body is read as any group body is: a ")" escaped, quoted by
     * \Q...\E, inside a class or inside a "(?#...)" comment does not close
     * it, and its own groups nest as deep as they like. Each item is the
     * first that matches at its place and is never read again; a "(" that
     * opens no item opens a group, and the first character that neither
     * starts an item nor opens or closes a group ends the body unclosed.
     *
     * An option setting in the body holds there as anywhere else: "x" set
     * by "(?x)" or "(?xx)", cleared by "(?-x)" or "(?^)", holds to the end
     * of the group it stands in, later alternatives included, and one set
     * by "(?x:" only inside that group; a group's ")" gives back the mode
     * its "(" was read in. Under "x" a "#" starts a comment that runs to
     * the end of the line, a ")" in it included. Under "xx" the spaces and
     * tabs before the first member of a class are skipped too.
     *
     * @param bool $extended     whether "x" is in force where the body opens
     * @param bool $extendedMore whether "xx" is in force there
     */
    public function assertionEnd(PatternText $text, int $position, string $opener, bool $extended, bool $extendedMore): ?int
    {
        // Each reading records where the groups it meets close, or that they
        // never do, under the mode each "(" is read in: past the opener of a
        // body, the reading from the "(" of that body goes on item for item
        // as the reading of the body itself does, from the same mode. A body
        // met again at the same place in the same mode is not read again:
        // the end found holds in a text that still holds its ")" and ends no
        // later than the text it was found in; "never closes" holds in a
        // text that ends at the same place.
        $pattern = $text->pattern;
        $length = $text->length;
        $offset = $text->offset;
        $textEnd = $offset + $length;
        $known = $this->ends[self::bodyMode($text, $extended, $extendedMore)][$offset + $position] ?? null;
        if (null !== $known && (null === $known[0] ? $known[1] === $textEnd : $known[0] <= $textEnd && $textEnd <= $known[1])) {
            return null === $known[0] ? null : $known[0] - $offset;
        }

        $at = $position + \strlen($opener);
        // Each group open: where its "(" stands, and whether "x" and "xx"
        // held there.
        $opened = [[$position, $extended, $extendedMore]];
        // No ")" left, no body closes: the run of openers after it is not
        // read again for each one.
        if (!$text->follows(')', $at)) {
            $this->unclosedBodies($text, $opened);

            return null;
        }

        while ($at < $length) {
            if ('[' === $pattern[$at]) {
                $at = self::bodyClassEnd($text, $at, $extendedMore);
                if (null === $at) {
                    $this->unclosedBodies($text, $opened);

                    return null;
                }

                continue;
            }

            // Under "x" a "#" comment runs to the newline the pattern sets.
            if ($extended && '#' === $pattern[$at]) {
                $newline = $text->nextNewline($at + 1);
                $at = null === $newline ? $length : $newline[0] + $newline[1];
                while ($at < $length && Utf8::continues($pattern[$at])) {
                    $at++;
                }

                continue;
            }

            if ($text->opensUnclosedProperty($at)) {
                $at += 2;

                continue;
            }

            // An escape PHP 7.3 and 7.4 read as its character takes no more:
            // "\p{L)}" is "p{L" then the ")" that closes the body.
            if ($text->badEscapeIsLiteral && '\\' === $pattern[$at] && BadEscape::at($pattern, $at, false, $text->utf, $text->syntax->target)) {
                $at += 2;

                continue;
            }

            if (1 === LibraryPcre::match($this->bodyItemRegex($text, $extended), $pattern, $read, 0, $at)) {
                $at += \strlen($read[0]);

                continue;
            }

            $char = $pattern[$at];
            // "(?[...])" is read as the lexer reads it, a "#" in it no comment.
            if ('(' === $char && $text->syntax->readsExtendedClass && '(?[' === substr($pattern, $at, 3)) {
                $at = self::extendedClassEnd($text, $at);

                continue;
            }

            if ('(' === $char && '(?#' !== substr($pattern, $at, 3)) {
                // "(?x)" changes the mode of the group it stands in, "(?x:"
                // opens a group read in the mode it sets.
                $setting = '?' === ($pattern[$at + 1] ?? '') ? $text->inlineFlags($at + 2) : null;
                if (null === $setting || $setting[1]) {
                    $opened[] = [$at, $extended, $extendedMore];
                }

                if (null !== $setting) {
                    $extended = $setting[0]->inForce('x', $extended);
                    $extendedMore = $setting[0]->extendedMoreInForce($extendedMore);
                    $at = $setting[2];

                    continue;
                }
            } elseif (')' !== $char) {
                $this->unclosedBodies($text, $opened);

                return null;
            } else {
                [$open, $extended, $extendedMore] = array_pop($opened) ?? [$position, $extended, $extendedMore];
                $this->ends[self::bodyMode($text, $extended, $extendedMore)][$offset + $open] = [$offset + $at + 1, $textEnd];
                if ([] === $opened) {
                    return $at + 1;
                }
            }

            $at++;
        }

        $this->unclosedBodies($text, $opened);

        return null;
    }

    /**
     * Where the "(?[" at $start ends: past the "]" that closes it at its own
     * level and the ")" after it, at a ")" at its own level, or at the end
     * of the text.
     */
    public static function extendedClassEnd(PatternText $text, int $start): int
    {
        $pattern = $text->pattern;
        $length = $text->length;
        $at = $start + 3;
        $depth = 0;
        while ($at < $length) {
            $char = $pattern[$at];
            if ('\\' === $char) {
                $quoteEnd = 'Q' === ($pattern[$at + 1] ?? '') ? strpos($pattern, '\\E', $at + 2) : null;
                $at = null === $quoteEnd ? $at + ExtendedClassReader::escapeLength($pattern, $at, $text->utf) : (false === $quoteEnd ? $length : $quoteEnd + 2);

                continue;
            }

            if ('[' === $char) {
                $at = ExtendedClassReader::endOfClass($pattern, $at);

                continue;
            }

            if (')' === $char && 0 === $depth) {
                break;
            }

            if (']' === $char && 0 === $depth) {
                $at += ')' === ($pattern[$at + 1] ?? '') ? 2 : 1;

                break;
            }

            $depth += '(' === $char ? 1 : (')' === $char ? -1 : 0);
            $at++;
        }

        return min($at, $length);
    }

    /**
     * Record that none of the groups still open when the reading of a body
     * stopped closes in the text read, each under the mode its "(" was read
     * in.
     *
     * @param list<array{0: int, 1: bool, 2: bool}> $opened
     */
    private function unclosedBodies(PatternText $text, array $opened): void
    {
        foreach ($opened as [$open, $extended, $extendedMore]) {
            $this->ends[self::bodyMode($text, $extended, $extendedMore)][$text->offset + $open] = [null, $text->offset + $text->length];
        }
    }

    /**
     * One item of the body of an alphabetic assertion, varying with the byte
     * mode and with "x", under which "#" starts a comment in the body too.
     */
    private function bodyItemRegex(PatternText $text, bool $extended): string
    {
        $key = 'body'.self::bodyMode($text, $extended);
        if (!isset($this->regexes[$key])) {
            $item = $extended
                ? str_replace(self::GROUP_BODY_TEXT, self::GROUP_BODY_TEXT_EXTENDED, self::GROUP_BODY_ITEM)
                : self::GROUP_BODY_ITEM;
            $this->regexes[$key] = '/(?:'.$item.')/xsA'.($text->byteMode ? '' : 'u');
        }

        return $this->regexes[$key];
    }

    /**
     * The reading mode of a body: the byte mode, and whether "x" and "xx"
     * are in force where the body opens.
     */
    private static function bodyMode(PatternText $text, bool $extended, bool $extendedMore = false): int
    {
        return ($text->byteMode ? 1 : 0) + ($extended ? 2 : 0) + ($extendedMore ? 4 : 0);
    }

    /**
     * Where the class opened at $open in the body of an alphabetic assertion
     * ends, past its "]"; null when it never closes.
     *
     * A "]" first in the class is a member, as PCRE reads it: PCRE skips
     * "\E", an empty "\Q\E" and one "^", in any order, before the first
     * member, so "[]" alone never closes, and the class runs to the end of
     * the text, and "[\E]a]" holds "]" and "a". A member is a POSIX class
     * "[:alpha:]", a quoted run, an escape ("\c" with the character it
     * takes, "\p{...}" to its "}"), or any character but "]" and the
     * backslash. Under "xx" PCRE skips spaces and tabs there too, so
     * "[ ]a]" holds "]" and "a".
     */
    private static function bodyClassEnd(PatternText $text, int $open, bool $extendedMore): ?int
    {
        $pattern = $text->pattern;
        $first = $open + 1;
        $negated = false;
        while (true) {
            if ($extendedMore && \in_array($pattern[$first] ?? '', [' ', "\t"], true)) {
                $first++;
            } elseif ('\\E' === substr($pattern, $first, 2)) {
                $first += 2;
            } elseif ('\\Q\\E' === substr($pattern, $first, 4)) {
                $first += 4;
            } elseif (!$negated && '^' === ($pattern[$first] ?? '')) {
                $negated = true;
                $first++;
            } else {
                break;
            }
        }

        $first += ']' === ($pattern[$first] ?? '') ? 1 : 0;
        $end = self::bodyClassMembersEnd($text, $first);

        return ']' === ($pattern[$end] ?? '') ? $end + 1 : null;
    }

    /**
     * Where the members of a class in the body of an alphabetic assertion,
     * read from $at, stop: at a "]" that closes the class, at a backslash
     * that ends the text read, or at the end. Each member is the first that
     * matches at its place.
     *
     * A POSIX class is "[:", an optional "^", letters and ":]", as the lexer
     * reads it in any class; any other "[:" is a member of one character.
     * In "[[:[:]]" the first "]" closes the class, as it does for PCRE, and
     * so it does in "[[:!:]]" and "[[::]]", names PCRE refuses: the class
     * ends where the lexer ends it.
     */
    private static function bodyClassMembersEnd(PatternText $text, int $at): int
    {
        $pattern = $text->pattern;
        $length = $text->length;
        while ($at < $length) {
            $char = $pattern[$at];
            if (']' === $char) {
                break;
            }

            // So is a POSIX item whose name holds a backslash, "[:\d-z:]".
            $terminator = '[' === $char ? PosixItem::terminatorPastABackslash($pattern, $at) : null;
            if (null !== $terminator) {
                $at = $terminator + 2;

                continue;
            }

            if ('[' === $char && ':' === ($pattern[$at + 1] ?? '')) {
                $name = $at + 2 + ('^' === ($pattern[$at + 2] ?? '') ? 1 : 0);
                $letters = strspn($pattern, self::POSIX_NAME_LETTERS, $name);
                if ($letters > 0 && ':]' === substr($pattern, $name + $letters, 2)) {
                    $at = $name + $letters + 2;

                    continue;
                }
            }

            if ('\\' !== $char) {
                $at++;

                continue;
            }

            if ($at + 1 >= $length) {
                break;
            }

            if ('Q' === $pattern[$at + 1]) {
                $quoteEnd = strpos($pattern, '\\E', $at + 2);
                $at = false === $quoteEnd ? $length : $quoteEnd + 2;

                continue;
            }

            // "\p{...}" runs to its "}", a "]" in it included, as the lexer
            // reads it in a class.
            if (!$text->opensUnclosedProperty($at) && 1 === LibraryPcre::match('/\G\\\\[pP]\{[^}]++\}/', $pattern, $property, 0, $at)) {
                $at += \strlen($property[0]);

                continue;
            }

            // "\c" takes the character after it, a "]" too; a byte at a time
            // is enough, no character but its first byte can be "]" or "\".
            $at += 'c' === $pattern[$at + 1] && $at + 2 < $length ? 3 : 2;
        }

        return $at;
    }
}

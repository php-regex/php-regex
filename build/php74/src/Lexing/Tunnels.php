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
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\Text\Utf8;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * The text the lexer reads through without the token regex: a quoted run,
 * "\Q" to "\E", a comment, "(?#" to ")", and under "x" a "#" comment to the
 * end of its line. Nothing in them is syntax.
 *
 * It reads where the lexer's state stands and moves it, entering and leaving
 * the quote and comment modes; it keeps nothing.
 *
 * @internal
 */
final class Tunnels
{
    private const QUOTE_END = '\E';

    private const COMMENT_CLOSE = ')';

    /**
     * The "\Q" at $start, which the cursor stands past: what follows is
     * quoted.
     */
    public static function openQuote(LexerState $state, int $start): Token
    {
        $state->inQuoteMode = true;

        return new Token(TokenType::QuoteModeStart(), '\Q', $start);
    }

    /**
     * The quoted text at the cursor, or the "\E" that ends it; null at the
     * end of the text, which a quoted run may reach: PCRE reads "\Q" without
     * "\E" to the end of the pattern, and the cursor stays in quote mode.
     */
    public static function quoted(LexerState $state): ?Token
    {
        // A run in a class is read one character at a time: where the run
        // ends is searched once, not again for each character.
        $run = $state->quotedRun;
        if (null === $run || $state->position < $run[0] || $state->position > $run[1]) {
            // "\z", not "$": "$" stops before a final newline, which the
            // quoted run holds, and would drop it.
            if (1 !== LibraryPcre::match(self::anchored($state, '(.*?)((\\\\E|\z))', 's'), $state->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $state->position)) {
                // Nothing here can fail to match, and PCRE giving up throws
                // on its own. Leaving quote mode and skipping to the end
                // would drop the rest of the pattern without a word.
                throw InternalFailureException::unexpectedState('The regex reading a quoted run did not match.');
            }

            // Both groups always take part in the match; the null the
            // unmatched flag would give is not reachable.
            $run = $state->quotedRun = [$state->position, $state->position + \strlen((string) $matches[1]), (string) $matches[2]];
        }

        [, $textEnd, $endSequence] = $run;
        $startPos = $state->position;

        if ($textEnd > $startPos) {
            // Inside a class a quoted run stands for its characters one by
            // one: "[\Qabc\E-z]" is a, b and the range c-z.
            $length = $state->inCharClass ? $state->characterLength($startPos) : $textEnd - $startPos;
            $state->position += $length;

            return new Token(TokenType::Literal(), (string) substr($state->pattern, $startPos, $length), $startPos);
        }

        if (self::QUOTE_END === $endSequence) {
            $state->inQuoteMode = false;
            $token = new Token(TokenType::QuoteModeEnd(), self::QUOTE_END, $state->position);
            $state->position += \strlen(self::QUOTE_END);

            return $token;
        }

        // End of pattern reached without \E - PCRE treats \Q without \E as valid
        // (quotes to end of pattern). Keep inQuoteMode = true per PCRE semantics.
        $state->position = $state->length;

        return null;
    }

    /**
     * The "(?#" at $start, which the cursor stands past: what follows up to
     * the first ")" is a comment.
     */
    public static function openComment(LexerState $state, int $start): Token
    {
        $state->inCommentMode = true;

        return new Token(TokenType::CommentOpen(), '(?#', $start);
    }

    /**
     * The text of the comment at the cursor, or the ")" that closes it; null
     * at the end of the text, the comment still open.
     */
    public static function comment(LexerState $state): ?Token
    {
        // Nothing here can fail to match, and PCRE giving up throws on its own.
        if (1 !== LibraryPcre::match(self::anchored($state, '([^)]*)(\)|$)'), $state->pattern, $matches, \PREG_UNMATCHED_AS_NULL, $state->position)) {
            throw InternalFailureException::unexpectedState('The regex reading a comment did not match.');
        }

        $commentText = (string) $matches[1];
        $endSequence = $matches[2];
        $startPos = $state->position;

        if ('' !== $commentText) {
            $state->position += \strlen($commentText);

            return new Token(TokenType::Literal(), $commentText, $startPos);
        }

        if (self::COMMENT_CLOSE === $endSequence) {
            $state->inCommentMode = false;
            $token = new Token(TokenType::GroupClose(), self::COMMENT_CLOSE, $state->position);
            $state->position += \strlen(self::COMMENT_CLOSE);

            return $token;
        }

        $state->position = $state->length;

        return null;
    }

    /**
     * Under /x a '#' starts a comment that runs to the end of the line, so its
     * content is text: '[', '(' and friends must not be tokenized as regex
     * syntax. The comment is emitted as literals — '#', the body and the
     * closing newline — which is what the parser turns into a CommentNode.
     * The line ends at the newline the leading options of the pattern set:
     * that newline is given the value "\n" whatever its bytes, which the
     * token still spans.
     *
     * @param list<Token> $tokens
     */
    public static function extendedComment(LexerState $state, array &$tokens): void
    {
        $tokens[] = new Token(TokenType::Literal(), '#', $state->position);
        ++$state->position;

        $newline = $state->text->nextNewline($state->position);
        $bodyEnd = null === $newline ? $state->length : $newline[0];

        if ($bodyEnd > $state->position) {
            $body = (string) substr($state->pattern, $state->position, $bodyEnd - $state->position);
            $tokens[] = new Token(TokenType::Literal(), $body, $state->position);
            $state->position = $bodyEnd;
        }

        if (null === $newline) {
            return;
        }

        $tokens[] = new Token(TokenType::Literal(), "\n", $state->position, $newline[1]);
        $state->position += $newline[1];

        // Outside UTF mode the byte 0x85 ends the line under "(*ANY)" even
        // inside a UTF-8 character: the bytes left of that character are
        // read one by one, as PCRE reads them.
        while ($state->position < $state->length && Utf8::continues($state->pattern[$state->position])) {
            $tokens[] = new Token(TokenType::Literal(), $state->pattern[$state->position], $state->position);
            ++$state->position;
        }
    }

    /**
     * Anchor a sub-pattern at the cursor, reading the subject the way the
     * pattern itself is read.
     *
     * A pattern that is not valid UTF-8 is tokenized byte by byte, so the
     * "u" modifier must go with it: asking PCRE to read invalid UTF-8 under
     * /u fails, and a failure here used to be taken for "nothing left to
     * read".
     */
    private static function anchored(LexerState $state, string $pattern, string $modifiers = ''): string
    {
        return '/'.$pattern.'/A'.$modifiers.($state->byteMode ? '' : 'u');
    }
}

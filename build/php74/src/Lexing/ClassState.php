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

use PHPRegex\Parser\Syntax\PosixItem;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * The classes the lexer reads: where one opens and closes, the "^" and "\E"
 * PCRE skips before its first member, a POSIX item read whole, and the Perl
 * extended class "(?[...])" read as one token.
 *
 * It reads where the lexer's state stands and moves it, keeping on it where
 * each class still open opens; it keeps nothing itself.
 *
 * @internal
 */
final class ClassState
{
    /**
     * The "[" at $start, which the cursor stands past: a class opened, or in
     * a class a member or the POSIX item it opens.
     */
    public static function open(LexerState $state, int $start): Token
    {
        // PHP compiles without PCRE2's extended class syntax, so a "[" inside
        // a class is a member: nothing nests. A POSIX item whose name holds
        // a backslash, "[:\d-z:]", is read whole, as PCRE reads it, not as
        // the escapes it holds: one token in a class; the "[" of a collating
        // element, "[.\d.]", then each of its characters; the characters of
        // the class it opens otherwise.
        $terminator = PosixItem::terminatorPastABackslash($state->pattern, $start);
        if ($state->inCharClass) {
            if (null !== $terminator && ':' === $state->pattern[$start + 1]) {
                $state->position = $terminator + 2;

                return new Token(TokenType::PosixClass(), (string) substr($state->pattern, $start + 2, $terminator - $start - 2), $start, $state->position - $start);
            }

            $state->classTextEnd = null === $terminator ? null : $terminator + 2;

            return new Token(TokenType::Literal(), '[', $start);
        }

        $state->classTextEnd = null === $terminator ? null : $terminator + 1;
        $state->inCharClass = true;
        $state->charClassStartPositions[] = $start;

        return new Token(TokenType::CharClassOpen(), '[', $start);
    }

    /**
     * A character of a POSIX item read whole, as a member: no escape, no
     * range, no quote in it is read.
     *
     * @param int $end where the item read whole ends
     */
    public static function itemText(LexerState $state, int $end): Token
    {
        $start = $state->position;
        $state->position += $state->characterLength($start);
        if ($state->position >= $end) {
            $state->classTextEnd = null;
        }

        return new Token(TokenType::Literal(), (string) substr($state->pattern, $start, $state->position - $start), $start);
    }

    /**
     * The "]" at $start, which the cursor stands past: a member while the
     * innermost class has none yet, else the end of that class.
     *
     * @param array<Token> $tokens the tokens read before it
     */
    public static function close(LexerState $state, int $start, array $tokens): Token
    {
        if (null !== self::prefix($state, $tokens)) {
            return new Token(TokenType::Literal(), ']', $start);
        }

        array_pop($state->charClassStartPositions);
        if ([] === $state->charClassStartPositions) {
            $state->inCharClass = false;
        }

        return new Token(TokenType::CharClassClose(), ']', $start);
    }

    /**
     * Whether the cursor is still at the start of the innermost class.
     *
     * PCRE skips "\E", an empty "\Q\E" and one "^", in any order, before it
     * reads the first member of a class, so a "]" that follows only those is
     * a member and does not close the class: "[\E]a]" holds "]" and "a".
     *
     * @param array<Token> $tokens the tokens read before the cursor
     *
     * @return bool|null null when a member has already been read; otherwise
     *                   whether the prefix holds the negating "^"
     */
    public static function prefix(LexerState $state, array $tokens): ?bool
    {
        // Unreachable: every caller is inside a class, which has an opening
        // position. It guards a call made outside one.
        if ([] === $state->charClassStartPositions) {
            return null;
        }

        $classStart = $state->charClassStartPositions[\count($state->charClassStartPositions) - 1];
        $negated = false;

        for ($index = \count($tokens) - 1; $index >= 0; --$index) {
            $token = $tokens[$index];

            if (TokenType::CharClassOpen() === $token->type && $classStart === $token->position) {
                return $negated;
            }

            if (TokenType::Negation() === $token->type) {
                $negated = true;

                continue;
            }

            // Quote mode is closed by the time a member is read here, so a
            // "\Q" in the prefix is the start of an empty "\Q\E": anything it
            // quoted would be a literal token, which ends the prefix.
            if (TokenType::QuoteModeEnd() !== $token->type && TokenType::QuoteModeStart() !== $token->type) {
                return null;
            }
        }

        // Unreachable: the opening token of the innermost class is always in
        // the list, so the loop returns before running out. It guards a token
        // list that lost it.
        return null;
    }

    /**
     * "(?[" to the "]" that closes it at its own level, and the ")" after it;
     * to the end of the pattern when none does. The parser judges the text.
     */
    public static function extendedClass(LexerState $state): Token
    {
        $start = $state->position;
        $state->position = BodyEnds::extendedClassEnd($state->text, $start);

        return new Token(TokenType::ExtendedClass(), (string) substr($state->pattern, $start, $state->position - $start), $start);
    }

    /**
     * Whether the class at $position is the "[" of "(?[", a Perl extended
     * class PCRE2 only reads from 10.45; before, PCRE2 refuses that "[",
     * whatever follows it. Only a group opener ending there does: in
     * "\(?[a" and "\c(?[a" the "(" is a character, and the class is plain.
     *
     * @param list<Token> $tokens
     */
    public static function opensExtendedClass(array $tokens, int $position): bool
    {
        foreach ($tokens as $token) {
            if ($token->end() === $position) {
                return TokenType::GroupModifierOpen() === $token->type;
            }
        }

        return false;
    }
}

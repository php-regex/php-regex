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

use PHPRegex\Parser\Syntax\BadEscape;
use PHPRegex\Parser\Syntax\CodePointReader;
use PHPRegex\Parser\Syntax\PosixItem;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * The ranges of a class as PCRE reads them while the class is still open:
 * whether a class escape would end one, which PCRE2 10.32 reads as its
 * letter under PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, and where the first
 * reversed one ends, which PCRE refuses before it misses the "]" of a class
 * that never closes.
 *
 * It reads the tokens of the class, the text they were read from, and
 * whether PCRE reads it as UTF-8 or as bytes, which decides the character a
 * multibyte bound names; it keeps nothing.
 *
 * @internal
 */
final class ClassRanges
{
    /**
     * Whether the escape at $position, in a class of PCRE2 10.32, ends a
     * range: a character, then a "-" that starts a range from it. A class
     * escape there is refused, and read as its letter under
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL.
     *
     * @param list<Token> $tokens     the tokens read before $position
     * @param int|null    $classStart where the innermost class open opens, null outside a class
     */
    public static function startsRangeEnd(PatternText $text, array $tokens, int $position, ?int $classStart): bool
    {
        // Only a class escape may be refused there; a character never is.
        if (!$text->syntax->classBadEscapeReadAsLetter || null === $classStart || !str_contains(BadEscape::CLASS_ESCAPE_LETTERS, $text->pattern[$position + 1] ?? '')) {
            return false;
        }

        $first = \count($tokens);
        while ($first > 0 && !(TokenType::CharClassOpen === $tokens[$first - 1]->type && $tokens[$first - 1]->position === $classStart)) {
            $first--;
        }

        $state = 'none';
        foreach (\array_slice($tokens, $first) as $token) {
            $state = match ($token->type) {
                TokenType::QuoteModeStart, TokenType::QuoteModeEnd, TokenType::Negation => $state,
                TokenType::Range => match ($state) {
                    'character' => 'range',
                    'range' => 'none',
                    default => 'character',
                },
                // Without UTF mode a multibyte end leaves its last byte, a
                // character a "-" may start a range from.
                TokenType::Literal, TokenType::LiteralEscaped => 'range' === $state && 1 === self::multibyteLength($text, $token) ? 'none' : 'character',
                TokenType::Unicode, TokenType::Octal, TokenType::OctalLegacy,
                TokenType::UnicodeNamed, TokenType::ControlChar => 'range' === $state ? 'none' : 'character',
                default => 'none',
            };
        }

        return 'range' === $state;
    }

    /**
     * The offset past the first reversed range of the class opened at
     * $classStart, "z-a", whose bounds each name one character: a literal,
     * quoted or not, or an escape such as "\x7A", "\172" or "\N{U+7A}". PCRE
     * skips the quotes on either side of the "-". Null when it has none.
     *
     * @param list<Token> $tokens
     */
    public static function reversedRangeEnd(PatternText $text, array $tokens, int $classStart): ?int
    {
        $members = [];
        $open = false;
        $quoted = false;
        foreach ($tokens as $token) {
            if (!$open) {
                $open = TokenType::CharClassOpen === $token->type && $token->position === $classStart;
            } elseif (TokenType::QuoteModeStart === $token->type || TokenType::QuoteModeEnd === $token->type) {
                $quoted = TokenType::QuoteModeStart === $token->type;
            } elseif (!$quoted && TokenType::Literal === $token->type && '[' === $token->value && null !== PosixItem::terminator($text->pattern, $token->position)) {
                // The "[" of a POSIX item, "[.a.]", names no character:
                // PCRE refuses the item, or a range it ends.
                $members[] = new Token(TokenType::PosixClass, '', $token->position, 1);
            } else {
                $members[] = $token;
            }
        }

        $count = \count($members);
        for ($i = 0; $i + 2 < $count; $i++) {
            if (TokenType::Range !== $members[$i + 1]->type) {
                continue;
            }

            $start = self::rangeBound($text, $members[$i], true);
            $end = self::rangeBound($text, $members[$i + 2], false);
            $endLength = self::multibyteLength($text, $members[$i + 2]);
            if (null !== $start && null !== $end && $start > $end) {
                // On the first byte of a multibyte end.
                return $members[$i + 2]->end() - $endLength + 1;
            }

            // Without UTF mode, a multibyte end may start the next range
            // from its last byte.
            $i += $endLength > 1 ? 1 : 2;
        }

        return null;
    }

    /**
     * The character a class member names as a bound of a range, null when it
     * names none or one this reading leaves alone. Without UTF mode PCRE
     * reads bytes: a multibyte literal starts a range at its last byte, and
     * ends one at its first. A "-" right after the one that makes the range
     * is its end.
     */
    private static function rangeBound(PatternText $text, Token $token, bool $start): ?int
    {
        // "\x" with no digit is NUL where PCRE takes it (up to 10.44). A
        // "\x{" PCRE2 refuses, which PHP 7.3 and 7.4 read as the letter x
        // then text, stays the x.
        if (TokenType::LiteralEscaped === $token->type && 2 === $token->end() - $token->position && '\\x' === substr($text->pattern, $token->position, 2)
            && '{' !== ($text->pattern[$token->end()] ?? '')) {
            return 0;
        }

        $utf = $text->utf;
        $value = $token->value;
        $codePoint = match ($token->type) {
            TokenType::Literal, TokenType::LiteralEscaped, TokenType::Range => match (true) {
                1 === \strlen($value) => \ord($value),
                $utf && 1 === mb_strlen($value, 'UTF-8') => mb_ord($value, 'UTF-8'),
                !$utf && \strlen($value) > 1 => \ord(substr($value, $start ? -1 : 0, 1)),
                default => null,
            },
            TokenType::Unicode => CodePointReader::fromHexEscape($value),
            TokenType::Octal => CodePointReader::fromOctalEscape($value),
            TokenType::OctalLegacy => CodePointReader::fromOctalEscape('\\'.$value),
            TokenType::UnicodeNamed => CodePointReader::fromNamedEscape('\\N{'.$value.'}'),
            TokenType::ControlChar => 1 === \strlen($value) && \ord($value) < 0x80 ? \ord(Ascii::upper($value)) ^ 0x40 : null,
            default => null,
        };

        return null === $codePoint || $codePoint < 0 || (!$utf && $codePoint > 0xFF) ? null : $codePoint;
    }

    /**
     * The bytes of a literal PCRE reads one at a time, without UTF mode: a
     * multibyte character ends a range on its first byte. One otherwise.
     */
    private static function multibyteLength(PatternText $text, Token $token): int
    {
        return !$text->utf && \in_array($token->type, [TokenType::Literal, TokenType::LiteralEscaped], true) ? max(1, \strlen($token->value)) : 1;
    }
}

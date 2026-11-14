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
 * multibyte bound names. Range-end queries retain their state for one
 * tokenizing, consuming only tokens appended since the previous query.
 *
 * @internal
 */
final class ClassRanges
{
    private int $tokensRead = 0;

    private string $rangeState = 'none';

    /**
     * Whether the escape at $position, in a class of PCRE2 10.32, ends a
     * range: a character, then a "-" that starts a range from it. A class
     * escape there is refused, and read as its letter under
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL.
     *
     * @param list<Token> $tokens     append-only tokens of one tokenizing, read before $position
     * @param int|null    $classStart where the innermost class open opens, null outside a class
     */
    public function startsRangeEnd(PatternText $text, array $tokens, int $position, ?int $classStart): bool
    {
        // Only a class escape may be refused there; a character never is.
        if (!$text->syntax->classBadEscapeReadAsLetter || null === $classStart || false === strpos(BadEscape::CLASS_ESCAPE_LETTERS, $text->pattern[$position + 1] ?? '')) {
            return false;
        }

        // The lexer only appends tokens. A class opener resets the state,
        // so a later class cannot inherit a range from an earlier one.
        $state = $this->rangeState;
        for ($count = \count($tokens); $this->tokensRead < $count; ++$this->tokensRead) {
            $token = $tokens[$this->tokensRead];
            switch (true) {
                case $token->type === TokenType::QuoteModeStart():
                case $token->type === TokenType::QuoteModeEnd():
                case $token->type === TokenType::Negation():
                    $state = $state;
                    break;
                case $token->type === TokenType::Range():
                    switch (true) {
                        case 'character' === $state:
                            $state = 'range';
                            break;
                        case 'range' === $state:
                            $state = 'none';
                            break;
                        default:
                            $state = 'character';
                            break;
                    }
                    break;
                case $token->type === TokenType::Literal():
                case $token->type === TokenType::LiteralEscaped():
                    $state = 'range' === $state && 1 === self::multibyteLength($text, $token) ? 'none' : 'character';
                    break;
                case $token->type === TokenType::Unicode():
                case $token->type === TokenType::Octal():
                case $token->type === TokenType::OctalLegacy():
                case $token->type === TokenType::UnicodeNamed():
                case $token->type === TokenType::ControlChar():
                    $state = 'range' === $state ? 'none' : 'character';
                    break;
                default:
                    $state = 'none';
                    break;
            }
        }

        $this->rangeState = $state;

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
                $open = TokenType::CharClassOpen() === $token->type && $token->position === $classStart;
            } elseif (TokenType::QuoteModeStart() === $token->type || TokenType::QuoteModeEnd() === $token->type) {
                $quoted = TokenType::QuoteModeStart() === $token->type;
            } elseif (!$quoted && TokenType::Literal() === $token->type && '[' === $token->value && null !== PosixItem::terminator($text->pattern, $token->position)) {
                // The "[" of a POSIX item, "[.a.]", names no character:
                // PCRE refuses the item, or a range it ends.
                $members[] = new Token(TokenType::PosixClass(), '', $token->position, 1);
            } else {
                $members[] = $token;
            }
        }

        $count = \count($members);
        for ($i = 0; $i + 2 < $count; ++$i) {
            if (TokenType::Range() !== $members[$i + 1]->type) {
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
        if (TokenType::LiteralEscaped() === $token->type && 2 === $token->end() - $token->position && '\\x' === (string) substr($text->pattern, $token->position, 2)
            && '{' !== ($text->pattern[$token->end()] ?? '')) {
            return 0;
        }

        $utf = $text->utf;
        $value = $token->value;
        switch (true) {
            case $token->type === TokenType::Literal():
            case $token->type === TokenType::LiteralEscaped():
            case $token->type === TokenType::Range():
                switch (true) {
                    case true === (1 === \strlen($value)):
                        $codePoint = \ord($value);
                        break;
                    case true === ($utf && 1 === mb_strlen($value, 'UTF-8')):
                        $codePoint = mb_ord($value, 'UTF-8');
                        break;
                    case true === (!$utf && \strlen($value) > 1):
                        $codePoint = \ord((string) substr($value, $start ? -1 : 0, 1));
                        break;
                    default:
                        $codePoint = null;
                        break;
                }
                break;
            case $token->type === TokenType::Unicode():
                $codePoint = CodePointReader::fromHexEscape($value);
                break;
            case $token->type === TokenType::Octal():
                $codePoint = CodePointReader::fromOctalEscape($value);
                break;
            case $token->type === TokenType::OctalLegacy():
                $codePoint = CodePointReader::fromOctalEscape('\\'.$value);
                break;
            case $token->type === TokenType::UnicodeNamed():
                $codePoint = CodePointReader::fromNamedEscape('\\N{'.$value.'}');
                break;
            case $token->type === TokenType::ControlChar():
                $codePoint = 1 === \strlen($value) && \ord($value) < 0x80 ? \ord(Ascii::upper($value)) ^ 0x40 : null;
                break;
            default:
                $codePoint = null;
                break;
        }

        return null === $codePoint || $codePoint < 0 || (!$utf && $codePoint > 0xFF) ? null : $codePoint;
    }

    /**
     * The bytes of a literal PCRE reads one at a time, without UTF mode: a
     * multibyte character ends a range on its first byte. One otherwise.
     */
    private static function multibyteLength(PatternText $text, Token $token): int
    {
        return !$text->utf && \in_array($token->type, [TokenType::Literal(), TokenType::LiteralEscaped()], true) ? max(1, \strlen($token->value)) : 1;
    }
}

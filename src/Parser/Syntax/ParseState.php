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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Where the parser stands in the pattern, and what every part that reads
 * the pattern shares.
 *
 * TokenParser reads the pattern; ClassParser reads a character class and
 * ConditionParser the condition of a conditional for it. The three walk the
 * same tokens over the same text, for the same target, in the same UTF and
 * bad-escape modes. A "\Q...\E" quote is the one thing they change as they
 * read: one opened inside a class can close after it, so whether the tokens
 * are quoted is kept here, where each part reads and sets it. The reading
 * steps they all take, an atom, a comment, the quotes PCRE skips, are here
 * too, so each part holds only the grammar it reads.
 *
 * @internal
 */
final class ParseState
{
    /**
     * Token types that describe a character the same way wherever they
     * appear: "\d" is the same inside a class and outside it.
     */
    private const ATOM_TYPES = [
        TokenType::Literal,
        TokenType::LiteralEscaped,
        TokenType::CharType,
        TokenType::UnicodeProp,
        TokenType::ControlChar,
        TokenType::Unicode,
        TokenType::UnicodeNamed,
        TokenType::Octal,
        TokenType::OctalLegacy,
    ];

    /**
     * Whether the tokens read now stand between "\Q" and "\E".
     */
    public bool $inQuoteMode = false;

    /**
     * @param bool $unicodeMode        whether the pattern is read as UTF-8:
     *                                 "u", or "(*UTF)" at the start
     * @param bool $badEscapeIsLiteral whether the pattern is compiled with
     *                                 PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as
     *                                 PHP 7.3 and 7.4 compile it without "X"
     */
    public function __construct(
        public readonly TokenStream $stream,
        public readonly string $pattern,
        public readonly PcreTarget $target,
        public readonly bool $unicodeMode,
        public readonly bool $badEscapeIsLiteral,
    ) {}

    /**
     * Reads the next token if it describes a character the same way
     * wherever it is written, and turns it into a node. What differs between
     * a class and the rest of the pattern, a dot, an anchor, a reference, a
     * range, is left to the part that reads there.
     */
    public function matchAtom(int $startPosition): ?NodeInterface
    {
        $type = $this->stream->currentType();
        if (!\in_array($type, self::ATOM_TYPES, true)) {
            return null;
        }

        $this->stream->advance();

        return $this->atom($this->stream->previous(), $type, $startPosition);
    }

    /**
     * The node of a token matchAtom() reads.
     */
    public function atom(Token $token, TokenType $type, int $startPosition): NodeInterface
    {
        return match ($type) {
            // Between "\Q" and "\E" a backslash is text: "\Q\x\E" is "\x".
            TokenType::Literal => new LiteralNode($token->value, $startPosition, $token->end()),
            // "\x" with no digit is NUL where PCRE takes it (up to 10.44).
            TokenType::LiteralEscaped => '\\x' === substr($this->pattern, $token->position, 2) && 2 === $token->end() - $token->position
                && '{' !== ($this->pattern[$token->end()] ?? '')
                ? new CharLiteralNode('\\x', 0, CharLiteralType::Unicode, $startPosition, $token->end())
                : new LiteralNode($token->value, $startPosition, $token->end()),
            TokenType::CharType => new CharTypeNode($token->value, $startPosition, $token->end()),
            TokenType::ControlChar => new ControlCharNode(
                $token->value,
                CodePointReader::fromControlChar($token->value),
                $startPosition,
                $token->end(),
            ),
            TokenType::UnicodeProp => new UnicodePropNode(
                $token->value,
                str_starts_with($token->value, '{'),
                $startPosition,
                $token->end(),
                $this->isNegatedPropertySyntax($startPosition),
            ),
            default => $this->charLiteral($token, $type, $startPosition),
        };
    }

    /**
     * Reads "(?#...)", its opener read: the text up to the ")" that closes
     * it, as the pattern spells it.
     */
    public function comment(): CommentNode
    {
        $startPosition = $this->stream->previous()->position;

        $comment = '';
        while (!$this->stream->isAtEnd() && !$this->stream->check(TokenType::GroupClose)) {
            $comment .= $this->sourceTextOf($this->stream->current());
            $this->stream->advance();
        }

        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close comment', ErrorCode::CommentUnclosed);

        return new CommentNode($comment, $startPosition, $endToken->position + 1);
    }

    /**
     * Skips the "\E" and empty "\Q\E" that PCRE reads as nothing.
     */
    public function skipEmptyQuotes(): void
    {
        while (true) {
            if ($this->stream->match(TokenType::QuoteModeEnd)) {
                $this->inQuoteMode = false;

                continue;
            }

            if ($this->stream->check(TokenType::QuoteModeStart)
                && TokenType::QuoteModeEnd === $this->stream->peek()->type) {
                $this->stream->advance();
                $this->stream->advance();

                continue;
            }

            return;
        }
    }

    /**
     * Consumes literal tokens while the predicate takes their value, and
     * gives their values joined.
     *
     * @param callable(string): bool $predicate
     */
    public function consumeWhile(callable $predicate): string
    {
        $value = '';

        while (
            !$this->stream->isAtEnd()
            && $this->stream->check(TokenType::Literal)
            && $predicate($this->stream->current()->value)
        ) {
            $value .= $this->stream->current()->value;
            $this->stream->advance();
        }

        return $value;
    }

    /**
     * Whether the current token is a literal digit.
     */
    public function isLiteralDigitToken(): bool
    {
        return $this->stream->check(TokenType::Literal) && Ascii::isDigit($this->stream->current()->value);
    }

    /**
     * Whether the current token is a literal ASCII letter.
     */
    public function isLiteralAlphaToken(): bool
    {
        return $this->stream->check(TokenType::Literal) && Ascii::isAlpha($this->stream->current()->value);
    }

    /**
     * The text a token was cut from, exactly as the pattern spelled it.
     *
     * The lexer rewrites what it reads — "\d" comes back as "d",
     * "\P{Greek}" as "{^Greek}" — so the value cannot be turned back into
     * source. The token knows its span instead.
     */
    public function sourceTextOf(Token $token): string
    {
        return substr($this->pattern, $token->position, $token->sourceLength);
    }

    /**
     * A syntax error at $position, with the pattern around it.
     */
    public function error(string $message, ErrorCode $code, int $position): ParserException
    {
        return SyntaxErrorException::withContext($message, $code, $position, $this->pattern);
    }

    /**
     * Whether a unicode property was written "\P{...}" rather than "\p{...}".
     *
     * The lexer folds the negation into the value — "\P{Greek}" and
     * "\p{^Greek}" arrive as the same token — so which of the two was written
     * can only be read from the pattern.
     */
    private function isNegatedPropertySyntax(int $startPosition): bool
    {
        return 'P' === ($this->pattern[$startPosition + 1] ?? 'p');
    }

    private function charLiteral(Token $token, TokenType $type, int $startPosition): CharLiteralNode
    {
        [$representation, $charType] = match ($type) {
            TokenType::Unicode => [$token->value, CharLiteralType::Unicode],
            TokenType::UnicodeNamed => ['\\N{'.$token->value.'}', CharLiteralType::UnicodeNamed],
            TokenType::Octal => [$token->value, CharLiteralType::Octal],
            TokenType::OctalLegacy => ['\\'.$token->value, CharLiteralType::OctalLegacy],
            default => throw new \LogicException('Unsupported character literal token type.'),
        };

        return new CharLiteralNode(
            $representation,
            CodePointReader::fromLiteral($representation, $charType),
            $charType,
            $startPosition,
            $token->end(),
        );
    }
}

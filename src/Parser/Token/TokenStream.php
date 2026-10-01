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

namespace PHPRegex\Parser\Token;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SyntaxErrorException;

/**
 * Token stream with direct indexing and caching.
 *
 * This implementation eliminates O(n) array operations and provides constant-time
 * access to tokens through direct array indexing with minimal memory overhead.
 */
final class TokenStream
{
    private int $position = 0;

    private int $maxPosition = 0;

    /**
     * @param array<Token> $tokens
     */
    public function __construct(private readonly array $tokens, private readonly string $pattern)
    {
        $this->maxPosition = \count($this->tokens) - 1;
    }

    /**
     * @throws \LogicException
     */
    public function current(): Token
    {
        if ($this->position > $this->maxPosition) {
            throw new \LogicException('Token stream is exhausted');
        }

        return $this->tokens[$this->position];
    }

    /**
     * @throws \LogicException
     */
    public function next(): void
    {
        if ($this->position > $this->maxPosition) {
            throw new \LogicException('Token stream is exhausted');
        }

        $this->position++;
    }

    /**
     * @throws \LogicException
     */
    public function rewind(int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $newPosition = $this->position - $count;
        if ($newPosition < 0) {
            throw new \LogicException(\sprintf(
                'Cannot rewind %d tokens, would go before start of stream',
                $count,
            ));
        }

        $this->position = $newPosition;
    }

    public function setPosition(int $position): void
    {
        if ($position < 0 || $position > $this->maxPosition + 1) {
            throw new \LogicException(\sprintf(
                'Position %d is out of bounds [0, %d]',
                $position,
                $this->maxPosition + 1,
            ));
        }

        $this->position = $position;
    }

    public function peek(int $offset = 1): Token
    {
        $targetPos = $this->position + $offset;

        if ($targetPos < 0) {
            return new Token(TokenType::Eof, '', 0);
        }

        if ($targetPos > $this->maxPosition) {
            return new Token(TokenType::Eof, '', $targetPos);
        }

        return $this->tokens[$targetPos];
    }

    public function hasMore(): bool
    {
        return $this->position <= $this->maxPosition;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * @return array<Token>
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    /**
     * Returns true if current token is EOF
     */
    /**
     * Whether the token under the cursor has this type.
     *
     * Past the end there is only T_EOF, which is what a parser asking for it
     * expects to find.
     */
    public function check(TokenType $type): bool
    {
        if ($this->isAtEnd()) {
            return TokenType::Eof === $type;
        }

        return $this->current()->type === $type;
    }

    /**
     * Whether the token under the cursor is a literal with this text.
     */
    public function checkLiteral(string $value): bool
    {
        if ($this->isAtEnd()) {
            return false;
        }

        $token = $this->current();

        return TokenType::Literal === $token->type && $token->value === $value;
    }

    /**
     * Step over the token under the cursor if it has this type.
     */
    public function match(TokenType $type): bool
    {
        if (!$this->check($type)) {
            return false;
        }

        $this->advance();

        return true;
    }

    /**
     * Step over the token under the cursor if it is this literal.
     */
    public function matchLiteral(string $value): bool
    {
        if (!$this->checkLiteral($value)) {
            return false;
        }

        $this->advance();

        return true;
    }

    /**
     * Step over the token under the cursor, unless the stream is exhausted.
     */
    public function advance(): void
    {
        if (!$this->isAtEnd()) {
            $this->next();
        }
    }

    /**
     * The token the cursor has just stepped over.
     */
    public function previous(): Token
    {
        if (0 === $this->position) {
            return new Token(TokenType::Eof, '', 0);
        }

        return $this->tokens[$this->position - 1];
    }

    /**
     * Step over a token of this type, or say what was found instead.
     *
     * @param ErrorCode $code what is wrong with the pattern when the token is missing
     *
     * @throws SyntaxErrorException
     */
    public function consume(TokenType $type, string $error, ErrorCode $code): Token
    {
        if ($this->check($type)) {
            $token = $this->current();
            $this->advance();

            return $token;
        }

        throw $this->unexpected($error, '(found '.$this->current()->type->value.')', $code);
    }

    /**
     * Step over this literal, or say what was found instead.
     *
     * @param ErrorCode $code what is wrong with the pattern when the literal is missing
     *
     * @throws SyntaxErrorException
     */
    public function consumeLiteral(string $value, string $error, ErrorCode $code): Token
    {
        if ($this->checkLiteral($value)) {
            $token = $this->current();
            $this->advance();

            return $token;
        }

        throw $this->unexpected(
            $error,
            '(found '.$this->current()->type->value.' with value '.$this->current()->value.')',
            $code,
        );
    }

    public function isAtEnd(): bool
    {
        return $this->position > $this->maxPosition
               || TokenType::Eof === $this->tokens[$this->position]->type;
    }

    private function unexpected(string $error, string $found, ErrorCode $code): SyntaxErrorException
    {
        $at = $this->isAtEnd() ? 'end of input' : 'position '.$this->current()->position;

        return SyntaxErrorException::withContext(
            $error.' at '.$at.' '.$found,
            $code,
            $this->current()->position,
            $this->pattern,
        );
    }
}

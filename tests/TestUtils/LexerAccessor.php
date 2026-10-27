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

namespace PHPRegex\Tests\TestUtils;

use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Lexing\LexerState;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * Accessor class to expose and manipulate private methods of the Lexer and
 * the state it reads with, for unit testing.
 */
final class LexerAccessor
{
    /**
     * @var \ReflectionClass<Lexer>
     */
    private readonly \ReflectionClass $reflection;

    public function __construct(private readonly Lexer $lexer)
    {
        $this->reflection = new \ReflectionClass($this->lexer);
    }

    /**
     * Calls a private method on the Lexer instance.
     *
     * @param array<mixed> $args
     */
    public function callPrivateMethod(string $methodName, array $args = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);

        return $method->invoke($this->lexer, ...$args);
    }

    /**
     * The state the Lexer reads with, the one the readers of its modes are
     * handed.
     */
    public function state(): LexerState
    {
        $state = $this->reflection->getProperty('state')->getValue($this->lexer);
        \assert($state instanceof LexerState);

        return $state;
    }

    /**
     * Sets the position of the state.
     */
    public function setPosition(int $position): void
    {
        $this->state()->position = $position;
    }

    /**
     * Gets the position of the state.
     */
    public function getPosition(): int
    {
        return $this->state()->position;
    }

    /**
     * Sets whether the state is in quote mode.
     */
    public function setInQuoteMode(bool $inQuoteMode): void
    {
        $this->state()->inQuoteMode = $inQuoteMode;
    }

    /**
     * Gets whether the state is in quote mode.
     */
    public function getInQuoteMode(): bool
    {
        return $this->state()->inQuoteMode;
    }

    /**
     * Sets whether the state is in comment mode.
     */
    public function setInCommentMode(bool $inCommentMode): void
    {
        $this->state()->inCommentMode = $inCommentMode;
    }

    /**
     * Gets whether the state is in comment mode.
     */
    public function getInCommentMode(): bool
    {
        return $this->state()->inCommentMode;
    }

    /**
     * Sets the text the state reads.
     */
    public function setPattern(string $pattern): void
    {
        $this->state()->pattern = $pattern;
    }

    /**
     * Sets the length of the text the state reads.
     */
    public function setLength(int $length): void
    {
        $this->state()->length = $length;
    }

    /**
     * Creates a Token instance (useful for mocking complex token structures).
     */
    public function createToken(TokenType $type, string $value, int $position): Token
    {
        return new Token($type, $value, $position);
    }
}

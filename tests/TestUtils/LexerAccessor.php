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
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * Accessor class to expose and manipulate private methods/properties of the Lexer for unit testing.
 */
final readonly class LexerAccessor
{
    /**
     * @var \ReflectionClass<Lexer>
     */
    private \ReflectionClass $reflection;

    public function __construct(private Lexer $lexer)
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
     * Sets the private position property.
     */
    public function setPosition(int $position): void
    {
        $property = $this->reflection->getProperty('position');
        $property->setValue($this->lexer, $position);
    }

    /**
     * Gets the private position property.
     */
    public function getPosition(): int
    {
        $property = $this->reflection->getProperty('position');
        $value = $property->getValue($this->lexer);
        \assert(\is_int($value));

        return $value;
    }

    /**
     * Sets the private inQuoteMode property.
     */
    public function setInQuoteMode(bool $inQuoteMode): void
    {
        $property = $this->reflection->getProperty('inQuoteMode');
        $property->setValue($this->lexer, $inQuoteMode);
    }

    /**
     * Gets the private inQuoteMode property.
     */
    public function getInQuoteMode(): bool
    {
        $property = $this->reflection->getProperty('inQuoteMode');

        return (bool) $property->getValue($this->lexer);
    }

    /**
     * Sets the private inCommentMode property.
     */
    public function setInCommentMode(bool $inCommentMode): void
    {
        $property = $this->reflection->getProperty('inCommentMode');
        $property->setValue($this->lexer, $inCommentMode);
    }

    /**
     * Sets the private pattern property.
     */
    public function setPattern(string $pattern): void
    {
        $property = $this->reflection->getProperty('pattern');
        $property->setValue($this->lexer, $pattern);
    }

    /**
     * Sets the private length property.
     */
    public function setLength(int $length): void
    {
        $property = $this->reflection->getProperty('length');
        $property->setValue($this->lexer, $length);
    }

    /**
     * Creates a Token instance (useful for mocking complex token structures).
     */
    public function createToken(TokenType $type, string $value, int $position): Token
    {
        return new Token($type, $value, $position);
    }
}

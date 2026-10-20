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

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\GroupNameReader;
use PHPRegex\Parser\Syntax\ParseState;
use PHPRegex\Parser\Syntax\TokenParser;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reaches the private parts of the Parser, for the tests that have to.
 *
 * Navigating the tokens is no longer one of them: the cursor lives on
 * TokenStream, which is public, and stream() hands it over.
 */
final class ParserAccessor
{
    /**
     * @var \ReflectionClass<TokenParser>
     */
    private readonly \ReflectionClass $reflection;

    public function __construct(private readonly TokenParser $parser)
    {
        $this->reflection = new \ReflectionClass($this->parser);
    }

    /**
     * Calls a private method on the Parser instance.
     *
     * @param array<mixed> $args
     */
    public function callPrivateMethod(string $methodName, array $args = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);

        return $method->invoke($this->parser, ...$args);
    }

    /**
     * Sets the internal TokenStream by creating one from an array of tokens.
     *
     * @param array<string|Token> $tokens
     */
    public function setTokens(array $tokens): void
    {
        $processedTokens = [];
        $pos = 0;
        foreach ($tokens as $token) {
            if ($token instanceof Token) {
                $processedTokens[] = $token;
                $pos += \strlen($token->value);
            } else {
                $processedTokens[] = $this->createToken(TokenType::Literal, $token, $pos);
                $pos += \strlen($token);
            }
        }
        $processedTokens[] = $this->createToken(TokenType::Eof, '', $pos);

        $stream = new TokenStream($processedTokens, '');

        $property = $this->reflection->getProperty('stream');
        $property->setValue($this->parser, $stream);

        // parse() builds these alongside the stream; a test that installs a
        // stream by hand has to install them too, or the parser is half set up.
        $groupNames = $this->reflection->getProperty('groupNames');
        $groupNames->setValue($this->parser, new GroupNameReader($stream));
        $this->installState();
    }

    /**
     * Calls a private method of the reader of classes the parser holds,
     * made on the reading state installed with the tokens.
     *
     * @param array<mixed> $args
     */
    public function callClassMethod(string $methodName, array $args = []): mixed
    {
        $classes = $this->reflection->getMethod('classes')->invoke($this->parser);
        \assert(\is_object($classes));

        return (new \ReflectionMethod($classes, $methodName))->invoke($classes, ...$args);
    }

    /**
     * Calls a private method of the reading state installed with the tokens.
     *
     * @param array<mixed> $args
     */
    public function callStateMethod(string $methodName, array $args = []): mixed
    {
        $state = $this->reflection->getProperty('state')->getValue($this->parser);
        \assert($state instanceof ParseState);

        return (new \ReflectionMethod($state, $methodName))->invoke($state, ...$args);
    }

    /**
     * Sets the current position of the parser in the token stream.
     */
    /**
     * Sets the pattern text the parser reports offsets against.
     */
    public function setPattern(string $pattern): void
    {
        $this->reflection->getProperty('pattern')->setValue($this->parser, $pattern);
        $this->installState();
    }

    public function setPosition(int $position): void
    {
        $property = $this->reflection->getProperty('stream');
        $stream = $property->getValue($this->parser);

        if ($stream instanceof TokenStream) {
            $stream->setPosition($position);
        }
    }

    /**
     * Gets the current position of the parser in the token stream.
     */
    public function getPosition(): int
    {
        $property = $this->reflection->getProperty('stream');
        $stream = $property->getValue($this->parser);

        if ($stream instanceof TokenStream) {
            return $stream->getPosition();
        }

        return 0;
    }

    /**
     * Utility method to create a Token instance.
     */
    public function createToken(TokenType $type, string $value, int $position): Token
    {
        return new Token($type, $value, $position);
    }

    /**
     * The stream the parser reads from, which is where the cursor lives.
     */
    public function stream(): TokenStream
    {
        $property = $this->reflection->getProperty('stream');
        $stream = $property->getValue($this->parser);
        \assert($stream instanceof TokenStream);

        return $stream;
    }

    public function advance(): void
    {
        $this->stream()->advance();
    }

    public function current(): Token
    {
        return $this->stream()->current();
    }

    /**
     * The reading state the parser shares with its readers, as parse() makes it,
     * over the installed stream and pattern; the readers made on the last
     * one are dropped.
     */
    private function installState(): void
    {
        $stream = $this->reflection->getProperty('stream')->getValue($this->parser);
        $target = $this->reflection->getProperty('target')->getValue($this->parser);
        \assert($stream instanceof TokenStream && $target instanceof PcreTarget);
        $pattern = $this->reflection->getProperty('pattern')->getValue($this->parser);

        $this->reflection->getProperty('state')->setValue($this->parser, new ParseState($stream, \is_string($pattern) ? $pattern : '', $target, false, false));
        $this->reflection->getProperty('classes')->setValue($this->parser, null);
    }
}

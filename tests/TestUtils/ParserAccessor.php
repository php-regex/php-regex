<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\TestUtils;

use PhpRegex\Parser\Internal\GroupNameReader;
use PhpRegex\Parser\Syntax\TokenParser;
use PhpRegex\Parser\Token\Token;
use PhpRegex\Parser\Token\TokenStream;
use PhpRegex\Parser\Token\TokenType;

/**
 * Reaches the private parts of the Parser, for the tests that have to.
 *
 * Navigating the tokens is no longer one of them: the cursor lives on
 * TokenStream, which is public, and stream() hands it over.
 */
final readonly class ParserAccessor
{
    /**
     * @var \ReflectionClass<\PhpRegex\Parser\Syntax\TokenParser>
     */
    private \ReflectionClass $reflection;

    public function __construct(private TokenParser $parser)
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
     * @param array<string|\PhpRegex\Parser\Token\Token> $tokens
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
                $processedTokens[] = $this->createToken(TokenType::T_LITERAL, $token, $pos);
                $pos += \strlen($token);
            }
        }
        $processedTokens[] = $this->createToken(TokenType::T_EOF, '', $pos);

        $stream = new TokenStream($processedTokens, '');

        $property = $this->reflection->getProperty('stream');
        $property->setValue($this->parser, $stream);

        // parse() builds this alongside the stream; a test that installs a
        // stream by hand has to install it too, or the parser is half set up.
        $groupNames = $this->reflection->getProperty('groupNames');
        $groupNames->setValue($this->parser, new GroupNameReader($stream));
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
}

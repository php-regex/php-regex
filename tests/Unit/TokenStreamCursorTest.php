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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Token\Token;
use PhpRegex\Parser\Token\TokenStream;
use PhpRegex\Parser\Token\TokenType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Walking a token stream: what the cursor answers, and what it refuses.
 */
final class TokenStreamCursorTest extends TestCase
{
    #[Test]
    public function test_check_looks_at_the_token_without_stepping_over_it(): void
    {
        $stream = $this->streamOf('a', 'b');

        $this->assertTrue($stream->check(TokenType::Literal));
        $this->assertTrue($stream->checkLiteral('a'));
        $this->assertFalse($stream->checkLiteral('b'));
        $this->assertSame(0, $stream->getPosition());
    }

    #[Test]
    public function test_match_steps_over_the_token_it_recognises(): void
    {
        $stream = $this->streamOf('a', 'b');

        $this->assertTrue($stream->match(TokenType::Literal));
        $this->assertSame(1, $stream->getPosition());

        $this->assertFalse($stream->matchLiteral('a'));
        $this->assertSame(1, $stream->getPosition());

        $this->assertTrue($stream->matchLiteral('b'));
        $this->assertSame(2, $stream->getPosition());
    }

    #[Test]
    public function test_the_end_of_the_stream_holds_only_eof(): void
    {
        $stream = $this->streamOf();

        $this->assertTrue($stream->isAtEnd());
        $this->assertTrue($stream->check(TokenType::Eof));
        $this->assertFalse($stream->check(TokenType::Literal));
        $this->assertFalse($stream->checkLiteral('a'));

        // Stepping past the end is a no-op rather than an error.
        $stream->advance();
        $this->assertSame(0, $stream->getPosition());
    }

    #[Test]
    public function test_previous_gives_the_token_just_stepped_over(): void
    {
        $stream = $this->streamOf('a', 'b');

        $this->assertSame(TokenType::Eof, $stream->previous()->type);

        $stream->advance();
        $this->assertSame('a', $stream->previous()->value);
    }

    #[Test]
    public function test_consume_returns_the_token_it_stepped_over(): void
    {
        $stream = $this->streamOf('a');

        $token = $stream->consume(TokenType::Literal, 'Expected a literal', ErrorCode::TokenUnexpected);

        $this->assertSame('a', $token->value);
        $this->assertTrue($stream->isAtEnd());
    }

    #[Test]
    public function test_consume_says_what_it_found_instead(): void
    {
        $stream = $this->streamOf('a');

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected a dot at position 0 (found literal)');

        $stream->consume(TokenType::Dot, 'Expected a dot', ErrorCode::TokenUnexpected);
    }

    #[Test]
    public function test_consume_says_when_there_is_nothing_left(): void
    {
        $stream = $this->streamOf();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected something at end of input (found eof)');

        $stream->consume(TokenType::Literal, 'Expected something', ErrorCode::TokenUnexpected);
    }

    #[Test]
    public function test_consume_literal_names_the_value_it_found(): void
    {
        $stream = $this->streamOf('b');

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected error at position 0 (found literal with value b)');

        $stream->consumeLiteral('a', 'Expected error', ErrorCode::GroupNameUnterminated);
    }

    private function streamOf(string ...$literals): TokenStream
    {
        $tokens = [];
        $position = 0;

        foreach ($literals as $literal) {
            $tokens[] = new Token(TokenType::Literal, $literal, $position);
            $position += \strlen($literal);
        }

        $tokens[] = new Token(TokenType::Eof, '', $position);

        return new TokenStream($tokens, implode('', $literals));
    }
}

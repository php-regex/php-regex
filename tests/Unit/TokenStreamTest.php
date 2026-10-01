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

use PhpRegex\Parser\Token\Token;
use PhpRegex\Parser\Token\TokenStream;
use PhpRegex\Parser\Token\TokenType;
use PHPUnit\Framework\TestCase;

final class TokenStreamTest extends TestCase
{
    public function test_iteration_and_positions(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Literal, 'b', 1),
            new Token(TokenType::Eof, '', 2),
        ];

        $stream = new TokenStream($tokens, 'ab');

        $this->assertSame(0, $stream->getPosition());
        $this->assertSame('a', $stream->current()->value);

        $stream->next();
        $this->assertSame(1, $stream->getPosition());
        $this->assertSame('b', $stream->current()->value);

        $peek = $stream->peek();
        $this->assertSame(TokenType::Eof, $peek->type);
        $this->assertSame(2, $peek->position);
    }

    public function test_peek_and_rewind(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'x', 0),
            new Token(TokenType::Literal, 'y', 1),
            new Token(TokenType::Eof, '', 2),
        ];

        $stream = new TokenStream($tokens, 'xy');
        $stream->next(); // consume x

        $peekBack = $stream->peek(-1);
        $this->assertSame('x', $peekBack->value);

        $stream->next(); // consume y
        $this->assertSame(TokenType::Eof, $stream->current()->type);

        $stream->rewind(2);
        $this->assertSame(0, $stream->getPosition());
        $this->assertSame('x', $stream->current()->value);
    }

    public function test_rewind_throws_on_invalid_count(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];

        $stream = new TokenStream($tokens, 'a');
        $stream->next();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot rewind 2 tokens, would go before start of stream');
        $stream->rewind(2);
    }

    public function test_next_and_current_throw_when_exhausted(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];
        $stream = new TokenStream($tokens, 'a');

        $stream->next(); // move to EOF
        $stream->next(); // consume EOF, buffer exhausted

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Token stream is exhausted');
        $stream->next();
    }

    public function test_set_position_forward_and_backward(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Literal, 'b', 1),
            new Token(TokenType::Literal, 'c', 2),
            new Token(TokenType::Eof, '', 3),
        ];

        $stream = new TokenStream($tokens, 'abc');
        $stream->setPosition(2);
        $this->assertSame('c', $stream->current()->value);

        $stream->setPosition(1);
        $this->assertSame('b', $stream->current()->value);
    }

    public function test_peek_out_of_bounds_returns_eof(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];
        $stream = new TokenStream($tokens, 'a');

        $peekFar = $stream->peek(5);
        $this->assertSame(TokenType::Eof, $peekFar->type);
        $this->assertSame(5, $peekFar->position);

        $peekBack = $stream->peek(-5);
        $this->assertSame(TokenType::Eof, $peekBack->type);
    }

    public function test_getters_expose_pattern_and_tokens(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];
        $stream = new TokenStream($tokens, 'a');

        $this->assertSame('a', $stream->getPattern());
        $this->assertCount(2, $stream->getTokens());
        $this->assertTrue($stream->hasMore());
    }

    public function test_set_position_throws_on_out_of_bounds(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];
        $stream = new TokenStream($tokens, 'a');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Position -1 is out of bounds [0, 2]');
        $stream->setPosition(-1);
    }

    public function test_set_position_throws_on_too_high(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];
        $stream = new TokenStream($tokens, 'a');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Position 3 is out of bounds [0, 2]');
        $stream->setPosition(3);
    }

    public function test_is_at_end(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];
        $stream = new TokenStream($tokens, 'a');

        $this->assertFalse($stream->isAtEnd());

        $stream->next();
        $this->assertTrue($stream->isAtEnd());

        $stream->next();
        $this->assertTrue($stream->isAtEnd());
    }

    public function test_rewind_zero_does_nothing(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];

        $stream = new TokenStream($tokens, 'a');
        $stream->next();
        $stream->rewind(0);

        $this->assertSame(1, $stream->getPosition());
    }

    public function test_current_throws_when_exhausted(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];

        $stream = new TokenStream($tokens, 'a');
        $stream->next(); // consume a
        $stream->next(); // consume EOF, buffer exhausted

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Token stream is exhausted');
        $stream->current();
    }
}

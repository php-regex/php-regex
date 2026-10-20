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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\TestCase;

final class TokenStreamTest extends TestCase
{
    use PhpUnitForwardCompat;

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

        $stream->advance();
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
        $stream->advance(); // step over x

        $peekBack = $stream->peek(-1);
        $this->assertSame('x', $peekBack->value);

        $stream->advance(); // step over y
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
        $stream->advance();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Cannot rewind 2 tokens, would go before start of stream');
        $stream->rewind(2);
    }

    public function test_advance_stops_on_the_end_of_the_pattern(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];
        $stream = new TokenStream($tokens, 'a');

        $stream->advance(); // move to EOF
        $stream->advance(); // the end-of-pattern token is never stepped over

        $this->assertSame(1, $stream->getPosition());
        $this->assertSame(TokenType::Eof, $stream->current()->type);
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
        $this->assertFalse($stream->isAtEnd());
    }

    public function test_set_position_throws_on_out_of_bounds(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];
        $stream = new TokenStream($tokens, 'a');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Position -1 is out of bounds [0, 2]');
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
        $this->expectExceptionMessageIsOrContains('Position 3 is out of bounds [0, 2]');
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

        $stream->advance();
        $this->assertTrue($stream->isAtEnd());

        $stream->advance();
        $this->assertTrue($stream->isAtEnd());
    }

    public function test_rewind_zero_does_nothing(): void
    {
        $tokens = [
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ];

        $stream = new TokenStream($tokens, 'a');
        $stream->advance();
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
        $stream->setPosition(2); // past the end-of-pattern token

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Token stream is exhausted');
        $stream->current();
    }
}

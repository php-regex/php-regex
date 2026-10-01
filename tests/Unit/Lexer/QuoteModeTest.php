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

namespace PHPRegex\Tests\Unit\Lexer;

use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Token\TokenType;
use PHPUnit\Framework\TestCase;

final class QuoteModeTest extends TestCase
{
    public function test_quote_mode_with_end_delimiter(): void
    {
        // \Q ... \E
        $tokens = (new Lexer())->tokenize('\Q*+?\E')->getTokens();

        // Now emits T_QUOTE_MODE_START, T_LITERAL, T_QUOTE_MODE_END, T_EOF
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::QuoteModeStart, $tokens[0]->type);
        $this->assertSame('\Q', $tokens[0]->value);
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame('*+?', $tokens[1]->value);
        $this->assertSame(TokenType::QuoteModeEnd, $tokens[2]->type);
        $this->assertSame('\E', $tokens[2]->value);
    }

    public function test_quote_mode_until_end_of_string(): void
    {
        // \Q ... (no \E)
        $tokens = (new Lexer())->tokenize('\Q*+?')->getTokens();

        // Now emits T_QUOTE_MODE_START, T_LITERAL, T_EOF (no T_QUOTE_MODE_END since no \E)
        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::QuoteModeStart, $tokens[0]->type);
        $this->assertSame('\Q', $tokens[0]->value);
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame('*+?', $tokens[1]->value);
    }

    public function test_empty_quote_mode(): void
    {
        // \Q\E
        $tokens = (new Lexer())->tokenize('a\Q\Eb')->getTokens();

        // Now emits: T_LITERAL('a'), T_QUOTE_MODE_START, T_QUOTE_MODE_END, T_LITERAL('b'), T_EOF
        $this->assertSame(TokenType::Literal, $tokens[0]->type);
        $this->assertSame('a', $tokens[0]->value);
        $this->assertSame(TokenType::QuoteModeStart, $tokens[1]->type);
        $this->assertSame('\Q', $tokens[1]->value);
        $this->assertSame(TokenType::QuoteModeEnd, $tokens[2]->type);
        $this->assertSame('\E', $tokens[2]->value);
        $this->assertSame(TokenType::Literal, $tokens[3]->type);
        $this->assertSame('b', $tokens[3]->value);
    }
}

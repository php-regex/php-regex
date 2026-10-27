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
use PHPRegex\Parser\Lexing\Tunnels;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\TestUtils\LexerAccessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LexerInternalStateTest extends TestCase
{
    /**
     * Covers the end of consumeCommentMode when the closing parenthesis is missing.
     * Normally tokenize() throws an exception afterwards, but we want to cover the internal "return null".
     */
    #[Test]
    public function test_lex_comment_mode_unterminated_returns_null(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);

        // Set up internal state directly without calling tokenize() to avoid post-processing exception
        $pattern = '(?# ... sans fin';
        $accessor->setPattern($pattern);
        $accessor->setLength(\strlen($pattern));
        $accessor->setPosition(3); // After (?#
        $accessor->setInCommentMode(true);

        // Direct call to the reader of the comment
        $result = Tunnels::comment($accessor->state());

        // It must first return a token with the comment text
        $this->assertInstanceOf(Token::class, $result);
        $this->assertSame(TokenType::Literal, $result->type);
        $this->assertSame(' ... sans fin', $result->value);
        // Position must be at the end of the text
        $this->assertSame(16, $accessor->getPosition());
    }

    /**
     * Covers the end of consumeQuoteMode when \E is missing.
     */
    #[Test]
    public function test_lex_quote_mode_unterminated_returns_null(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);

        $lexer->tokenize('\Q ... sans fin');
        $accessor->setPosition(2); // After \Q

        $result = Tunnels::quoted($accessor->state());

        // It must first return a token with the literal text
        $this->assertInstanceOf(Token::class, $result);
        $this->assertSame(TokenType::Literal, $result->type);
        $this->assertSame(' ... sans fin', $result->value);
        // Position must be at the end of the text
        $this->assertSame(15, $accessor->getPosition());
    }
}

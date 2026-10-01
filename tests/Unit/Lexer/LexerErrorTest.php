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

namespace PhpRegex\Tests\Unit\Lexer;

use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Lexer;
use PhpRegex\Parser\Token\Token;
use PhpRegex\Tests\TestUtils\LexerAccessor;
use PHPUnit\Framework\TestCase;

final class LexerErrorTest extends TestCase
{
    public function test_reset_tokenizes_invalid_utf8_in_byte_mode(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('valid');

        // \xFF is invalid UTF-8; without the /u modifier the lexer treats
        // the pattern as bytes, like PCRE.
        $tokens = $lexer->tokenize("\xFF")->getTokens();

        $this->assertCount(2, $tokens); // \xFF, EOF
    }

    public function test_tokenize_throws_on_unclosed_char_class(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unclosed character class "]" at end of input.');

        (new Lexer())->tokenize('[a-z');
    }

    public function test_lex_quote_mode_emits_literal_and_exits(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('start\Qabc\Eend');
        $accessor = new LexerAccessor($lexer);

        // 1. Lexer stops after \Q (position 7)
        $accessor->setPosition(7);
        $accessor->setInQuoteMode(true);

        // 2. Must find 'abc'
        $token = $accessor->callPrivateMethod('consumeQuoteMode');
        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('abc', $token->value);
        $this->assertSame(10, $accessor->getPosition()); // Position after 'abc' (7 + 3)
        $this->assertTrue($accessor->getInQuoteMode()); // Still in quote mode

        // 3. Must find \E token (now emitted instead of returning null)
        $token = $accessor->callPrivateMethod('consumeQuoteMode');
        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('\E', $token->value);
        $this->assertSame(12, $accessor->getPosition()); // Position after \E (10 + 2)
        $this->assertFalse($accessor->getInQuoteMode()); // Exit quote mode
    }

    public function test_lex_quote_mode_handles_trailing_text_without_e(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('\Qabc');
        $accessor = new LexerAccessor($lexer);

        // 1. Simulate entering quote mode (position 2)
        $accessor->setPosition(2);
        $accessor->setInQuoteMode(true);

        // 2. Must find 'abc'
        $token = $accessor->callPrivateMethod('consumeQuoteMode');
        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('abc', $token->value);

        // 3. Must reach end of string (position 5)
        $token = $accessor->callPrivateMethod('consumeQuoteMode');
        $this->assertNull($token);
        $this->assertSame(5, $accessor->getPosition());
        // Remains in $inQuoteMode = true at end of string (PCRE behavior)
        $this->assertTrue($accessor->getInQuoteMode());
    }
}

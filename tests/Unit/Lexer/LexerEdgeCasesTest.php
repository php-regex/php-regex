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
use PhpRegex\Parser\Token\TokenType;
use PhpRegex\Tests\TestUtils\LexerAccessor;
use PHPUnit\Framework\TestCase;

/**
 * The corners of the lexer: escapes it resolves, modes it enters and leaves,
 * and the input it refuses.
 */
final class LexerEdgeCasesTest extends TestCase
{
    public function test_match_at_position_with_invalid_regex_throws(): void
    {
        $lexer = new Lexer();
        $ref = new \ReflectionClass($lexer);

        $pattern = $ref->getProperty('pattern');
        $pattern->setValue($lexer, 'abc');
        $position = $ref->getProperty('position');
        $position->setValue($lexer, 0);

        $method = $ref->getMethod('matchAtPosition');

        $this->expectException(LexerException::class);
        set_error_handler(static fn (int $errno): bool => \E_WARNING === $errno);

        try {
            $method->invoke($lexer, '/[a-/');
        } finally {
            restore_error_handler();
        }
    }

    public function test_create_token_without_matching_map_throws(): void
    {
        $lexer = new Lexer();
        $ref = new \ReflectionClass($lexer);

        $pattern = $ref->getProperty('pattern');
        $pattern->setValue($lexer, 'abc');

        $method = $ref->getMethod('createToken');

        $this->expectException(LexerException::class);
        $method->invoke($lexer, ['T_UNKNOWN'], [], 'a', 0, []);
    }

    public function test_lexer_tokenizes_literals_and_eof(): void
    {
        $lexer = new Lexer();

        $tokenStream = $lexer->tokenize('test');
        $tokens = $tokenStream->getTokens();

        $this->assertSame('test', $tokenStream->getPattern());
        $this->assertSame(TokenType::T_LITERAL, $tokens[0]->type);
        $this->assertSame('t', $tokens[0]->value);
        $this->assertSame(TokenType::T_EOF, $tokens[\count($tokens) - 1]->type);
    }

    /**
     * Tests the "default" case of the switch in extractTokenValue.
     * This case is normally unreachable as the main Regex filters tokens,
     * but for 100% coverage we must force it via Reflection.
     */
    public function test_extract_token_value_fallback(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('');
        $accessor = new LexerAccessor($lexer);

        // Force a token that has no specific extraction logic
        // (e.g. T_LITERAL goes to the default)
        $val = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::T_LITERAL,
            'X',
            []
        ]);
        $this->assertSame('X', $val);

        // Test empty array fallback (null coalescing) in Lexer
        // Case: T_POSIX_CLASS without 'v_posix' key in matches
        $val = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::T_POSIX_CLASS,
            '[[:alnum:]]',
            [] // Empty array to simulate partial match
        ]);
        // The code does ($matches['v_posix'] ?? '') -> ''
        $this->assertSame('', $val);
    }

    /**
     * Tests Unicode normalization with malformed data
     * to reach the `??` fallbacks.
     */
    public function test_normalize_unicode_prop_fallbacks(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('');
        $accessor = new LexerAccessor($lexer);

        // Case where property is empty
        $val = $accessor->callPrivateMethod('normalizeUnicodeProp', [
            '\p{}',
        ]);
        $this->assertSame('', $val);
    }

    /**
     * Tests that a PCRE failure while reading a quoted run is reported.
     */
    public function test_consume_quote_mode_reports_a_pcre_failure(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);

        // Reading a malformed byte as UTF-8 is what tokenize() avoids by
        // choosing byte mode; forced into it, the lexer must say so rather
        // than skip to the end of the pattern and lose everything after the
        // quoted run.
        $invalid = "\xC3";
        $accessor->setPattern($invalid);
        $accessor->setLength(\strlen($invalid));
        $accessor->setPosition(0);
        $accessor->setInQuoteMode(true);

        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('PCRE Error while reading a quoted run');

        $accessor->callPrivateMethod('consumeQuoteMode');
    }

    public function test_consume_quote_mode_reaches_eof_without_closing(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);
        $pattern = '\Q';

        $accessor->setPattern($pattern);
        $accessor->setLength(\strlen($pattern));
        $accessor->setPosition(\strlen($pattern));
        $accessor->setInQuoteMode(true);

        $result = $accessor->callPrivateMethod('consumeQuoteMode');

        $this->assertNull($result);
        $this->assertSame(\strlen($pattern), $accessor->getPosition());
        $this->assertTrue($accessor->getInQuoteMode());
    }

    public function test_consume_quote_mode_returns_literal_and_exits_on_end_marker(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);
        $pattern = '\Qabc\E';

        $accessor->setPattern($pattern);
        $accessor->setLength(\strlen($pattern));
        $accessor->setPosition(2); // start after \Q
        $accessor->setInQuoteMode(true);

        $literalToken = $accessor->callPrivateMethod('consumeQuoteMode');
        $this->assertInstanceOf(Token::class, $literalToken);
        $this->assertSame('abc', $literalToken->value);
        $this->assertSame(2, $literalToken->position);
        $this->assertTrue($accessor->getInQuoteMode());

        $endToken = $accessor->callPrivateMethod('consumeQuoteMode');
        $this->assertInstanceOf(Token::class, $endToken);
        $this->assertSame(TokenType::T_QUOTE_MODE_END, $endToken->type);
        $this->assertFalse($accessor->getInQuoteMode());
    }

    public function test_consume_comment_mode_reports_a_pcre_failure(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);

        // As for a quoted run: a comment that PCRE cannot read is an error,
        // not a reason to drop the rest of the pattern.
        $invalid = "\xC3";
        $accessor->setPattern($invalid);
        $accessor->setLength(\strlen($invalid));
        $accessor->setPosition(0);
        $accessor->setInCommentMode(true);

        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('PCRE Error while reading a comment');

        $accessor->callPrivateMethod('consumeCommentMode');
    }

    public function test_consume_comment_mode_unclosed_reaches_eof(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);
        $pattern = '(?#';

        $accessor->setPattern($pattern);
        $accessor->setLength(\strlen($pattern));
        $accessor->setPosition(\strlen($pattern));
        $accessor->setInCommentMode(true);

        $result = $accessor->callPrivateMethod('consumeCommentMode');

        $this->assertNull($result);
        $this->assertSame(\strlen($pattern), $accessor->getPosition());
        $this->assertTrue($this->getPrivateBool($lexer, 'inCommentMode'));
    }

    public function test_consume_comment_mode_returns_literal_and_closing_token(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);
        $pattern = 'text)';

        $accessor->setPattern($pattern);
        $accessor->setLength(\strlen($pattern));
        $accessor->setPosition(3); // after 'tex'
        $accessor->setInCommentMode(true);

        $literal = $accessor->callPrivateMethod('consumeCommentMode');
        $this->assertInstanceOf(Token::class, $literal);
        $this->assertSame('t', $literal->value);
        $this->assertSame(3, $literal->position);
        $this->assertTrue($this->getPrivateBool($lexer, 'inCommentMode'));

        $closing = $accessor->callPrivateMethod('consumeCommentMode');
        $this->assertInstanceOf(Token::class, $closing);
        $this->assertSame(TokenType::T_GROUP_CLOSE, $closing->type);
        $this->assertFalse($this->getPrivateBool($lexer, 'inCommentMode'));
    }

    public function test_extract_token_value_handles_bell_escape(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);

        $value = $accessor->callPrivateMethod('extractTokenValue', [TokenType::T_LITERAL_ESCAPED, '\\a', []]);

        $this->assertSame("\x07", $value);
    }

    public function test_extract_token_value_falls_back_to_default(): void
    {
        $lexer = new Lexer();
        $accessor = new LexerAccessor($lexer);

        $value = $accessor->callPrivateMethod('extractTokenValue', [TokenType::T_LITERAL, 'X', []]);

        $this->assertSame('X', $value);
    }

    private function getPrivateBool(object $object, string $property): bool
    {
        $ref = new \ReflectionProperty($object, $property);

        return (bool) $ref->getValue($object);
    }
}

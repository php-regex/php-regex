<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Sweep;

use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Lexer;
use PhpRegex\Parser\Token\TokenType;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through Lexer.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class LexerSweepTest extends TestCase
{
    public function test_lexer_unicode_prop_normalization(): void
    {
        $tokens = (new Lexer())->tokenize('/\p{L}/')->getTokens();
        $this->assertNotEmpty($tokens);

        // Test negated property
        $tokens = (new Lexer())->tokenize('/\P{L}/')->getTokens();
        $this->assertNotEmpty($tokens);

        // Test double negation
        $tokens = (new Lexer())->tokenize('/\P{^L}/')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_escaped_literals(): void
    {
        // Test all escaped literals
        $patterns = ['\t', '\n', '\r', '\f', '\v', '\e', '\.', '\[', '\]'];
        foreach ($patterns as $pattern) {
            $tokens = (new Lexer())->tokenize('/'.$pattern.'/')->getTokens();
            $this->assertNotEmpty($tokens);
        }
    }

    public function test_lexer_quote_mode_without_end(): void
    {
        // Quote mode without \E
        $tokens = (new Lexer())->tokenize('/\Qabc/')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_quote_mode_with_end(): void
    {
        // Quote mode with \E
        $tokens = (new Lexer())->tokenize('/\Qabc\Edef/')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_backref_variations(): void
    {
        // Test \g{-1}
        $tokens = (new Lexer())->tokenize('/(a)\g{-1}/')->getTokens();
        $this->assertNotEmpty($tokens);

        // Test \g{1}
        $tokens = (new Lexer())->tokenize('/(a)\g{1}/')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_octal_legacy(): void
    {
        $tokens = (new Lexer())->tokenize('/\01/')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_posix_class(): void
    {
        $tokens = (new Lexer())->tokenize('/[[:alpha:]]/')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_quote_mode_with_empty_literal(): void
    {
        $tokens = (new Lexer())->tokenize('\Q\E')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_quote_mode_ending_at_string_end(): void
    {
        $tokens = (new Lexer())->tokenize('\Qtest')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_extract_token_value_escape_sequences(): void
    {
        // These are tested indirectly through parsing
        $tokens = (new Lexer())->tokenize('\t\n\r\f\v\e')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_normalize_unicode_prop_variations(): void
    {
        // Test \p{L}, \P{L}, \p{^L}, \P{^L} variations
        $tokens = (new Lexer())->tokenize('\p{L}\P{L}\p{^L}\P{^L}')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_all_escape_sequences_in_char_class(): void
    {
        $tokens = (new Lexer())->tokenize('[\\t\\n\\r\\f\\v\\e\\d\\s\\w]')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_unicode_props_in_char_class(): void
    {
        $tokens = (new Lexer())->tokenize('[\\p{L}\\P{L}]')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_posix_in_char_class(): void
    {
        $tokens = (new Lexer())->tokenize('[[:alpha:][:digit:]]')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_backref_variations_2(): void
    {
        $tokens = (new Lexer())->tokenize('\\1\\k<name>\\k{name}')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_g_reference_all_forms(): void
    {
        $tokens = (new Lexer())->tokenize('\\g1\\g{1}\\g<name>\\g-1\\g+1')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_pcre_verbs(): void
    {
        $tokens = (new Lexer())->tokenize('(*ACCEPT)(*FAIL)(*MARK:name)')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_quote_mode_with_backslash(): void
    {
        $tokens = (new Lexer())->tokenize('\\Q\\\\E')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_quote_mode_with_metacharacters(): void
    {
        $tokens = (new Lexer())->tokenize('\\Q.*+?^$[](){}|\\E')->getTokens();
        $this->assertNotEmpty($tokens);
    }

    public function test_lexer_trailing_backslash(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unable to tokenize');

        (new Lexer())->tokenize('abc\\');
    }

    public function test_lexer_unclosed_character_class(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unclosed character class');

        (new Lexer())->tokenize('[abc');
    }

    public function test_lexer_unclosed_comment(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unclosed comment');

        (new Lexer())->tokenize('(?#comment without closing');
    }

    public function test_lexer_comment_at_end_of_string(): void
    {
        // Test comment mode that reaches end of string
        try {
            (new Lexer())->tokenize('abc(?#test');
            $this->fail('Expected LexerException');
        } catch (LexerException $e) {
            $this->assertStringContainsString('Unclosed comment', $e->getMessage());
        }
    }

    /**
     * Tests the "default" fallback of extractTokenValue in Lexer.
     * Simulates a T_LITERAL_ESCAPED token that is not in the known list (\t, \n, etc.)
     * to force the 'default => substr($matchedValue, 1)'.
     */
    public function test_lexer_extract_token_value_default_escape(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('');
        $reflection = new \ReflectionClass($lexer);
        $method = $reflection->getMethod('extractTokenValue');

        // Simulates an unknown escaped character, e.g. '\@' -> '@'
        $result = $method->invoke($lexer, TokenType::LiteralEscaped, '\@', []);

        $this->assertSame('@', $result);
    }

    /**
     * Tests the global "default" fallback of extractTokenValue.
     * Forces a token type that has no specific logic.
     */
    public function test_lexer_extract_token_value_global_default(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('');
        $reflection = new \ReflectionClass($lexer);
        $method = $reflection->getMethod('extractTokenValue');

        // T_LITERAL falls into the default
        $result = $method->invoke($lexer, TokenType::Literal, 'A', []);

        $this->assertSame('A', $result);
    }

    /**
     * Tests the fallback of normalizeUnicodeProp when captures are missing.
     */
    public function test_lexer_normalize_unicode_missing_captures(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('');
        $reflection = new \ReflectionClass($lexer);
        $method = $reflection->getMethod('normalizeUnicodeProp');

        // Empty property to hit the fallback path
        $result = $method->invoke($lexer, '\p{}');

        $this->assertSame('', $result);
    }
}

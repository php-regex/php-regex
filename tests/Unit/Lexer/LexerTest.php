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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Token\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    public function test_tokenize_simple_literal(): void
    {
        $tokens = (new Lexer())->tokenize('foo')->getTokens();

        // f o o EOF = 4 tokens
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::Literal, $tokens[0]->type);
        $this->assertSame('f', $tokens[0]->value);
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame('o', $tokens[1]->value);
        $this->assertSame(TokenType::Literal, $tokens[2]->type);
        $this->assertSame('o', $tokens[2]->value);
        $this->assertSame(TokenType::Eof, $tokens[3]->type);
    }

    public function test_tokenize_multibyte_literal(): void
    {
        $tokens = (new Lexer())->tokenize('fôô')->getTokens();

        // f ô ô EOF = 4 tokens
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::Literal, $tokens[0]->type);
        $this->assertSame('f', $tokens[0]->value);
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame('ô', $tokens[1]->value);
        $this->assertSame(TokenType::Literal, $tokens[2]->type);
        $this->assertSame('ô', $tokens[2]->value);
    }

    public function test_tokenize_group_and_quantifier(): void
    {
        $tokens = (new Lexer())->tokenize('(bar)?')->getTokens();

        $expected = [
            TokenType::GroupOpen,
            TokenType::Literal, // b
            TokenType::Literal, // a
            TokenType::Literal, // r
            TokenType::GroupClose,
            TokenType::Quantifier, // ?
            TokenType::Eof,
        ];
        $this->assertCount(\count($expected), $tokens);
        $this->assertSame('?', $tokens[5]->value);

        foreach ($expected as $i => $type) {
            $this->assertSame($type, $tokens[$i]->type);
        }
    }

    public function test_tokenize_alternation(): void
    {
        $tokens = (new Lexer())->tokenize('foo|bar')->getTokens();
        // f o o | b a r EOF = 8 tokens
        $this->assertCount(8, $tokens);
        $this->assertSame(TokenType::Alternation, $tokens[3]->type);
    }

    public function test_tokenize_custom_quantifier(): void
    {
        $tokens = (new Lexer())->tokenize('a{2,4}')->getTokens();

        // a {2,4} EOF = 3 tokens
        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::Literal, $tokens[0]->type);
        $this->assertSame(TokenType::Quantifier, $tokens[1]->type);
        $this->assertSame('{2,4}', $tokens[1]->value);
    }

    public function test_tokenize_invalid_quantifier_as_literal(): void
    {
        $tokens = (new Lexer())->tokenize('a{b}')->getTokens();
        // a { b } EOF = 5 tokens
        $this->assertCount(5, $tokens);
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame('{', $tokens[1]->value);
        $this->assertSame(TokenType::Literal, $tokens[2]->type);
        $this->assertSame('b', $tokens[2]->value);
        $this->assertSame(TokenType::Literal, $tokens[3]->type);
        $this->assertSame('}', $tokens[3]->value);
    }

    public function test_tokenize_escaped_meta_char(): void
    {
        $tokens = (new Lexer())->tokenize('\\(a\\*\\)')->getTokens();

        // ( a * ) EOF = 5 tokens
        $this->assertCount(5, $tokens);

        $this->assertSame(TokenType::LiteralEscaped, $tokens[0]->type); // \(
        $this->assertSame('(', $tokens[0]->value);

        $this->assertSame(TokenType::Literal, $tokens[1]->type); // a
        $this->assertSame('a', $tokens[1]->value);

        $this->assertSame(TokenType::LiteralEscaped, $tokens[2]->type); // \*
        $this->assertSame('*', $tokens[2]->value);

        $this->assertSame(TokenType::LiteralEscaped, $tokens[3]->type); // \)
        $this->assertSame(')', $tokens[3]->value);

        $this->assertSame(TokenType::Eof, $tokens[4]->type);
    }

    public function test_tokenize_char_types_and_dot(): void
    {
        $tokens = (new Lexer())->tokenize('.\d\s\w\D\S\W')->getTokens();

        $expected = [
            TokenType::Dot,
            TokenType::CharType, // \d
            TokenType::CharType, // \s
            TokenType::CharType, // \w
            TokenType::CharType, // \D
            TokenType::CharType, // \S
            TokenType::CharType, // \W
            TokenType::Eof,
        ];
        $this->assertCount(\count($expected), $tokens);

        foreach ($expected as $i => $type) {
            $this->assertSame($type, $tokens[$i]->type);
        }

        $this->assertSame('d', $tokens[1]->value);
        $this->assertSame('W', $tokens[6]->value);
    }

    public function test_tokenize_anchors(): void
    {
        $tokens = (new Lexer())->tokenize('^foo$')->getTokens();

        // ^ f o o $ EOF = 6 tokens
        $this->assertCount(6, $tokens);
        $this->assertSame(TokenType::Anchor, $tokens[0]->type);
        $this->assertSame('^', $tokens[0]->value);
        $this->assertSame(TokenType::Anchor, $tokens[4]->type);
        $this->assertSame('$', $tokens[4]->value);
    }

    public function test_tokenize_assertions(): void
    {
        $tokens = (new Lexer())->tokenize('\\Afoo\\z\\b\\G\\B')->getTokens();

        $this->assertSame(TokenType::Assertion, $tokens[0]->type);
        $this->assertSame('A', $tokens[0]->value);
        // ... f o o
        $this->assertSame(TokenType::Assertion, $tokens[4]->type);
        $this->assertSame('z', $tokens[4]->value);
        $this->assertSame(TokenType::Assertion, $tokens[5]->type);
        $this->assertSame('b', $tokens[5]->value);
        $this->assertSame(TokenType::Assertion, $tokens[6]->type);
        $this->assertSame('G', $tokens[6]->value);
        $this->assertSame(TokenType::Assertion, $tokens[7]->type);
        $this->assertSame('B', $tokens[7]->value);
    }

    public function test_tokenize_unicode_prop(): void
    {
        $tokens = (new Lexer())->tokenize('\\p{L}\\P{^L}\\pL')->getTokens();

        $this->assertSame(TokenType::UnicodeProp, $tokens[0]->type);
        $this->assertSame('{L}', $tokens[0]->value); // \p{L}
        $this->assertSame(TokenType::UnicodeProp, $tokens[1]->type);
        $this->assertSame('{L}', $tokens[1]->value); // \P{^L} - double negation cancels out
        $this->assertSame(TokenType::UnicodeProp, $tokens[2]->type);
        $this->assertSame('L', $tokens[2]->value); // \pL
    }

    public function test_tokenize_octal(): void
    {
        $tokens = (new Lexer())->tokenize('\\o{777}')->getTokens();

        $this->assertSame(TokenType::Octal, $tokens[0]->type);
        $this->assertSame('\\o{777}', $tokens[0]->value);
    }

    /**
     * @param array<string> $expectedOctals
     */
    #[DataProvider('provide_legacy_octal_sequences')]
    public function test_tokenize_legacy_octal_sequences(string $pattern, array $expectedOctals, bool $expectRange): void
    {
        $tokens = (new Lexer())->tokenize($pattern)->getTokens();

        $octalTokens = array_values(array_filter(
            $tokens,
            static fn ($token) => TokenType::OctalLegacy === $token->type,
        ));

        $this->assertSame(
            $expectedOctals,
            array_map(static fn ($token) => $token->value, $octalTokens),
        );

        if ($expectRange) {
            $this->assertSame(TokenType::CharClassOpen, $tokens[0]->type);
            $this->assertSame(TokenType::OctalLegacy, $tokens[1]->type);
            $this->assertSame(TokenType::Range, $tokens[2]->type);
            $this->assertSame(TokenType::OctalLegacy, $tokens[3]->type);
        }
    }

    public static function provide_legacy_octal_sequences(): \Iterator
    {
        yield 'null_byte' => ['[\\000]', ['000'], false];
        yield 'leading_zero' => ['[\\010]', ['010'], false];
        yield 'upper_byte_limit' => ['[\\377]', ['377'], false];
        yield 'max_three_digit' => ['[\\777]', ['777'], false];
        yield 'mixed_digits' => ['[\\123]', ['123'], false];
        yield 'range_preserves_three_digit' => ['[\\177-\\377]', ['177', '377'], true];
    }

    public function test_throws_on_trailing_backslash(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unable to tokenize');
        (new Lexer())->tokenize('foo\\');
    }

    /**
     * PCRE reports a trailing backslash at the end of the pattern, not at
     * the backslash: pcre2test 10.49, the pattern given in hex (no PHP
     * pattern can end on a backslash, it would escape the delimiter), "\ at
     * end of pattern" at each offset below.
     */
    #[Test]
    #[DataProvider('provideTrailingBackslashes')]
    public function test_tokenize_reports_a_trailing_backslash_where_pcre_does(string $pattern, int $offset): void
    {
        $caught = null;

        try {
            (new Lexer())->tokenize($pattern);
        } catch (LexerException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(LexerException::class, $caught, \sprintf('%s ends on a backslash but was read.', $pattern));
        $this->assertSame([ErrorCode::EscapeTrailingBackslash, $offset], [$caught->getErrorCode(), $caught->getPosition()], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideTrailingBackslashes(): iterable
    {
        yield 'after a literal' => ['pattern' => 'a\\', 'offset' => 2];
        yield 'in an open group' => ['pattern' => '(a\\', 'offset' => 3];
        yield 'after three literals' => ['pattern' => 'foo\\', 'offset' => 4];
        yield 'in an open non-capturing group' => ['pattern' => '(?:a\\', 'offset' => 5];
        yield 'in an open named group' => ['pattern' => '(?<n>a\\', 'offset' => 7];
        yield 'in an open lookahead' => ['pattern' => '(?=a\\', 'offset' => 5];
        yield 'after an empty alternative' => ['pattern' => 'a|\\', 'offset' => 3];
        yield 'after an empty quote' => ['pattern' => 'a\\Q\\E\\', 'offset' => 6];
        yield 'after a two-byte character' => ['pattern' => "\u{e9}\\", 'offset' => 3];
        yield 'after an x comment' => ['pattern' => "(?x)a #c\n\\", 'offset' => 10];
    }

    /**
     * This test validates that the internal pattern constants of the Lexer
     * are properly defined and can be compiled into valid PCRE patterns.
     */
    public function test_validate_pattern_constants(): void
    {
        // Use reflection to access private constants
        $reflection = new \ReflectionClass(Lexer::class);
        $consts = $reflection->getConstants();

        $this->assertArrayHasKey('PATTERNS_OUTSIDE', $consts, 'Lexer class must define PATTERNS_OUTSIDE');
        $this->assertArrayHasKey('PATTERNS_INSIDE', $consts, 'Lexer class must define PATTERNS_INSIDE');

        $this->assertIsArray($consts['PATTERNS_OUTSIDE']);
        $this->assertIsArray($consts['PATTERNS_INSIDE']);

        // Test that we can create a lexer and tokenize (which compiles the patterns)
        $lexer = new Lexer();
        $stream = $lexer->tokenize('test');
        $this->assertIsArray($stream->getTokens());

        // Test that patterns are not empty
        $this->assertNotEmpty($consts['PATTERNS_OUTSIDE']);
        $this->assertNotEmpty($consts['PATTERNS_INSIDE']);
    }

    public function test_tokenize_inside_char_class_range_negation_literals(): void
    {
        // Tests context-sensitive tokens: ^ (negation), - (range), and ] (literal)
        $tokens = (new Lexer())->tokenize('[^a-z-]]')->getTokens();

        $this->assertSame(TokenType::CharClassOpen, $tokens[0]->type);
        $this->assertSame(TokenType::Negation, $tokens[1]->type); // ^ at start
        $this->assertSame(TokenType::Literal, $tokens[2]->type); // a
        $this->assertSame(TokenType::Range, $tokens[3]->type); // - in middle
        $this->assertSame(TokenType::Literal, $tokens[4]->type); // z
        $this->assertSame(TokenType::Range, $tokens[5]->type); // - in middle (literal if last, but here it's followed by ])
        $this->assertSame(TokenType::CharClassClose, $tokens[6]->type); // ] at end
        $this->assertSame(TokenType::Literal, $tokens[7]->type); // Trailing ] (literal because of position logic)
    }

    public function test_tokenize_char_class_literal_at_start(): void
    {
        // [^]a] - literal ']' at start
        $tokens = (new Lexer())->tokenize('[^]a]')->getTokens();

        $this->assertSame(TokenType::Negation, $tokens[1]->type);
        $this->assertSame(TokenType::Literal, $tokens[2]->type); // ']' as literal
    }

    public function test_tokenize_posix_class(): void
    {
        $tokens = (new Lexer())->tokenize('[[:alnum:]]')->getTokens();

        $this->assertSame(TokenType::PosixClass, $tokens[1]->type);
        $this->assertSame('alnum', $tokens[1]->value);
    }

    public function test_tokenize_unclosed_char_class_error_with_end_of_input(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unclosed character class "]" at end of input.');
        (new Lexer())->tokenize('[a');
    }

    public function test_tokenize_quote_mode(): void
    {
        $tokens = (new Lexer())->tokenize('\Q*+.\Efoo')->getTokens();

        // Now emits T_QUOTE_MODE_START, T_LITERAL (content), T_QUOTE_MODE_END for full fidelity
        $this->assertSame(TokenType::QuoteModeStart, $tokens[0]->type);
        $this->assertSame('\Q', $tokens[0]->value);
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame('*+.', $tokens[1]->value);
        $this->assertSame(TokenType::QuoteModeEnd, $tokens[2]->type);
        $this->assertSame('\E', $tokens[2]->value);
        $this->assertSame(TokenType::Literal, $tokens[3]->type);
        $this->assertSame('f', $tokens[3]->value);
    }

    public function test_tokenize_char_type_n_h_v(): void
    {
        // Test \N (any char except newline), \H (not horizontal whitespace), \V (not vertical whitespace)
        $tokens = (new Lexer())->tokenize('\\N\\H\\V')->getTokens();

        $this->assertSame(TokenType::CharType, $tokens[0]->type);
        $this->assertSame('N', $tokens[0]->value);

        $this->assertSame(TokenType::CharType, $tokens[1]->type);
        $this->assertSame('H', $tokens[1]->value);

        $this->assertSame(TokenType::CharType, $tokens[2]->type);
        $this->assertSame('V', $tokens[2]->value);

        $this->assertSame(TokenType::Eof, $tokens[3]->type);
    }

    public function test_tokenize_char_type_n_h_v_inside_char_class(): void
    {
        // Test \N, \H, \V inside character classes
        $tokens = (new Lexer())->tokenize('[\\N\\H\\V]')->getTokens();

        $this->assertSame(TokenType::CharClassOpen, $tokens[0]->type);

        $this->assertSame(TokenType::CharType, $tokens[1]->type);
        $this->assertSame('N', $tokens[1]->value);

        $this->assertSame(TokenType::CharType, $tokens[2]->type);
        $this->assertSame('H', $tokens[2]->value);

        $this->assertSame(TokenType::CharType, $tokens[3]->type);
        $this->assertSame('V', $tokens[3]->value);

        $this->assertSame(TokenType::CharClassClose, $tokens[4]->type);
    }

    public function test_tokenize_backslash_b_inside_char_class_is_backspace(): void
    {
        // Inside character class, \b means backspace (0x08), not word boundary
        $tokens = (new Lexer())->tokenize('[\\b]')->getTokens();

        $this->assertSame(TokenType::CharClassOpen, $tokens[0]->type);

        // \b inside char class should be T_LITERAL_ESCAPED with value \x08 (backspace)
        $this->assertSame(TokenType::LiteralEscaped, $tokens[1]->type);
        $this->assertSame("\x08", $tokens[1]->value);

        $this->assertSame(TokenType::CharClassClose, $tokens[2]->type);
    }

    public function test_tokenize_backslash_b_outside_char_class_is_assertion(): void
    {
        // Outside character class, \b is word boundary assertion
        $tokens = (new Lexer())->tokenize('\\bword\\b')->getTokens();

        $this->assertSame(TokenType::Assertion, $tokens[0]->type);
        $this->assertSame('b', $tokens[0]->value);

        // w o r d
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame(TokenType::Literal, $tokens[2]->type);
        $this->assertSame(TokenType::Literal, $tokens[3]->type);
        $this->assertSame(TokenType::Literal, $tokens[4]->type);

        $this->assertSame(TokenType::Assertion, $tokens[5]->type);
        $this->assertSame('b', $tokens[5]->value);
    }

    public function test_tokenize_backslash_b_mixed_context(): void
    {
        // Test \b in both contexts: outside (assertion) and inside char class (backspace)
        $tokens = (new Lexer())->tokenize('\\b[\\b]\\b')->getTokens();

        // First \b - outside, word boundary assertion
        $this->assertSame(TokenType::Assertion, $tokens[0]->type);
        $this->assertSame('b', $tokens[0]->value);

        // [ - char class open
        $this->assertSame(TokenType::CharClassOpen, $tokens[1]->type);

        // \b inside char class - backspace
        $this->assertSame(TokenType::LiteralEscaped, $tokens[2]->type);
        $this->assertSame("\x08", $tokens[2]->value);

        // ] - char class close
        $this->assertSame(TokenType::CharClassClose, $tokens[3]->type);

        // Last \b - outside, word boundary assertion
        $this->assertSame(TokenType::Assertion, $tokens[4]->type);
        $this->assertSame('b', $tokens[4]->value);
    }
}

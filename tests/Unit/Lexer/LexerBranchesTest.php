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

namespace RegexParser\Tests\Unit\Lexer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RegexParser\ErrorCode;
use RegexParser\Exception\LexerException;
use RegexParser\Lexer;
use RegexParser\Token;
use RegexParser\TokenType;

/**
 * Comprehensive tests to improve Lexer coverage to 100%.
 * Focuses on uncovered code paths in:
 * - consumeQuoteMode()
 * - extractTokenValue()
 * - normalizeUnicodeProp()
 */
final class LexerBranchesTest extends TestCase
{
    public function test_escaped_tab_character(): void
    {
        $tokens = (new Lexer())->tokenize('\t')->getTokens();

        $this->assertCount(2, $tokens); // T_LITERAL_ESCAPED + EOF
        $this->assertSame(TokenType::T_LITERAL_ESCAPED, $tokens[0]->type);
        $this->assertSame("\t", $tokens[0]->value);
    }

    public function test_escaped_newline_character(): void
    {
        $tokens = (new Lexer())->tokenize('\n')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_LITERAL_ESCAPED, $tokens[0]->type);
        $this->assertSame("\n", $tokens[0]->value);
    }

    public function test_escaped_carriage_return_character(): void
    {
        $tokens = (new Lexer())->tokenize('\r')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_LITERAL_ESCAPED, $tokens[0]->type);
        $this->assertSame("\r", $tokens[0]->value);
    }

    public function test_escaped_form_feed_character(): void
    {
        $tokens = (new Lexer())->tokenize('\f')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_LITERAL_ESCAPED, $tokens[0]->type);
        $this->assertSame("\f", $tokens[0]->value);
    }

    public function test_escaped_vertical_tab_character(): void
    {
        // Note: \v is T_CHAR_TYPE in PCRE regex context (vertical whitespace)
        $tokens = (new Lexer())->tokenize('\v')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_CHAR_TYPE, $tokens[0]->type);
        $this->assertSame('v', $tokens[0]->value);
    }

    public function test_escaped_escape_character(): void
    {
        $tokens = (new Lexer())->tokenize('\e')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_LITERAL_ESCAPED, $tokens[0]->type);
        $this->assertSame("\e", $tokens[0]->value);
    }

    public function test_multiple_escape_sequences(): void
    {
        // Note: \v is T_CHAR_TYPE, not T_LITERAL_ESCAPED in regex context
        $tokens = (new Lexer())->tokenize('\t\n\r\f\e')->getTokens();

        $this->assertCount(6, $tokens); // 5 escaped + EOF
        $this->assertSame("\t", $tokens[0]->value);
        $this->assertSame("\n", $tokens[1]->value);
        $this->assertSame("\r", $tokens[2]->value);
        $this->assertSame("\f", $tokens[3]->value);
        $this->assertSame("\e", $tokens[4]->value);
    }

    public function test_escape_sequences_in_pattern(): void
    {
        $tokens = (new Lexer())->tokenize('abc\tdef\nghi')->getTokens();

        // Should have: a, b, c, \t, d, e, f, \n, g, h, i, EOF
        $this->assertSame('a', $tokens[0]->value);
        $this->assertSame('b', $tokens[1]->value);
        $this->assertSame('c', $tokens[2]->value);
        $this->assertSame("\t", $tokens[3]->value);
        $this->assertSame('d', $tokens[4]->value);
        $this->assertSame('e', $tokens[5]->value);
        $this->assertSame('f', $tokens[6]->value);
        $this->assertSame("\n", $tokens[7]->value);
    }

    public function test_unicode_prop_lowercase_p_simple(): void
    {
        $tokens = (new Lexer())->tokenize('\p{L}')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_UNICODE_PROP, $tokens[0]->type);
        $this->assertSame('{L}', $tokens[0]->value);
    }

    public function test_unicode_prop_uppercase_p_simple(): void
    {
        $tokens = (new Lexer())->tokenize('\P{L}')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_UNICODE_PROP, $tokens[0]->type);
        $this->assertSame('{^L}', $tokens[0]->value); // Negated
    }

    public function test_unicode_prop_lowercase_p_with_negation(): void
    {
        $tokens = (new Lexer())->tokenize('\p{^L}')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_UNICODE_PROP, $tokens[0]->type);
        $this->assertSame('{^L}', $tokens[0]->value);
    }

    public function test_unicode_prop_uppercase_p_with_double_negation(): void
    {
        // \P{^L} should result in 'L' (double negation cancels out)
        $tokens = (new Lexer())->tokenize('\P{^L}')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_UNICODE_PROP, $tokens[0]->type);
        $this->assertSame('{L}', $tokens[0]->value); // Double negation removed
    }

    public function test_unicode_prop_short_form_lowercase(): void
    {
        // \pL (short form)
        $tokens = (new Lexer())->tokenize('\pL')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_UNICODE_PROP, $tokens[0]->type);
        $this->assertSame('L', $tokens[0]->value);
    }

    public function test_unicode_prop_short_form_uppercase(): void
    {
        // \PL (short form, negated)
        $tokens = (new Lexer())->tokenize('\PL')->getTokens();

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::T_UNICODE_PROP, $tokens[0]->type);
        $this->assertSame('^L', $tokens[0]->value);
    }

    public function test_unicode_prop_various_properties(): void
    {
        $patterns = [
            '\p{L}' => '{L}',           // Simple property
            '\P{L}' => '{^L}',          // Negated simple property
            '\p{Letter}' => '{Letter}',   // Long property name
            '\P{Letter}' => '{^Letter}',  // Negated long property name
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(
                $expectedValue,
                $tokens[0]->value,
                "Failed for pattern: {$pattern}",
            );
        }
    }

    public function test_pcre_verb_extraction(): void
    {
        $patterns = [
            '(*FAIL)' => 'FAIL',
            '(*ACCEPT)' => 'ACCEPT',
            '(*MARK:foo)' => 'MARK:foo',
            '(*COMMIT)' => 'COMMIT',
            '(*PRUNE)' => 'PRUNE',
            '(*SKIP)' => 'SKIP',
            '(*THEN)' => 'THEN',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_PCRE_VERB, $tokens[0]->type);
            $this->assertSame($expectedValue, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_assertion_extraction(): void
    {
        $patterns = [
            '\A' => 'A',
            '\z' => 'z',
            '\Z' => 'Z',
            '\G' => 'G',
            '\b' => 'b',
            '\B' => 'B',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_ASSERTION, $tokens[0]->type);
            $this->assertSame($expectedValue, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_char_type_extraction(): void
    {
        $patterns = [
            '\d' => 'd',
            '\D' => 'D',
            '\s' => 's',
            '\S' => 'S',
            '\w' => 'w',
            '\W' => 'W',
            '\h' => 'h',
            '\H' => 'H',
            '\v' => 'v', // Note: \v in char type context, not escape sequence
            '\R' => 'R',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            // \v can be either T_CHAR_TYPE or T_LITERAL_ESCAPED depending on context
            $this->assertTrue(
                TokenType::T_CHAR_TYPE === $tokens[0]->type
                || TokenType::T_LITERAL_ESCAPED === $tokens[0]->type,
                "Failed type check for: {$pattern}",
            );
            $this->assertSame($expectedValue, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_keep_extraction(): void
    {
        $tokens = (new Lexer())->tokenize('\K')->getTokens();

        $this->assertSame(TokenType::T_KEEP, $tokens[0]->type);
        $this->assertSame('K', $tokens[0]->value);
    }

    public function test_backref_with_number_extraction(): void
    {
        $patterns = [
            '\1' => '\1',
            '\2' => '\2',
            '\9' => '\9',
            '\10' => '\10',
            '\99' => '\99',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_BACKREF, $tokens[0]->type);
            $this->assertSame($expectedValue, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_backref_with_named_groups(): void
    {
        $patterns = [
            '\k<name>',
            '\k{name}',
        ];

        foreach ($patterns as $pattern) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_BACKREF, $tokens[0]->type);
            $this->assertSame($pattern, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_octal_legacy_extraction(): void
    {
        $patterns = [
            '\0' => '0',
            '\01' => '01',
            '\07' => '07',
            '\012' => '012',
            '\077' => '077',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_OCTAL_LEGACY, $tokens[0]->type);
            $this->assertSame($expectedValue, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_posix_class_extraction(): void
    {
        $patterns = [
            '[[:alnum:]]' => 'alnum',
            '[[:^alnum:]]' => '^alnum',
            '[[:alpha:]]' => 'alpha',
            '[[:digit:]]' => 'digit',
            '[[:lower:]]' => 'lower',
            '[[:upper:]]' => 'upper',
            '[[:space:]]' => 'space',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();
            $posixToken = null;
            foreach ($tokens as $token) {
                if (TokenType::T_POSIX_CLASS === $token->type) {
                    $posixToken = $token;

                    break;
                }
            }

            $this->assertInstanceOf(Token::class, $posixToken, "No POSIX class token found for: {$pattern}");
            $this->assertSame($expectedValue, $posixToken->value, "Failed for: {$pattern}");
        }
    }

    public function test_quote_mode_with_multiple_segments(): void
    {
        $tokens = (new Lexer())->tokenize('a\Q*+?\Eb\Q[]\Ec')->getTokens();

        // Should have: a, *+?, b, [], c, EOF
        $values = array_map(static fn ($t) => $t->value, array_filter($tokens, static fn ($t) => TokenType::T_EOF !== $t->type));

        $this->assertContains('a', $values);
        $this->assertContains('*+?', $values);
        $this->assertContains('b', $values);
        $this->assertContains('[]', $values);
        $this->assertContains('c', $values);
    }

    public function test_quote_mode_at_start(): void
    {
        $tokens = (new Lexer())->tokenize('\Q*+?\Eabc')->getTokens();

        // Now emits T_QUOTE_MODE_START first, then literal content
        $this->assertSame(TokenType::T_QUOTE_MODE_START, $tokens[0]->type);
        $this->assertSame('\Q', $tokens[0]->value);
        $this->assertSame(TokenType::T_LITERAL, $tokens[1]->type);
        $this->assertSame('*+?', $tokens[1]->value);
        $this->assertSame(TokenType::T_QUOTE_MODE_END, $tokens[2]->type);
    }

    public function test_quote_mode_at_end(): void
    {
        $tokens = (new Lexer())->tokenize('abc\Q*+?')->getTokens();

        // Last token before EOF should be the quoted literal
        $nonEofTokens = array_filter($tokens, static fn ($t) => TokenType::T_EOF !== $t->type);
        $lastToken = end($nonEofTokens);

        $this->assertNotFalse($lastToken);
        $this->assertSame('*+?', $lastToken->value);
        $this->assertSame(TokenType::T_LITERAL, $lastToken->type);
    }

    public function test_quote_mode_with_special_chars(): void
    {
        $tokens = (new Lexer())->tokenize('\Q()[]{}^$|.?*+\E')->getTokens();

        // Now emits T_QUOTE_MODE_START, T_LITERAL, T_QUOTE_MODE_END, T_EOF
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::T_QUOTE_MODE_START, $tokens[0]->type);
        $this->assertSame(TokenType::T_LITERAL, $tokens[1]->type);
        $this->assertSame('()[]{}^$|.?*+', $tokens[1]->value);
        $this->assertSame(TokenType::T_QUOTE_MODE_END, $tokens[2]->type);
    }

    public function test_quote_mode_with_backslashes(): void
    {
        $tokens = (new Lexer())->tokenize('\Q\d\s\w\E')->getTokens();

        // Now emits T_QUOTE_MODE_START, T_LITERAL, T_QUOTE_MODE_END, T_EOF
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::T_QUOTE_MODE_START, $tokens[0]->type);
        $this->assertSame(TokenType::T_LITERAL, $tokens[1]->type);
        $this->assertSame('\d\s\w', $tokens[1]->value);
        $this->assertSame(TokenType::T_QUOTE_MODE_END, $tokens[2]->type);
    }

    public function test_nested_quote_mode_markers(): void
    {
        // \Q starts quote mode, captures '\Q' as literal, first \E ends quote mode
        // Second \E is outside quote mode and becomes T_QUOTE_MODE_END token
        $tokens = (new Lexer())->tokenize('\Q\Q\E\E')->getTokens();

        // Now emits: T_QUOTE_MODE_START, T_LITERAL('\Q'), T_QUOTE_MODE_END, T_QUOTE_MODE_END, T_EOF
        $this->assertCount(5, $tokens);
        $this->assertSame(TokenType::T_QUOTE_MODE_START, $tokens[0]->type);
        $this->assertSame(TokenType::T_LITERAL, $tokens[1]->type);
        $this->assertSame('\Q', $tokens[1]->value);
        $this->assertSame(TokenType::T_QUOTE_MODE_END, $tokens[2]->type);
        $this->assertSame(TokenType::T_QUOTE_MODE_END, $tokens[3]->type);
    }

    public function test_g_reference_variations(): void
    {
        $patterns = [
            '\g1',
            '\g-1',
            '\g+1',
            '\g{name}',
            '\g<name>',
        ];

        foreach ($patterns as $pattern) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_G_REFERENCE, $tokens[0]->type, "Failed for: {$pattern}");
        }
    }

    public function test_octal_with_braces(): void
    {
        $tokens = (new Lexer())->tokenize('\o{123}')->getTokens();

        $this->assertSame(TokenType::T_OCTAL, $tokens[0]->type);
        $this->assertSame('\o{123}', $tokens[0]->value);
    }

    public function test_unicode_hex_variations(): void
    {
        $testCases = [
            '\x41' => '\x41',        // 2-digit hex -> kept as string
            '\u0041' => '\u0041',  // 4-digit hex -> kept as string
            '\u{0041}' => '\u{0041}',    // Unicode with braces -> kept as string
            '\u{1F600}' => '\u{1F600}',   // Emoji codepoint -> kept as string
        ];

        foreach ($testCases as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_UNICODE, $tokens[0]->type, "Failed for: {$pattern}");
            $this->assertSame($expectedValue, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_combined_escape_sequences_and_unicode(): void
    {
        $tokens = (new Lexer())->tokenize('\t\n\p{L}\P{Nd}\r')->getTokens();

        $this->assertCount(6, $tokens); // 5 tokens + EOF
        $this->assertSame("\t", $tokens[0]->value);
        $this->assertSame("\n", $tokens[1]->value);
        $this->assertSame('{L}', $tokens[2]->value);
        $this->assertSame('{^Nd}', $tokens[3]->value);
        $this->assertSame("\r", $tokens[4]->value);
    }

    public function test_escape_sequences_inside_character_class(): void
    {
        $tokens = (new Lexer())->tokenize('[\t\n\r\f\e]')->getTokens();

        // Should have: [, \t, \n, \r, \f, \e, ], EOF
        $this->assertSame('[', $tokens[0]->value);
        $this->assertSame(TokenType::T_CHAR_CLASS_OPEN, $tokens[0]->type);

        // Check escape sequences are present
        $values = array_map(static fn ($t) => $t->value, $tokens);
        $this->assertContains("\t", $values);
        $this->assertContains("\n", $values);
        $this->assertContains("\r", $values);
        $this->assertContains("\f", $values);
        $this->assertContains("\e", $values);
    }

    public function test_all_escape_literal_types(): void
    {
        // Test other escaped characters that fall into the default case
        $patterns = [
            '\.' => '.',
            '\*' => '*',
            '\+' => '+',
            '\?' => '?',
            '\[' => '[',
            '\]' => ']',
            '\(' => '(',
            '\)' => ')',
            '\{' => '{',
            '\}' => '}',
            '\|' => '|',
            '\^' => '^',
            '\$' => '$',
            '\-' => '-',
            '\/' => '/',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_LITERAL_ESCAPED, $tokens[0]->type, "Failed for: {$pattern}");
            $this->assertSame($expectedValue, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_unicode_property_with_underscores_and_digits(): void
    {
        // Test property names with underscores and digits
        $patterns = [
            '\p{Script_Extensions}' => '{Script_Extensions}',
            '\p{InBasic_Latin}' => '{InBasic_Latin}',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(
                $expectedValue,
                $tokens[0]->value,
                "Failed for pattern: {$pattern}",
            );
        }
    }

    public function test_quote_mode_with_newlines_and_special_chars(): void
    {
        $tokens = (new Lexer())->tokenize("\Q\n\t\r\E")->getTokens();

        // Now emits T_QUOTE_MODE_START, T_LITERAL, T_QUOTE_MODE_END, T_EOF
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::T_QUOTE_MODE_START, $tokens[0]->type);
        $this->assertSame(TokenType::T_LITERAL, $tokens[1]->type);
        $this->assertSame("\n\t\r", $tokens[1]->value);
        $this->assertSame(TokenType::T_QUOTE_MODE_END, $tokens[2]->type);
    }

    public function test_backref_edge_cases(): void
    {
        // Test various backreference formats
        $patterns = [
            '\k<name123>',
            '\k{name_test}',
            '\k<_underscore>',
        ];

        foreach ($patterns as $pattern) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_BACKREF, $tokens[0]->type, "Failed for: {$pattern}");
        }
    }

    public function test_g_reference_edge_cases(): void
    {
        // Test g-reference with various formats
        $patterns = [
            '\g0',
            '\g99',
            '\g-99',
            '\g+99',
            '\g{name_123}',
            '\g<name_456>',
        ];

        foreach ($patterns as $pattern) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_G_REFERENCE, $tokens[0]->type, "Failed for: {$pattern}");
        }
    }

    public function test_comment_token(): void
    {
        $tokens = (new Lexer())->tokenize('(?#comment here)')->getTokens();

        // Should have comment open token
        $this->assertSame(TokenType::T_COMMENT_OPEN, $tokens[0]->type);
    }

    public function test_group_modifier_open(): void
    {
        $tokens = (new Lexer())->tokenize('(?i)')->getTokens();

        $this->assertSame(TokenType::T_GROUP_MODIFIER_OPEN, $tokens[0]->type);
    }

    public function test_complex_pattern_with_all_token_types(): void
    {
        // Complex pattern using many different token types
        $tokens = (new Lexer())->tokenize('(?>abc|def)\d+[[:alpha:]]\p{L}\K\b')->getTokens();

        // Just verify it tokenizes without error and produces tokens
        $this->assertGreaterThan(5, \count($tokens));

        // Verify we have various token types
        $types = array_map(static fn ($t) => $t->type, $tokens);
        $this->assertContains(TokenType::T_GROUP_MODIFIER_OPEN, $types);
    }

    public function test_octal_legacy_all_variants(): void
    {
        // Test all octal legacy variants
        $patterns = [
            '\00' => '00',
            '\000' => '000',
        ];

        foreach ($patterns as $pattern => $expectedValue) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_OCTAL_LEGACY, $tokens[0]->type);
            $this->assertSame($expectedValue, $tokens[0]->value, "Failed for: {$pattern}");
        }
    }

    public function test_quote_mode_empty_before_end(): void
    {
        // Test when consumeQuoteMode returns null (empty content at \E)
        $tokens = (new Lexer())->tokenize('a\Q\Eb\Q\Ec')->getTokens();

        // Should have: a, b, c, EOF (no tokens from empty \Q\E)
        $values = array_map(static fn ($t) => $t->value, array_filter($tokens, static fn ($t) => TokenType::T_EOF !== $t->type));

        $this->assertContains('a', $values);
        $this->assertContains('b', $values);
        $this->assertContains('c', $values);
    }

    public function test_pcre_verbs_with_arguments(): void
    {
        $patterns = [
            '(*:NAME)',
            '(*MARK:test)',
            '(*PRUNE:label)',
            '(*THEN:foo)',
        ];

        foreach ($patterns as $pattern) {
            $tokens = (new Lexer())->tokenize($pattern)->getTokens();

            $this->assertSame(TokenType::T_PCRE_VERB, $tokens[0]->type, "Failed for: {$pattern}");
            $this->assertNotEmpty($tokens[0]->value);
        }
    }

    public function test_callout_extraction(): void
    {
        $tokens = (new Lexer())->tokenize('(?C some content )')->getTokens();

        $this->assertSame(TokenType::T_CALLOUT, $tokens[0]->type);
        $this->assertSame(' some content ', $tokens[0]->value);
    }

    public function test_unicode_named_extraction(): void
    {
        $tokens = (new Lexer())->tokenize('\N{LATIN CAPITAL LETTER A}')->getTokens();

        $this->assertSame(TokenType::T_UNICODE_NAMED, $tokens[0]->type);
        $this->assertSame('LATIN CAPITAL LETTER A', $tokens[0]->value);
    }

    public function test_control_char_extraction(): void
    {
        $tokens = (new Lexer())->tokenize('\cA')->getTokens();

        $this->assertSame(TokenType::T_CONTROL_CHAR, $tokens[0]->type);
        $this->assertSame('A', $tokens[0]->value);
    }

    public function test_char_class_ampersands_are_literals(): void
    {
        // PHP has no class intersection: "[a&&b]" matches "&".
        $tokens = (new Lexer())->tokenize('[a&&b]')->getTokens();

        $ampersands = array_values(array_filter($tokens, static fn (Token $token): bool => '&' === $token->value));

        $this->assertCount(2, $ampersands);
        $this->assertSame(TokenType::T_LITERAL, $ampersands[0]->type);
        $this->assertSame(TokenType::T_LITERAL, $ampersands[1]->type);
    }

    public function test_char_class_double_hyphen_is_two_range_tokens(): void
    {
        // PHP has no class subtraction: "[a--b]" is a range from "a" down to
        // "-", which preg_match() refuses as out of order.
        $tokens = (new Lexer())->tokenize('[a--b]')->getTokens();

        $hyphens = array_values(array_filter($tokens, static fn (Token $token): bool => '-' === $token->value));

        $this->assertCount(2, $hyphens);
        $this->assertSame(TokenType::T_RANGE, $hyphens[0]->type);
        $this->assertSame(TokenType::T_RANGE, $hyphens[1]->type);
    }

    public function test_nested_char_class_tokens(): void
    {
        $tokens = (new Lexer())->tokenize('[a[b]c]')->getTokens();

        $openCount = 0;
        $closeCount = 0;
        $hasLiteralOpen = false;
        foreach ($tokens as $token) {
            if (TokenType::T_CHAR_CLASS_OPEN === $token->type) {
                $openCount++;
            }
            if (TokenType::T_CHAR_CLASS_CLOSE === $token->type) {
                $closeCount++;
            }
            if (TokenType::T_LITERAL === $token->type && '[' === $token->value) {
                $hasLiteralOpen = true;
            }
        }

        $this->assertSame(1, $openCount);
        $this->assertSame(1, $closeCount);
        $this->assertTrue($hasLiteralOpen);
    }

    public function test_unicode_prop_empty_after_negation(): void
    {
        $tokens = (new Lexer())->tokenize('\p{^}')->getTokens();

        $this->assertSame(TokenType::T_UNICODE_PROP, $tokens[0]->type);
        $this->assertSame('', $tokens[0]->value);
    }

    public function test_unclosed_character_class_throws(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unclosed character class');

        (new Lexer())->tokenize('[abc');
    }

    /**
     * A body handed to the lexer directly can end in a lone backslash, which
     * no delimited pattern can: there the backslash escapes the delimiter.
     */
    #[DataProvider('provideBodiesEndingInABackslash')]
    public function test_a_body_ending_in_a_lone_backslash_is_refused_with_its_own_code(string $body, int $position): void
    {
        try {
            (new Lexer())->tokenize($body);
            $this->fail(\sprintf('%s must be refused.', var_export($body, true)));
        } catch (LexerException $e) {
            $this->assertSame(ErrorCode::EscapeTrailingBackslash, $e->getErrorCode());
            $this->assertSame($position, $e->getPosition());
        }
    }

    /**
     * @return iterable<string, array{body: string, position: int}>
     */
    public static function provideBodiesEndingInABackslash(): iterable
    {
        yield 'backslash alone' => ['body' => '\\', 'position' => 0];
        yield 'after a literal' => ['body' => 'a\\', 'position' => 1];
        yield 'inside a class' => ['body' => '[a\\', 'position' => 2];
    }
}

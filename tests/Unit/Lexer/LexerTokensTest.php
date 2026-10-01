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

use PhpRegex\Parser\Lexer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tokens the lexer produces, spelled out.
 *
 * These cases used to assert that some tokens came out, which is true of
 * every input. What matters is which ones: the escapes it resolves, the
 * quoted runs it keeps whole, and the negation it folds into the value of a
 * unicode property — "\P{L}" and "\p{^L}" come back as the same token.
 */
final class LexerTokensTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTokenizations')]
    public function test_a_pattern_produces_these_tokens(string $pattern, string $tokens): void
    {
        $this->assertSame($tokens, $this->tokenize($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, tokens: string}>
     */
    public static function provideTokenizations(): iterable
    {
        yield 'quote mode' => [
            'pattern' => '\\Qhello world\\E',
            'tokens' => 'T_QUOTE_MODE_START(\\Q) T_LITERAL(hello world) T_QUOTE_MODE_END(\\E) T_EOF',
        ];

        yield 'quote mode with metacharacters' => [
            'pattern' => '\\Q.*+?[]{}()\\E',
            'tokens' => 'T_QUOTE_MODE_START(\\Q) T_LITERAL(.*+?[]{}()) T_QUOTE_MODE_END(\\E) T_EOF',
        ];

        yield 'quote mode left open' => [
            'pattern' => '\\Qhello world',
            'tokens' => 'T_QUOTE_MODE_START(\\Q) T_LITERAL(hello world) T_EOF',
        ];

        yield 'empty quote mode' => [
            'pattern' => '\\Q\\E',
            'tokens' => 'T_QUOTE_MODE_START(\\Q) T_QUOTE_MODE_END(\\E) T_EOF',
        ];

        yield 'quote mode holding escapes' => [
            'pattern' => '\\Q\\n\\t\\E',
            'tokens' => 'T_QUOTE_MODE_START(\\Q) T_LITERAL(\\n\\t) T_QUOTE_MODE_END(\\E) T_EOF',
        ];

        yield 'tab escape' => [
            'pattern' => '\\t',
            'tokens' => 'T_LITERAL_ESCAPED(\\t) T_EOF',
        ];

        yield 'newline escape' => [
            'pattern' => '\\n',
            'tokens' => 'T_LITERAL_ESCAPED(\\n) T_EOF',
        ];

        yield 'carriage return escape' => [
            'pattern' => '\\r',
            'tokens' => 'T_LITERAL_ESCAPED(\\r) T_EOF',
        ];

        yield 'form feed escape' => [
            'pattern' => '\\f',
            'tokens' => 'T_LITERAL_ESCAPED(\\f) T_EOF',
        ];

        yield 'vertical tab escape' => [
            'pattern' => '\\v',
            'tokens' => 'T_CHAR_TYPE(v) T_EOF',
        ];

        yield 'escape escape' => [
            'pattern' => '\\e',
            'tokens' => 'T_LITERAL_ESCAPED(\\033) T_EOF',
        ];

        yield 'every control escape' => [
            'pattern' => '\\t\\n\\r\\f\\e',
            'tokens' => 'T_LITERAL_ESCAPED(\\t) T_LITERAL_ESCAPED(\\n) T_LITERAL_ESCAPED(\\r) T_LITERAL_ESCAPED(\\f) T_LITERAL_ESCAPED(\\033) T_EOF',
        ];

        yield 'unicode property' => [
            'pattern' => '\\p{L}',
            'tokens' => 'T_UNICODE_PROP({L}) T_EOF',
        ];

        yield 'negated unicode property' => [
            'pattern' => '\\P{L}',
            'tokens' => 'T_UNICODE_PROP({^L}) T_EOF',
        ];

        yield 'unicode property negated inside' => [
            'pattern' => '\\p{^L}',
            'tokens' => 'T_UNICODE_PROP({^L}) T_EOF',
        ];

        yield 'unicode property negated twice' => [
            'pattern' => '\\P{^L}',
            'tokens' => 'T_UNICODE_PROP({L}) T_EOF',
        ];

        yield 'short unicode property' => [
            'pattern' => '\\pL',
            'tokens' => 'T_UNICODE_PROP(L) T_EOF',
        ];

        yield 'short negated unicode property' => [
            'pattern' => '\\PL',
            'tokens' => 'T_UNICODE_PROP(^L) T_EOF',
        ];

        yield 'quote mode twice' => [
            'pattern' => '\\Qabc\\Edef\\Qghi\\E',
            'tokens' => 'T_QUOTE_MODE_START(\\Q) T_LITERAL(abc) T_QUOTE_MODE_END(\\E) T_LITERAL(d) T_LITERAL(e) T_LITERAL(f) T_QUOTE_MODE_START(\\Q) T_LITERAL(ghi) T_QUOTE_MODE_END(\\E) T_EOF',
        ];

        yield 'escaped metacharacters' => [
            'pattern' => '\\.\\*\\+\\?',
            'tokens' => 'T_LITERAL_ESCAPED(.) T_LITERAL_ESCAPED(*) T_LITERAL_ESCAPED(+) T_LITERAL_ESCAPED(?) T_EOF',
        ];

        yield 'pcre verb' => [
            'pattern' => '(*FAIL)',
            'tokens' => 'T_PCRE_VERB(FAIL) T_EOF',
        ];

        yield 'pcre verb with an argument' => [
            'pattern' => '(*MARK:foo)',
            'tokens' => 'T_PCRE_VERB(MARK:foo) T_EOF',
        ];

        yield 'uppercase letter property' => [
            'pattern' => '\\p{Lu}',
            'tokens' => 'T_UNICODE_PROP({Lu}) T_EOF',
        ];

        yield 'negated uppercase letter property' => [
            'pattern' => '\\P{Lu}',
            'tokens' => 'T_UNICODE_PROP({^Lu}) T_EOF',
        ];

        yield 'decimal digit property' => [
            'pattern' => '\\p{Nd}',
            'tokens' => 'T_UNICODE_PROP({Nd}) T_EOF',
        ];

        yield 'negated decimal digit property' => [
            'pattern' => '\\P{Nd}',
            'tokens' => 'T_UNICODE_PROP({^Nd}) T_EOF',
        ];

        yield 'currency symbol property' => [
            'pattern' => '\\p{Sc}',
            'tokens' => 'T_UNICODE_PROP({Sc}) T_EOF',
        ];

        yield 'negated currency symbol property' => [
            'pattern' => '\\P{Sc}',
            'tokens' => 'T_UNICODE_PROP({^Sc}) T_EOF',
        ];
        yield 'a verb wrapping a group' => [
            'pattern' => '(*atomic:(a))',
            'tokens' => 'T_PCRE_VERB(atomic:(a)) T_EOF',
        ];

        yield 'a verb wrapping nested groups' => [
            'pattern' => '(*atomic:((a)))',
            'tokens' => 'T_PCRE_VERB(atomic:((a))) T_EOF',
        ];

        yield 'a verb wrapping several groups' => [
            'pattern' => '(*pla:((a)b(c)))',
            'tokens' => 'T_PCRE_VERB(pla:((a)b(c))) T_EOF',
        ];
    }

    private function tokenize(string $pattern): string
    {
        $parts = [];

        foreach ((new Lexer())->tokenize($pattern)->getTokens() as $token) {
            $value = addcslashes($token->value, "\0..\37\177..\377");
            $parts[] = $token->type->name.('' === $value ? '' : '('.$value.')');
        }

        return implode(' ', $parts);
    }
}

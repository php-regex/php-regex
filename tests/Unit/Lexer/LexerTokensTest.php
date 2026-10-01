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
            'tokens' => 'QuoteModeStart(\\Q) Literal(hello world) QuoteModeEnd(\\E) Eof',
        ];

        yield 'quote mode with metacharacters' => [
            'pattern' => '\\Q.*+?[]{}()\\E',
            'tokens' => 'QuoteModeStart(\\Q) Literal(.*+?[]{}()) QuoteModeEnd(\\E) Eof',
        ];

        yield 'quote mode left open' => [
            'pattern' => '\\Qhello world',
            'tokens' => 'QuoteModeStart(\\Q) Literal(hello world) Eof',
        ];

        yield 'empty quote mode' => [
            'pattern' => '\\Q\\E',
            'tokens' => 'QuoteModeStart(\\Q) QuoteModeEnd(\\E) Eof',
        ];

        yield 'quote mode holding escapes' => [
            'pattern' => '\\Q\\n\\t\\E',
            'tokens' => 'QuoteModeStart(\\Q) Literal(\\n\\t) QuoteModeEnd(\\E) Eof',
        ];

        yield 'tab escape' => [
            'pattern' => '\\t',
            'tokens' => 'LiteralEscaped(\\t) Eof',
        ];

        yield 'newline escape' => [
            'pattern' => '\\n',
            'tokens' => 'LiteralEscaped(\\n) Eof',
        ];

        yield 'carriage return escape' => [
            'pattern' => '\\r',
            'tokens' => 'LiteralEscaped(\\r) Eof',
        ];

        yield 'form feed escape' => [
            'pattern' => '\\f',
            'tokens' => 'LiteralEscaped(\\f) Eof',
        ];

        yield 'vertical tab escape' => [
            'pattern' => '\\v',
            'tokens' => 'CharType(v) Eof',
        ];

        yield 'escape escape' => [
            'pattern' => '\\e',
            'tokens' => 'LiteralEscaped(\\033) Eof',
        ];

        yield 'every control escape' => [
            'pattern' => '\\t\\n\\r\\f\\e',
            'tokens' => 'LiteralEscaped(\\t) LiteralEscaped(\\n) LiteralEscaped(\\r) LiteralEscaped(\\f) LiteralEscaped(\\033) Eof',
        ];

        yield 'unicode property' => [
            'pattern' => '\\p{L}',
            'tokens' => 'UnicodeProp({L}) Eof',
        ];

        yield 'negated unicode property' => [
            'pattern' => '\\P{L}',
            'tokens' => 'UnicodeProp({^L}) Eof',
        ];

        yield 'unicode property negated inside' => [
            'pattern' => '\\p{^L}',
            'tokens' => 'UnicodeProp({^L}) Eof',
        ];

        yield 'unicode property negated twice' => [
            'pattern' => '\\P{^L}',
            'tokens' => 'UnicodeProp({L}) Eof',
        ];

        yield 'short unicode property' => [
            'pattern' => '\\pL',
            'tokens' => 'UnicodeProp(L) Eof',
        ];

        yield 'short negated unicode property' => [
            'pattern' => '\\PL',
            'tokens' => 'UnicodeProp(^L) Eof',
        ];

        yield 'quote mode twice' => [
            'pattern' => '\\Qabc\\Edef\\Qghi\\E',
            'tokens' => 'QuoteModeStart(\\Q) Literal(abc) QuoteModeEnd(\\E) Literal(d) Literal(e) Literal(f) QuoteModeStart(\\Q) Literal(ghi) QuoteModeEnd(\\E) Eof',
        ];

        yield 'escaped metacharacters' => [
            'pattern' => '\\.\\*\\+\\?',
            'tokens' => 'LiteralEscaped(.) LiteralEscaped(*) LiteralEscaped(+) LiteralEscaped(?) Eof',
        ];

        yield 'pcre verb' => [
            'pattern' => '(*FAIL)',
            'tokens' => 'PcreVerb(FAIL) Eof',
        ];

        yield 'pcre verb with an argument' => [
            'pattern' => '(*MARK:foo)',
            'tokens' => 'PcreVerb(MARK:foo) Eof',
        ];

        yield 'uppercase letter property' => [
            'pattern' => '\\p{Lu}',
            'tokens' => 'UnicodeProp({Lu}) Eof',
        ];

        yield 'negated uppercase letter property' => [
            'pattern' => '\\P{Lu}',
            'tokens' => 'UnicodeProp({^Lu}) Eof',
        ];

        yield 'decimal digit property' => [
            'pattern' => '\\p{Nd}',
            'tokens' => 'UnicodeProp({Nd}) Eof',
        ];

        yield 'negated decimal digit property' => [
            'pattern' => '\\P{Nd}',
            'tokens' => 'UnicodeProp({^Nd}) Eof',
        ];

        yield 'currency symbol property' => [
            'pattern' => '\\p{Sc}',
            'tokens' => 'UnicodeProp({Sc}) Eof',
        ];

        yield 'negated currency symbol property' => [
            'pattern' => '\\P{Sc}',
            'tokens' => 'UnicodeProp({^Sc}) Eof',
        ];
        yield 'a verb wrapping a group' => [
            'pattern' => '(*atomic:(a))',
            'tokens' => 'PcreVerb(atomic:(a)) Eof',
        ];

        yield 'a verb wrapping nested groups' => [
            'pattern' => '(*atomic:((a)))',
            'tokens' => 'PcreVerb(atomic:((a))) Eof',
        ];

        yield 'a verb wrapping several groups' => [
            'pattern' => '(*pla:((a)b(c)))',
            'tokens' => 'PcreVerb(pla:((a)b(c))) Eof',
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

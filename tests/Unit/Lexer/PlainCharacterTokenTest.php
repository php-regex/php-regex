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
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Token\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A character that can only be text where it stands is one literal token:
 * a byte without UTF-8, a whole character with it, whatever mode, class or
 * scope surrounds it.
 */
final class PlainCharacterTokenTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $expected type, value, offset, bytes read
     */
    #[Test]
    #[DataProvider('provideTokens')]
    public function test_the_tokens_of_a_pattern(string $pattern, array $expected, int|string $phpVersion = 'runtime'): void
    {
        $tokens = RegexParser::create(['cache' => null, 'php_version' => $phpVersion])->tokenize($pattern);

        $this->assertSame($expected, array_map(
            static fn (Token $token): array => [$token->type->value, $token->value, $token->position, $token->sourceLength],
            $tokens,
        ));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<array{0: string, 1: string, 2: int, 3: int}>, phpVersion?: int|string}>
     */
    public static function provideTokens(): iterable
    {
        yield 'plain punctuation' => ['pattern' => '~ !"%&\',-/:;<=>@_`~', 'expected' => [
            ['literal', ' ', 0, 1], ['literal', '!', 1, 1], ['literal', '"', 2, 1], ['literal', '%', 3, 1],
            ['literal', '&', 4, 1], ['literal', "'", 5, 1], ['literal', ',', 6, 1], ['literal', '-', 7, 1],
            ['literal', '/', 8, 1], ['literal', ':', 9, 1], ['literal', ';', 10, 1], ['literal', '<', 11, 1],
            ['literal', '=', 12, 1], ['literal', '>', 13, 1], ['literal', '@', 14, 1], ['literal', '_', 15, 1],
            ['literal', '`', 16, 1], ['eof', '', 17, 0],
        ]];
        yield 'characters of every UTF-8 length under u' => ['pattern' => "/x\u{E9}\u{20AC}\u{1F600}/u", 'expected' => [
            ['literal', 'x', 0, 1], ['literal', "\u{E9}", 1, 2], ['literal', "\u{20AC}", 3, 3], ['literal', "\u{1F600}", 6, 4], ['eof', '', 10, 0],
        ]];
        yield 'valid UTF-8 without u is read a character at a time' => ['pattern' => "/\u{E9}a/", 'expected' => [
            ['literal', "\u{E9}", 0, 2], ['literal', 'a', 2, 1], ['eof', '', 3, 0],
        ]];
        yield 'bytes that are no UTF-8 are read one by one' => ['pattern' => "/a\xE9\xFF\xC3b/", 'expected' => [
            ['literal', 'a', 0, 1], ['literal', "\xE9", 1, 1], ['literal', "\xFF", 2, 1], ['literal', "\xC3", 3, 1], ['literal', 'b', 4, 1], ['eof', '', 5, 0],
        ]];
        yield 'text before and after what the token regex reads' => ['pattern' => '/ab{2}c{x|d.e$/', 'expected' => [
            ['literal', 'a', 0, 1], ['literal', 'b', 1, 1], ['quantifier', '{2}', 2, 3], ['literal', 'c', 5, 1], ['literal', '{', 6, 1],
            ['literal', 'x', 7, 1], ['alternation', '|', 8, 1], ['literal', 'd', 9, 1], ['dot', '.', 10, 1], ['literal', 'e', 11, 1],
            ['anchor', '$', 12, 1], ['eof', '', 13, 0],
        ]];
        yield 'a hyphen and a caret in a class' => ['pattern' => '/[^a-z]-/', 'expected' => [
            ['char_class_open', '[', 0, 1], ['negation', '^', 1, 1], ['literal', 'a', 2, 1], ['range', '-', 3, 1], ['literal', 'z', 4, 1],
            ['char_class_close', ']', 5, 1], ['literal', '-', 6, 1], ['eof', '', 7, 0],
        ]];
        yield 'spaces and a comment under x' => ['pattern' => "/a b # c\nd/x", 'expected' => [
            ['literal', 'a', 0, 1], ['literal', ' ', 1, 1], ['literal', 'b', 2, 1], ['literal', ' ', 3, 1], ['literal', '#', 4, 1],
            ['literal', ' c', 5, 2], ['literal', "\n", 7, 1], ['literal', 'd', 8, 1], ['eof', '', 9, 0],
        ]];
        yield 'x turned on and off inside the pattern' => ['pattern' => "/(?x)a #b\n(?-x)c #d/", 'expected' => [
            ['group_modifier_open', '(?', 0, 2], ['literal', 'x', 2, 1], ['group_close', ')', 3, 1], ['literal', 'a', 4, 1], ['literal', ' ', 5, 1],
            ['literal', '#', 6, 1], ['literal', 'b', 7, 1], ['literal', "\n", 8, 1], ['group_modifier_open', '(?', 9, 2], ['literal', '-', 11, 1],
            ['literal', 'x', 12, 1], ['group_close', ')', 13, 1], ['literal', 'c', 14, 1], ['literal', ' ', 15, 1], ['literal', '#', 16, 1],
            ['literal', 'd', 17, 1], ['eof', '', 18, 0],
        ]];
        yield 'a group name is text after its opener' => ['pattern' => '/(?<n>a)/', 'expected' => [
            ['group_modifier_open', '(?', 0, 2], ['literal', '<', 2, 1], ['literal', 'n', 3, 1], ['literal', '>', 4, 1], ['literal', 'a', 5, 1],
            ['group_close', ')', 6, 1], ['eof', '', 7, 0],
        ]];
        yield 'quoted and commented text' => ['pattern' => '/\Qa.b\Ec(?#d)e/', 'expected' => [
            ['quote_mode_start', '\Q', 0, 2], ['literal', 'a.b', 2, 3], ['quote_mode_end', '\E', 5, 2], ['literal', 'c', 7, 1],
            ['comment_open', '(?#', 8, 3], ['literal', 'd', 11, 1], ['group_close', ')', 12, 1], ['literal', 'e', 13, 1], ['eof', '', 14, 0],
        ]];
        yield 'PHP 7.3 reads an unknown escape as its letter' => ['pattern' => '/a\yb/', 'expected' => [
            ['literal', 'a', 0, 1], ['literal_escaped', 'y', 1, 2], ['literal', 'b', 3, 1], ['eof', '', 4, 0],
        ], 'phpVersion' => 70300];
    }

    #[Test]
    public function test_a_byte_that_is_no_utf8_is_refused_under_u_where_it_stands(): void
    {
        try {
            RegexParser::create(['cache' => null])->tokenize("/ab\xE9c/u");
            $this->fail('Expected the pattern to be refused.');
        } catch (LexerException $e) {
            $this->assertSame(ErrorCode::EncodingInvalidUtf8, $e->errorCode);
            $this->assertSame(2, $e->position);
        }
    }
}

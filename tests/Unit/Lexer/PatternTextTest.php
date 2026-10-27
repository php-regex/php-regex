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
use PHPRegex\Parser\Lexing\PatternText;
use PHPRegex\Parser\Lexing\TargetSyntax;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The text a lexer reads, with what PCRE reads it as: bytes or UTF-8, the
 * newline convention of the whole pattern, and the searches the readers of
 * the text share.
 */
final class PatternTextTest extends TestCase
{
    #[Test]
    public function test_a_text_that_is_no_utf8_is_read_byte_by_byte(): void
    {
        $text = self::text("a\xFF");

        $this->assertTrue($text->byteMode);
        $this->assertFalse($text->utf);
        $this->assertSame(2, $text->length);
    }

    #[Test]
    public function test_utf_mode_comes_from_the_flags_or_the_leading_setting(): void
    {
        $this->assertTrue(self::text('é', 'u')->utf);
        $this->assertTrue(self::text('(*UTF)é')->utf);
        $this->assertFalse(self::text('é')->utf);
        $this->assertFalse(self::text('é')->byteMode);
    }

    /**
     * PCRE refuses a pattern that is no UTF-8 in UTF mode at the first byte
     * that starts no UTF-8 character: "/a\xFFb/u" fails to compile.
     */
    #[Test]
    #[DataProvider('provideTextsThatAreNoUtf8')]
    public function test_a_text_that_is_no_utf8_is_refused_in_utf_mode_at_its_first_bad_byte(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match('/'.$pattern.'/u', ''));

        try {
            self::text($pattern, 'u');
            $this->fail('A text that is no UTF-8 was read in UTF mode.');
        } catch (LexerException $exception) {
            $this->assertSame(ErrorCode::EncodingInvalidUtf8, $exception->errorCode);
            $this->assertSame($offset, $exception->position);
            $this->assertSame('Input string is not valid UTF-8.', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideTextsThatAreNoUtf8(): iterable
    {
        yield 'a byte no UTF-8 holds' => ['pattern' => "a\xFFb", 'offset' => 1];
        yield 'an overlong form' => ['pattern' => "ab\xC0\x80", 'offset' => 2];
    }

    /**
     * @param array{0: int, 1: int}|null $expected
     */
    #[Test]
    #[DataProvider('provideNewlines')]
    public function test_the_next_newline_is_the_one_the_leading_options_set(string $pattern, int $from, ?array $expected): void
    {
        $this->assertSame($expected, self::text($pattern)->nextNewline($from));
    }

    /**
     * @return iterable<string, array{pattern: string, from: int, expected: array{0: int, 1: int}|null}>
     */
    public static function provideNewlines(): iterable
    {
        yield 'LF by default' => ['pattern' => "a\rb\nc", 'from' => 0, 'expected' => [3, 1]];
        yield 'CR' => ['pattern' => "(*CR)a\nb\rc", 'from' => 5, 'expected' => [8, 1]];
        yield 'CRLF only as a pair' => ['pattern' => "(*CRLF)a\rb\r\nc", 'from' => 7, 'expected' => [10, 2]];
        yield 'NUL' => ['pattern' => "(*NUL)a\nb\0c", 'from' => 6, 'expected' => [9, 1]];
        yield 'ANYCRLF' => ['pattern' => "(*ANYCRLF)a\fb\rc", 'from' => 10, 'expected' => [13, 1]];
        yield 'ANY, U+2028 in UTF mode' => ['pattern' => "(*UTF)(*ANY)a\u{2028}b", 'from' => 12, 'expected' => [13, 3]];
        yield 'ANY, the byte 0x85 outside UTF mode' => ['pattern' => "(*ANY)a\xC3\x85b", 'from' => 6, 'expected' => [8, 1]];
        yield 'none left' => ['pattern' => "a\nb", 'from' => 2, 'expected' => null];
    }

    /**
     * The newline convention and UTF mode are those of the whole pattern,
     * not of the part of it read.
     */
    #[Test]
    public function test_a_part_reads_the_newline_convention_of_the_whole_pattern(): void
    {
        $whole = "(*CR)(*pla:a\rb)";
        $text = new PatternText(new TargetSyntax(PcreTarget::runtime()), "a\rb", '', $whole, 11);

        $this->assertSame([1, 1], $text->nextNewline(0));
        $this->assertSame(11, $text->offset);
    }

    #[Test]
    public function test_follows_finds_a_byte_from_any_offset(): void
    {
        $text = self::text('a}b}c');

        $this->assertTrue($text->follows('}', 0));
        $this->assertTrue($text->follows('}', 2));
        $this->assertTrue($text->follows('}', 3));
        $this->assertFalse($text->follows('}', 4));
        $this->assertTrue($text->follows('}', 1));
        $this->assertFalse($text->follows(')', 0));
    }

    #[Test]
    public function test_an_unclosed_property_is_one_no_brace_closes(): void
    {
        $text = self::text('\p{L \p{N}');

        $this->assertFalse($text->opensUnclosedProperty(0));
        $this->assertFalse($text->opensUnclosedProperty(5));
        $this->assertTrue(self::text('\P{L')->opensUnclosedProperty(0));
        $this->assertFalse(self::text('\pL')->opensUnclosedProperty(0));
    }

    #[Test]
    public function test_the_inline_flags_after_an_opener_say_how_far_they_reach(): void
    {
        $text = self::text('(?x)a(?i-x:b)(?<n>c)');

        $set = $text->inlineFlags(2);
        $this->assertNotNull($set);
        $this->assertTrue($set[0]->inForce('x', false));
        $this->assertFalse($set[1]);
        $this->assertSame(4, $set[2]);

        $scoped = $text->inlineFlags(7);
        $this->assertNotNull($scoped);
        $this->assertFalse($scoped[0]->inForce('x', true));
        $this->assertTrue($scoped[1]);
        $this->assertSame(11, $scoped[2]);

        $this->assertNull($text->inlineFlags(15));
    }

    /**
     * PHP 7.3 and 7.4 compile without "X" with
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL; 8.0 and later do not.
     */
    #[Test]
    public function test_bad_escapes_are_literal_on_php_7_without_the_x_flag(): void
    {
        $php73 = new TargetSyntax(PcreTarget::bundledWith(70300));

        $this->assertTrue((new PatternText($php73, 'a', '', 'a', 0))->badEscapeIsLiteral);
        $this->assertFalse((new PatternText($php73, 'a', 'X', 'a', 0))->badEscapeIsLiteral);
        $this->assertFalse((new PatternText(new TargetSyntax(PcreTarget::bundledWith(80000)), 'a', '', 'a', 0))->badEscapeIsLiteral);
    }

    #[Test]
    public function test_the_syntax_of_a_target_is_read_once_from_its_features(): void
    {
        $old = PcreTarget::bundledWith(70300);
        $syntax = new TargetSyntax($old);

        $this->assertSame($old, $syntax->target);
        $this->assertSame($old->phpVersionId, $syntax->phpVersionId);
        $this->assertSame($old->supports(PcreFeature::AlphabeticAssertionNames), $syntax->readsAlphabeticAssertions);
        $this->assertSame(!$old->supports(PcreFeature::BadEscapeInClassStaysRefused), $syntax->classBadEscapeReadAsLetter);
        $this->assertFalse($syntax->readsExtendedClass);
        $this->assertTrue((new TargetSyntax(PcreTarget::bundledWith(80500)))->namesReadWhole);
    }

    private static function text(string $pattern, string $flags = ''): PatternText
    {
        return new PatternText(new TargetSyntax(PcreTarget::runtime()), $pattern, $flags, $pattern, 0);
    }
}

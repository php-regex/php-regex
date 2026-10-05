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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\Rule\Support\CodePoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The code point a hexadecimal escape spells: "\xHH" and "\x{H...}" as PCRE
 * reads them, and the "\uHHHH" and "\u{H...}" spellings of other engines,
 * which PCRE itself refuses without its ALT_BSUX option. Anything else is
 * no such escape.
 */
final class CodePointsEscapeTest extends TestCase
{
    #[Test]
    #[DataProvider('providePcreEscapes')]
    public function test_parse_unicode_escape_reads_the_code_point_pcre_matches(string $escape, int $codePoint): void
    {
        $this->assertSame(1, preg_match('/^'.$escape.'$/u', mb_chr($codePoint, 'UTF-8')), \sprintf('Oracle: %s is U+%04X.', $escape, $codePoint));

        $this->assertSame($codePoint, CodePoints::parseUnicodeEscape($escape));
    }

    /**
     * @return iterable<string, array{escape: string, codePoint: int}>
     */
    public static function providePcreEscapes(): iterable
    {
        yield 'two hex digits' => ['escape' => '\\x41', 'codePoint' => 0x41];
        yield 'two hex digits, lowercase' => ['escape' => '\\xe9', 'codePoint' => 0xE9];
        yield 'braced, Kelvin sign' => ['escape' => '\\x{212A}', 'codePoint' => 0x212A];
        yield 'braced, beyond the BMP' => ['escape' => '\\x{1F600}', 'codePoint' => 0x1F600];
        yield 'braced, one digit' => ['escape' => '\\x{0}', 'codePoint' => 0];
    }

    #[Test]
    #[DataProvider('provideOtherEngineEscapes')]
    public function test_parse_unicode_escape_reads_the_u_spellings(string $escape, int $codePoint): void
    {
        $this->assertFalse(@preg_match('/'.$escape.'/u', ''), \sprintf('Oracle: PCRE refuses %s.', $escape));

        $this->assertSame($codePoint, CodePoints::parseUnicodeEscape($escape));
    }

    /**
     * @return iterable<string, array{escape: string, codePoint: int}>
     */
    public static function provideOtherEngineEscapes(): iterable
    {
        yield 'four hex digits' => ['escape' => '\\u00E9', 'codePoint' => 0xE9];
        yield 'braced' => ['escape' => '\\u{1F600}', 'codePoint' => 0x1F600];
    }

    #[Test]
    #[DataProvider('provideNonEscapes')]
    public function test_parse_unicode_escape_refuses_what_is_no_hex_escape(string $escape): void
    {
        $this->assertNull(CodePoints::parseUnicodeEscape($escape));
    }

    /**
     * @return iterable<string, array{escape: string}>
     */
    public static function provideNonEscapes(): iterable
    {
        yield 'one hex digit' => ['escape' => '\\x4'];
        yield 'three hex digits' => ['escape' => '\\x414'];
        yield 'not hex' => ['escape' => '\\xZZ'];
        yield 'empty braces' => ['escape' => '\\x{}'];
        yield 'unclosed braces' => ['escape' => '\\x{41'];
        yield 'three digits after u' => ['escape' => '\\u00E'];
        yield 'octal' => ['escape' => '\\101'];
        yield 'text after the escape' => ['escape' => '\\x41a'];
        yield 'no backslash' => ['escape' => 'x41'];
    }
}

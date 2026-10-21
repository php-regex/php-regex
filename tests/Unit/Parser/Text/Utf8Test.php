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

namespace PHPRegex\Tests\Unit\Parser\Text;

use PHPRegex\Parser\Text\Utf8;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How many bytes the UTF-8 character at an offset takes, read from its
 * first byte: 1 for a byte that starts none, so a walk always moves on.
 */
final class Utf8Test extends TestCase
{
    #[Test]
    #[DataProvider('provideCharacters')]
    public function test_width_is_the_length_of_the_character_at_the_offset(string $text, int $at, int $width): void
    {
        $this->assertSame($width, Utf8::width($text, $at));
    }

    /**
     * How many bytes UTF-8 writes a code point in, at each boundary.
     */
    #[Test]
    public function test_the_encoded_length_of_a_code_point_follows_its_range(): void
    {
        foreach ([0x00 => 1, 0x7F => 1, 0x80 => 2, 0x7FF => 2, 0x800 => 3, 0xFFFF => 3, 0x10000 => 4, 0x10FFFF => 4] as $codePoint => $length) {
            $this->assertSame($length, Utf8::encodedLength($codePoint), \sprintf('U+%04X', $codePoint));
            $this->assertSame(\strlen((string) mb_chr($codePoint, 'UTF-8')), Utf8::encodedLength($codePoint), 'Oracle: mb_chr() writes it so.');
        }
    }

    #[Test]
    public function test_a_continuation_byte_is_one_of_0x80_to_0xbf(): void
    {
        $this->assertTrue(Utf8::continues("\x80"));
        $this->assertTrue(Utf8::continues("\xBF"));
        $this->assertFalse(Utf8::continues("\x7F"));
        $this->assertFalse(Utf8::continues("\xC0"));
    }

    /**
     * @return iterable<string, array{text: string, at: int, width: int}>
     */
    public static function provideCharacters(): iterable
    {
        yield 'ASCII' => ['text' => 'ab', 'at' => 1, 'width' => 1];
        yield 'two bytes' => ['text' => "a\u{E9}", 'at' => 1, 'width' => 2];
        yield 'three bytes' => ['text' => "\u{20AC}", 'at' => 0, 'width' => 3];
        yield 'four bytes' => ['text' => "\u{1F600}", 'at' => 0, 'width' => 4];
        yield 'the lowest two-byte lead' => ['text' => "\xC0\x80", 'at' => 0, 'width' => 2];
        yield 'the lowest three-byte lead' => ['text' => "\xE0\xA0\x80", 'at' => 0, 'width' => 3];
        yield 'the lowest four-byte lead' => ['text' => "\xF0\x90\x80\x80", 'at' => 0, 'width' => 4];
        yield 'the highest one-byte character' => ['text' => "\x7F", 'at' => 0, 'width' => 1];
        yield 'a continuation byte' => ['text' => "\u{E9}", 'at' => 1, 'width' => 1];
        yield 'the highest continuation byte' => ['text' => "\xBF", 'at' => 0, 'width' => 1];
        yield 'past the end' => ['text' => 'a', 'at' => 1, 'width' => 1];
    }
}

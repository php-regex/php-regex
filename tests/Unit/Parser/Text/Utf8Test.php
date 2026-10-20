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

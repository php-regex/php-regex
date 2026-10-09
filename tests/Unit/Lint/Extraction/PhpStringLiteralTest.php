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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A string literal is read as PHP reads it: each expected value below is
 * the one PHP compiled from the same literal in this file.
 */
final class PhpStringLiteralTest extends TestCase
{
    /**
     * @return iterable<string, array{literal: string, expected: string}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'an unknown escape is kept, regex escapes with it' => ['literal' => '"/\d+\.x\/y/"', 'expected' => "/\d+\.x\/y/"];
        yield 'the control escapes' => ['literal' => '"\n\t\r\v\e\f"', 'expected' => "\n\t\r\v\e\f"];
        yield 'backslash, dollar and quote' => ['literal' => '"\\\\ \$ \""', 'expected' => '\\ $ "'];
        yield 'a single quote is no escape in double quotes' => ['literal' => '"\\\'"', 'expected' => "\\'"];
        yield 'octal' => ['literal' => '"\101\0\7"', 'expected' => "\101\0\7"];
        yield 'an 8 is no octal digit' => ['literal' => '"\8"', 'expected' => "\8"];
        yield 'hexadecimal, one or two digits' => ['literal' => '"\x41\x4"', 'expected' => "\x41\x4"];
        yield 'a \x with no digit is kept' => ['literal' => '"\xZZ"', 'expected' => "\xZZ"];
        yield 'a \x{ is no PHP escape' => ['literal' => '"\x{41}"', 'expected' => "\x{41}"];
        yield 'unicode' => ['literal' => '"\u{1F600}\u{41}\u{e9}"', 'expected' => "\u{1F600}\u{41}\u{e9}"];
        yield 'unicode at each UTF-8 length, a surrogate included' => ['literal' => '"\u{7F}\u{80}\u{7FF}\u{800}\u{D800}\u{FFFF}\u{10000}\u{10FFFF}"', 'expected' => "\u{7F}\u{80}\u{7FF}\u{800}\u{D800}\u{FFFF}\u{10000}\u{10FFFF}"];
        yield 'a \u{} PHP refuses to compile, in a half-typed file, is kept as written' => ['literal' => '"\u{}\u{zz}\u{41"', 'expected' => '\u{}\u{zz}\u{41'];
        yield 'octal past \377 keeps its low byte' => ['literal' => '"\400\777"', 'expected' => "\0\377"];
        yield 'a \u with no brace is kept' => ['literal' => '"\u00e9"', 'expected' => "\u00e9"];
        yield 'a brace is kept' => ['literal' => '"\{"', 'expected' => "\{"];
        yield 'single quotes read \\\\ and \\\' only' => ['literal' => "'/\\d\\'\\\\x\\n/'", 'expected' => '/\d\'\\x\n/'];
    }

    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_a_literal_is_read_as_php_reads_it(string $literal, string $expected): void
    {
        $this->assertSame($expected, PhpStringLiteral::decode($literal));
    }

    #[Test]
    public function test_a_token_that_is_no_quoted_literal_is_not_read(): void
    {
        $this->assertNull(PhpStringLiteral::decode('x'));
        $this->assertNull(PhpStringLiteral::decode('abc'));
        $this->assertNull(PhpStringLiteral::decode('"'));
    }
}

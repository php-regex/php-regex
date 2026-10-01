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

namespace PhpRegex\Tests\Unit\Internal;

use PhpRegex\Parser\Internal\CodePointReader;
use PhpRegex\Parser\Node\CharLiteralType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every way PCRE lets a pattern name a character, and what it names.
 */
final class CodePointReaderTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEscapes')]
    public function test_an_escape_names_a_code_point(string $escape, CharLiteralType $type, int $codePoint): void
    {
        $this->assertSame($codePoint, CodePointReader::fromLiteral($escape, $type));
    }

    /**
     * @return iterable<string, array{escape: string, type: \PhpRegex\Parser\Node\CharLiteralType, codePoint: int}>
     */
    public static function provideEscapes(): iterable
    {
        yield 'two hex digits' => ['escape' => '\xFF', 'type' => CharLiteralType::Unicode, 'codePoint' => 255];
        yield 'four hex digits' => ['escape' => '\\u0041', 'type' => CharLiteralType::Unicode, 'codePoint' => 65];
        yield 'braced hex' => ['escape' => '\x{1F600}', 'type' => CharLiteralType::Unicode, 'codePoint' => 128512];
        yield 'braced hex with u' => ['escape' => '\u{1F600}', 'type' => CharLiteralType::Unicode, 'codePoint' => 128512];
        yield 'braced octal' => ['escape' => '\o{101}', 'type' => CharLiteralType::Octal, 'codePoint' => 65];
        yield 'legacy octal' => ['escape' => '\101', 'type' => CharLiteralType::OctalLegacy, 'codePoint' => 65];
        yield 'a named code point' => [
            'escape' => '\N{U+0041}',
            'type' => CharLiteralType::UnicodeNamed,
            'codePoint' => 65,
        ];

        // Nothing readable: the escape keeps its spelling in the tree and the
        // validator decides whether it is an error.
        yield 'not an escape at all' => ['escape' => 'invalid', 'type' => CharLiteralType::Unicode, 'codePoint' => -1];
        yield 'a name that is not one' => [
            'escape' => '\N{NOT A CHARACTER NAME}',
            'type' => CharLiteralType::UnicodeNamed,
            'codePoint' => -1,
        ];
        yield 'octal digits out of range' => [
            'escape' => '\o{9}',
            'type' => CharLiteralType::Octal,
            'codePoint' => -1,
        ];
    }

    #[Test]
    public function test_a_unicode_name_is_resolved_when_intl_can(): void
    {
        if (!class_exists(\IntlChar::class)) {
            $this->markTestSkipped('intl is required to resolve a character by name.');
        }

        $this->assertSame(97, CodePointReader::fromNamedEscape('\N{LATIN SMALL LETTER A}'));
    }

    #[Test]
    #[DataProvider('provideControlChars')]
    public function test_a_control_escape_flips_bit_six(string $letter, int $codePoint): void
    {
        $this->assertSame($codePoint, CodePointReader::fromControlChar($letter));
    }

    /**
     * @return iterable<string, array{letter: string, codePoint: int}>
     */
    public static function provideControlChars(): iterable
    {
        yield 'cA is SOH' => ['letter' => 'A', 'codePoint' => 1];
        yield 'cX is CAN' => ['letter' => 'X', 'codePoint' => 24];
        yield 'the letter case does not matter' => ['letter' => 'x', 'codePoint' => 24];
        yield 'nothing to flip' => ['letter' => '', 'codePoint' => -1];
    }
}

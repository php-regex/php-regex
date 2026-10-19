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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Explain\Vocabulary;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\QuantifierType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The words both explanations describe a construct with, read on the forms
 * a parsed pattern rarely hands them.
 */
final class ExplainVocabularyTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_literal_names_a_blank_or_a_control_character(string $value, string $expected): void
    {
        $this->assertSame($expected, Vocabulary::literal($value));
    }

    /**
     * @return iterable<string, array{value: string, expected: string}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'empty' => ['value' => '', 'expected' => "'' (empty string)"];
        yield 'carriage return' => ['value' => "\r", 'expected' => "'\\r' (carriage return)"];
        yield 'form feed' => ['value' => "\f", 'expected' => "'\\f' (form feed)"];
        yield 'bell' => ['value' => "\x07", 'expected' => "'\\a' (bell)"];
        yield 'escape' => ['value' => "\x1B", 'expected' => "'\\e' (escape)"];
        yield 'delete' => ['value' => "\x7F", 'expected' => "'\\x7F'"];
        yield 'a high byte' => ['value' => "\xE9", 'expected' => "'\\xE9'"];
    }

    #[Test]
    public function test_unicode_property_negates_the_greek_block_in_its_own_words(): void
    {
        $this->assertSame('A character in the Greek block (block)', Vocabulary::unicodeProperty('ingreek', false));
        $this->assertSame('Any character except one in the Greek block (block)', Vocabulary::unicodeProperty('ingreek', true));
        $this->assertSame('Any character except an uppercase letter (category)', Vocabulary::unicodeProperty('lu', true));
        $this->assertNull(Vocabulary::unicodeProperty('greek', false));
    }

    #[Test]
    #[DataProvider('provideHexValues')]
    public function test_unicode_hex_value_reads_each_spelling(string $representation, string $expected): void
    {
        $this->assertSame($expected, Vocabulary::unicodeHexValue(new CharLiteralNode($representation, 0, CharLiteralType::Unicode, 0, 0)));
    }

    /**
     * @return iterable<string, array{representation: string, expected: string}>
     */
    public static function provideHexValues(): iterable
    {
        yield 'two digits' => ['representation' => '\xe9', 'expected' => 'E9'];
        yield 'braced' => ['representation' => '\x{1f600}', 'expected' => '1F600'];
        yield 'four digits after u' => ['representation' => '\\u00e9', 'expected' => '00E9'];
        yield 'one raw byte' => ['representation' => "\n", 'expected' => '0A'];
        yield 'anything else, upper case' => ['representation' => 'abc', 'expected' => 'ABC'];
    }

    #[Test]
    public function test_octal_value_reads_braced_and_legacy_codes(): void
    {
        $this->assertSame('0101', Vocabulary::octalValue(new CharLiteralNode('\o{101}', 65, CharLiteralType::Octal, 0, 0)));
        $this->assertSame('0101', Vocabulary::octalValue(new CharLiteralNode('\101', 65, CharLiteralType::Octal, 0, 0)));
        $this->assertSame('012', Vocabulary::legacyOctalValue('\012'));
    }

    #[Test]
    public function test_quantifier_reads_a_count_it_cannot_parse_as_written(): void
    {
        $this->assertSame('with quantifier {x}', Vocabulary::quantifier('{x}', QuantifierType::Greedy));
    }
}

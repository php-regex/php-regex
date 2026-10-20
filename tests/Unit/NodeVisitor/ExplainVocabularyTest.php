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
    public function test_every_property_named_is_one_the_engine_takes(): void
    {
        // The newest PCRE2 knows every name an older release knew.
        foreach (array_keys(Vocabulary::UNICODE_PROPERTIES) as $name) {
            $this->assertNotFalse(@preg_match('/\p{'.$name.'}/u', ''), $name);
        }
    }

    #[Test]
    #[DataProvider('provideProperties')]
    public function test_unicode_property_says_what_the_engine_matches(string $name, string $description, string $subject, int $matches): void
    {
        $this->assertSame($matches, preg_match('/^\p{'.$name.'}$/u', $subject), 'engine');
        $this->assertSame($description, Vocabulary::unicodeProperty(strtolower($name), false));
    }

    /**
     * @return iterable<string, array{name: string, description: string, subject: string, matches: int}>
     */
    public static function provideProperties(): iterable
    {
        // A letter beyond ASCII, which "[a-z]" said it was not.
        yield 'lower' => ['name' => 'lower', 'description' => 'A lowercase character (the Unicode Lowercase property)', 'subject' => 'é', 'matches' => 1];
        yield 'lowercase, by its long name' => ['name' => 'Lowercase', 'description' => 'A lowercase character (the Unicode Lowercase property)', 'subject' => 'ª', 'matches' => 1];
        yield 'upper' => ['name' => 'upper', 'description' => 'An uppercase character (the Unicode Uppercase property)', 'subject' => 'Ⅰ', 'matches' => 1];
        yield 'alpha' => ['name' => 'alpha', 'description' => 'An alphabetic character (the Unicode Alphabetic property)', 'subject' => 'Ⅰ', 'matches' => 1];
        yield 'ascii' => ['name' => 'ascii', 'description' => 'An ASCII character: [\x00-\x7F]', 'subject' => 'é', 'matches' => 0];
        yield 'space' => ['name' => 'space', 'description' => 'A whitespace character (the Unicode White_Space property)', 'subject' => "\u{2028}", 'matches' => 1];
        yield 'white space, spelled with an underscore' => ['name' => 'White_Space', 'description' => 'A whitespace character (the Unicode White_Space property)', 'subject' => "\u{85}", 'matches' => 1];
        yield 'an uppercase letter' => ['name' => 'Lu', 'description' => 'An uppercase letter (category)', 'subject' => 'ǅ', 'matches' => 0];
        yield 'a currency symbol' => ['name' => 'Sc', 'description' => 'A currency symbol (category)', 'subject' => '€', 'matches' => 1];
    }

    #[Test]
    #[DataProvider('provideRefusedNames')]
    public function test_a_name_the_engine_refuses_is_described_by_none(string $name): void
    {
        $this->assertFalse(@preg_match('/\p{'.$name.'}/u', ''), $name);
        $this->assertNull(Vocabulary::unicodeProperty($name, false));
    }

    /**
     * @return iterable<string, array{name: string}>
     */
    public static function provideRefusedNames(): iterable
    {
        foreach (['javalowercase', 'javauppercase', 'javawhitespace', 'javamirrored', 'islatin', 'ingreek', 'isalphabetic', 'digit', 'alnum', 'punct', 'graph', 'print', 'blank', 'cntrl', 'xdigit'] as $name) {
            yield $name => ['name' => $name];
        }
    }

    #[Test]
    public function test_unicode_property_negates_its_description(): void
    {
        $this->assertSame('Any character except an uppercase letter (category)', Vocabulary::unicodeProperty('lu', true));
        $this->assertSame('Any character except a whitespace character (the Unicode White_Space property)', Vocabulary::unicodeProperty('white-space', true));
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

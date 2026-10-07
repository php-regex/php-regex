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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\ReportSpelling;
use PHPRegex\Tests\Unit\Cli\LintBaselineTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a report spells one line of a pattern and the text it puts in XML.
 */
final class ReportSpellingTest extends TestCase
{
    /**
     * Every delimiter a line is tried with, so that a line holding them all
     * can be read with none of them.
     */
    private const EVERY_DELIMITER = '/#~!%@;,|`=&"\':-_';

    /**
     * Every control byte a line is tried with next, then each spelled
     * "\xHH" in display form.
     */
    private const EVERY_CONTROL_DELIMITER = "\x01\x02\x03\x04\x05\x06\x07\x08\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private const EVERY_CONTROL_DELIMITER_SPELLED = '\x01\x02\x03\x04\x05\x06\x07\x08\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F';

    #[Test]
    #[DataProvider('provideFragments')]
    public function test_display_fragment_spells_the_line_in_the_mode_of_its_pattern(string $fragment, ?string $pattern, string $expected): void
    {
        $this->assertSame($expected, ReportSpelling::displayFragment($fragment, $pattern));
    }

    /**
     * @return iterable<string, array{fragment: string, pattern: string|null, expected: string}>
     */
    public static function provideFragments(): iterable
    {
        // A line cut after a lone backslash: the backslash is kept as the
        // last character, and the line before it is still read in the
        // pattern's mode, here byte by byte.
        yield 'a trailing backslash, byte mode' => [
            'fragment' => "\u{202E}\\",
            'pattern' => '/x/',
            'expected' => '\xE2\x80\xAE\\',
        ];
        yield 'a trailing backslash under u' => [
            'fragment' => "\u{202E}\\",
            'pattern' => '/x/u',
            'expected' => '\x{202E}\\',
        ];
        yield 'three trailing backslashes, byte mode' => [
            'fragment' => "\u{202E}\\\\\\",
            'pattern' => '/x/',
            'expected' => '\xE2\x80\xAE\\\\\\',
        ];
        yield 'two trailing backslashes, byte mode' => [
            'fragment' => "\u{202E}\\\\",
            'pattern' => '/x/',
            'expected' => '\xE2\x80\xAE\\\\',
        ];
        // A line that holds every delimiter is still spelled in the mode of
        // its pattern: per byte without UTF, where PCRE refuses \x{202E}.
        yield 'every delimiter, byte mode' => [
            'fragment' => self::EVERY_DELIMITER."\u{202E}",
            'pattern' => '/x/',
            'expected' => self::EVERY_DELIMITER.'\xE2\x80\xAE',
        ];
        yield 'every delimiter under u' => [
            'fragment' => self::EVERY_DELIMITER."\u{202E}",
            'pattern' => '/x/u',
            'expected' => self::EVERY_DELIMITER.'\x{202E}',
        ];
        yield 'every delimiter and a trailing backslash' => [
            'fragment' => self::EVERY_DELIMITER."\u{202E}\\",
            'pattern' => '/x/',
            'expected' => self::EVERY_DELIMITER.'\xE2\x80\xAE\\',
        ];
        // A line that holds every delimiter and every control byte is read
        // with none: it is still spelled in the mode of its pattern, per
        // byte without UTF, where PCRE refuses \x{202E} ("character code
        // point value in \x{} or \o{} is too large", PCRE2 10.49).
        yield 'every delimiter and every control byte, byte mode' => [
            'fragment' => self::EVERY_DELIMITER.self::EVERY_CONTROL_DELIMITER."\u{202E}",
            'pattern' => '/x/',
            'expected' => self::EVERY_DELIMITER.self::EVERY_CONTROL_DELIMITER_SPELLED.'\xE2\x80\xAE',
        ];
        yield 'every delimiter and every control byte under u' => [
            'fragment' => self::EVERY_DELIMITER.self::EVERY_CONTROL_DELIMITER."\u{202E}",
            'pattern' => '/x/u',
            'expected' => self::EVERY_DELIMITER.self::EVERY_CONTROL_DELIMITER_SPELLED.'\x{202E}',
        ];
        yield 'every delimiter and every control byte, a trailing backslash' => [
            'fragment' => self::EVERY_DELIMITER.self::EVERY_CONTROL_DELIMITER."\u{202E}\\",
            'pattern' => '/x/u',
            'expected' => self::EVERY_DELIMITER.self::EVERY_CONTROL_DELIMITER_SPELLED.'\x{202E}\\',
        ];
        // With no pattern, or a text that is no delimited pattern, the line
        // is read as code points.
        yield 'no pattern' => [
            'fragment' => "\u{202E}a",
            'pattern' => null,
            'expected' => '\x{202E}a',
        ];
        yield 'a pattern with no delimiters' => [
            'fragment' => "\u{202E}a",
            'pattern' => 'abc',
            'expected' => '\x{202E}a',
        ];
        yield 'an empty pattern' => [
            'fragment' => "\u{202E}a",
            'pattern' => '',
            'expected' => '\x{202E}a',
        ];
        yield 'a pattern in byte mode' => [
            'fragment' => "\u{202E}a",
            'pattern' => '/x/',
            'expected' => '\xE2\x80\xAEa',
        ];
        yield 'a pattern turning UTF on with (*UTF)' => [
            'fragment' => "\u{202E}a",
            'pattern' => '/(*UTF)x/',
            'expected' => '\x{202E}a',
        ];
        // A Unicode format character is hidden as a bidirectional control
        // is, in the mode of its pattern.
        yield 'a byte order mark under u' => [
            'fragment' => "\u{FEFF}a",
            'pattern' => '/x/u',
            'expected' => '\x{FEFF}a',
        ];
        yield 'a byte order mark, byte mode' => [
            'fragment' => "\u{FEFF}a",
            'pattern' => '/x/',
            'expected' => '\xEF\xBB\xBFa',
        ];
        yield 'a tag character under u' => [
            'fragment' => "\u{E0041}a",
            'pattern' => '/x/u',
            'expected' => '\x{E0041}a',
        ];
        yield 'a tag character, byte mode' => [
            'fragment' => "\u{E0041}a",
            'pattern' => '/x/',
            'expected' => '\xF3\xA0\x81\x81a',
        ];
    }

    #[Test]
    #[DataProvider('provideXmlTexts')]
    public function test_xml_spells_what_xml_cannot_hold_in_hex(string $text, string $attribute, string $characterData): void
    {
        $this->assertSame($attribute, ReportSpelling::xmlAttribute($text));
        $this->assertSame($characterData, ReportSpelling::xmlText($text));

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML('<r a="'.$attribute.'">'.$characterData.'</r>'));
        $root = $document->documentElement;
        $this->assertInstanceOf(\DOMElement::class, $root);
        $this->assertSame($root->getAttribute('a'), $root->textContent);
    }

    /**
     * @return iterable<string, array{text: string, attribute: string, characterData: string}>
     */
    public static function provideXmlTexts(): iterable
    {
        yield 'NUL' => ['text' => "a\x00b", 'attribute' => 'a\x00b', 'characterData' => 'a\x00b'];
        yield 'the first C0 control after NUL' => ['text' => "\x01", 'attribute' => '\x01', 'characterData' => '\x01'];
        yield 'backspace, vertical tab, form feed' => ['text' => "\x08\x0B\x0C", 'attribute' => '\x08\x0B\x0C', 'characterData' => '\x08\x0B\x0C'];
        yield 'the C0 controls after carriage return' => ['text' => "\x0E\x1F", 'attribute' => '\x0E\x1F', 'characterData' => '\x0E\x1F'];
        yield 'U+FFFE' => ['text' => "a\u{FFFE}b", 'attribute' => 'a\x{FFFE}b', 'characterData' => 'a\x{FFFE}b'];
        yield 'U+FFFF' => ['text' => "\u{FFFF}", 'attribute' => '\x{FFFF}', 'characterData' => '\x{FFFF}'];
        yield 'U+FFFD is a character XML holds' => ['text' => "\u{FFFD}", 'attribute' => "\u{FFFD}", 'characterData' => "\u{FFFD}"];
        yield 'a stray byte and a C0 control' => ['text' => "\xFF\x01", 'attribute' => '\xFF\x01', 'characterData' => '\xFF\x01'];
        yield 'markup and quotes' => ['text' => '<&"\'>', 'attribute' => '&lt;&amp;&quot;&apos;&gt;', 'characterData' => '&lt;&amp;&quot;&apos;&gt;'];
    }

    /**
     * Tab, line feed and carriage return are characters XML holds: the
     * attribute keeps all three as character references, the character data
     * only the carriage return.
     */
    #[Test]
    public function test_xml_keeps_tab_line_feed_and_carriage_return(): void
    {
        $this->assertSame('&#9;&#10;&#13;', ReportSpelling::xmlAttribute("\t\n\r"));
        $this->assertSame("\t\n&#13;", ReportSpelling::xmlText("\t\n\r"));
    }

    /**
     * The machine formats spell text that is no valid UTF-8 as the
     * baseline file does: each byte that is no part of a UTF-8 character
     * as "\xHH", every valid character of any width kept.
     */
    #[Test]
    #[DataProviderExternal(LintBaselineTest::class, 'provideTextsThatAreNoUtf8')]
    public function test_source_spells_each_byte_that_is_no_part_of_a_utf8_character(string $text, string $spelled): void
    {
        $this->assertSame($spelled, ReportSpelling::source($text));
    }

    /**
     * The caret stays under the character it pointed at once the excerpt
     * is spelled: a hidden character before it widens the line, one under
     * it is pointed at by its first spelled character; a caret past the
     * excerpt keeps its distance from the end, one left of the excerpt goes
     * under its first character. A snippet in no caret form is spelled
     * line by line.
     */
    #[Test]
    #[DataProvider('provideSnippets')]
    public function test_display_snippet_keeps_the_caret_under_its_character(string $snippet, string $expected): void
    {
        $this->assertSame($expected, ReportSpelling::displaySnippet($snippet, null));
    }

    /**
     * @return iterable<string, array{snippet: string, expected: string}>
     */
    public static function provideSnippets(): iterable
    {
        yield 'a hidden character before the caret' => [
            'snippet' => "Line 1: ab\u{202E}c\n".str_repeat(' ', 13).'^',
            'expected' => "Line 1: ab\\x{202E}c\n".str_repeat(' ', 18).'^',
        ];
        yield 'the caret under a hidden character' => [
            'snippet' => "Line 1: ab\u{202E}c\n".str_repeat(' ', 10).'^',
            'expected' => "Line 1: ab\\x{202E}c\n".str_repeat(' ', 10).'^',
        ];
        // The caret comes placed in bytes; it stands under the same
        // character once shown, one column for "é".
        yield 'a multibyte character before the caret' => [
            'snippet' => "Line 1: éa\n".str_repeat(' ', 10).'^',
            'expected' => "Line 1: éa\n".str_repeat(' ', 9).'^',
        ];
        yield 'the caret past the excerpt' => [
            'snippet' => "Line 1: ab\n".str_repeat(' ', 12).'^',
            'expected' => "Line 1: ab\n".str_repeat(' ', 12).'^',
        ];
        yield 'the caret left of the excerpt' => [
            'snippet' => "Line 1: abc\n^",
            'expected' => "Line 1: abc\n".str_repeat(' ', 8).'^',
        ];
        yield 'no caret form' => [
            'snippet' => "near \u{202E}here",
            'expected' => 'near \x{202E}here',
        ];
    }

    /**
     * A message or hint a person reads carries no byte that drives the
     * terminal or the log: an escape character, a lone carriage return or
     * NUL is spelled "\xHH". Tab and line feed lay the text out and stay.
     */
    #[Test]
    #[DataProvider('provideControlBytesInText')]
    public function test_display_text_spells_a_control_byte_that_drives_the_terminal(string $text, string $expected): void
    {
        $this->assertSame($expected, ReportSpelling::displayText($text));
    }

    /**
     * @return iterable<string, array{text: string, expected: string}>
     */
    public static function provideControlBytesInText(): iterable
    {
        yield 'escape character' => ['text' => "use \x1B[31mred", 'expected' => 'use \x1B[31mred'];
        yield 'lone carriage return' => ['text' => "line\rover", 'expected' => 'line\x0Dover'];
        yield 'bell and backspace' => ['text' => "a\x07b\x08c", 'expected' => 'a\x07b\x08c'];
        yield 'delete' => ['text' => "a\x7Fb", 'expected' => 'a\x7Fb'];
        yield 'tab and line feed kept' => ['text' => "a\tb\nc", 'expected' => "a\tb\nc"];
        yield 'nul' => ['text' => "a\x00b", 'expected' => 'a\x00b'];
        yield 'escape character with a hidden character' => ['text' => "\x1B\u{202E}", 'expected' => '\x1B\x{202E}'];
        // A Unicode format character (general category Cf) moves or hides
        // text as a bidirectional control does: each is spelled "\x{HEX}".
        yield 'byte order mark' => ['text' => "a\u{FEFF}b", 'expected' => 'a\x{FEFF}b'];
        yield 'zero-width non-joiner' => ['text' => "a\u{200C}b", 'expected' => 'a\x{200C}b'];
        yield 'zero-width joiner' => ['text' => "a\u{200D}b", 'expected' => 'a\x{200D}b'];
        yield 'word joiner' => ['text' => "a\u{2060}b", 'expected' => 'a\x{2060}b'];
        yield 'invisible plus' => ['text' => "a\u{2064}b", 'expected' => 'a\x{2064}b'];
        yield 'soft hyphen' => ['text' => "a\u{AD}b", 'expected' => 'a\x{AD}b'];
        yield 'mongolian vowel separator' => ['text' => "a\u{180E}b", 'expected' => 'a\x{180E}b'];
        yield 'tag latin capital letter A' => ['text' => "a\u{E0041}b", 'expected' => 'a\x{E0041}b'];
        yield 'arabic letter mark' => ['text' => "a\u{61C}b", 'expected' => 'a\x{61C}b'];
        yield 'arabic number sign' => ['text' => "a\u{600}b", 'expected' => 'a\x{600}b'];
        yield 'no-break space, per mille sign and a letter kept' => ['text' => "a\u{A0}\u{2030}\u{E9}b", 'expected' => "a\u{A0}\u{2030}\u{E9}b"];
    }
}

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

namespace PHPRegex\Tests\Unit\Parser\Json;

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\JsonEncodingException;
use PHPRegex\Parser\Exception\Snippet;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonDocumentTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_encode_prints_one_pretty_document_ending_with_one_newline(): void
    {
        $json = JsonDocument::encode(['path' => 'a/b', 'text' => 'é', 'list' => [1, 2]]);

        $this->assertSame("{\n    \"path\": \"a/b\",\n    \"text\": \"é\",\n    \"list\": [\n        1,\n        2\n    ]\n}\n", $json);
    }

    #[Test]
    public function test_encode_keeps_valid_utf8_as_it_is_control_characters_included(): void
    {
        $value = "line\none\ttab \\n é 《》 \x00";

        $json = JsonDocument::encode(['caret_snippet' => $value, 'nested' => ['pattern' => '/a\n/']]);

        $this->assertSame(['caret_snippet' => $value, 'nested' => ['pattern' => '/a\n/']], json_decode($json, true, 512, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    #[DataProvider('provideInvalidUtf8')]
    public function test_encode_spells_each_invalid_byte_and_keeps_the_rest(string $value, string $expected): void
    {
        $decoded = json_decode(JsonDocument::encode(['value' => $value]), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['value' => $expected], $decoded);
    }

    /**
     * @return iterable<string, array{value: string, expected: string}>
     */
    public static function provideInvalidUtf8(): iterable
    {
        yield 'a lone byte above ASCII' => ['value' => "/a\xff/", 'expected' => '/a\xFF/'];
        yield 'a continuation byte with no lead' => ['value' => "a\x80b", 'expected' => 'a\x80b'];
        yield 'a truncated sequence before ASCII' => ['value' => "\xE3\x81A", 'expected' => '\xE3\x81A'];
        yield 'a truncated sequence at the end' => ['value' => "é\xE3\x81", 'expected' => 'é\xE3\x81'];
        yield 'an overlong encoding' => ['value' => "\xC0\xAF", 'expected' => '\xC0\xAF'];
        yield 'a surrogate' => ['value' => "\xED\xA0\x80", 'expected' => '\xED\xA0\x80'];
        yield 'a lead byte UTF-8 never uses' => ['value' => "\xF8\x88\x80\x80\x80", 'expected' => '\xF8\x88\x80\x80\x80'];
        yield 'valid sequences around an invalid byte' => ['value' => "《\xFE》\n", 'expected' => "《\\xFE》\n"];
        yield 'a four-byte sequence kept' => ['value' => "\xF0\x9F\x98\x80\xFF", 'expected' => "\u{1F600}\\xFF"];
    }

    /**
     * A caret snippet counts the columns before its caret in bytes when
     * they are no UTF-8: once each invalid byte is spelled "\xHH", the
     * caret moves under the same character, in a snippet alone or in a
     * message that quotes one.
     */
    #[Test]
    #[DataProvider('provideCaretSnippets')]
    public function test_a_caret_stays_under_its_character_once_the_bytes_are_spelled(string $pattern, int $position, string $before, string $after, string $expected): void
    {
        $text = $before.Snippet::render($pattern, $position).$after;

        $this->assertSame($expected, JsonDocument::spellInvalidBytes($text));
        $this->assertSame(['value' => $expected], json_decode(JsonDocument::encode(['value' => $text]), true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * Only a line of spaces and a caret under the line of a snippet moves: a
     * line ending in another character stays where it is, and so does a
     * caret under a line that only looks like a snippet's.
     */
    #[Test]
    public function test_only_the_caret_line_under_a_snippet_moves(): void
    {
        $this->assertSame("Line 1: \\xFF(\n         x", JsonDocument::spellInvalidBytes("Line 1: \xFF(\n         x"));
        $this->assertSame("Note 1: \\xFF(\n         ^", JsonDocument::spellInvalidBytes("Note 1: \xFF(\n         ^"));
    }

    /**
     * @return iterable<string, array{pattern: string, position: int, before: string, after: string, expected: string}>
     */
    public static function provideCaretSnippets(): iterable
    {
        yield 'after two invalid bytes' => ['pattern' => "\xFF\xFE(", 'position' => 3, 'before' => '', 'after' => '', 'expected' => "Line 1: \\xFF\\xFE(\n                 ^"];
        yield 'on an invalid byte' => ['pattern' => "a\xFFb", 'position' => 1, 'before' => '', 'after' => '', 'expected' => "Line 1: a\\xFFb\n         ^"];
        yield 'after an invalid byte and a character of two bytes' => ['pattern' => "\xFFé(", 'position' => 3, 'before' => '', 'after' => '', 'expected' => "Line 1: \\xFFé(\n             ^"];
        yield 'after an invalid byte and a wide character' => ['pattern' => "\xFF中(", 'position' => 4, 'before' => '', 'after' => '', 'expected' => "Line 1: \\xFF中(\n              ^"];
        yield 'after an invalid byte and an emoji' => ['pattern' => "\xFF\u{1F680}(", 'position' => 5, 'before' => '', 'after' => '', 'expected' => "Line 1: \\xFF\u{1F680}(\n              ^"];
        yield 'on the second line, past the end' => ['pattern' => "a\n\xFF", 'position' => 3, 'before' => '', 'after' => '', 'expected' => "Line 2: \\xFF\n            ^"];
        yield 'quoted in a message' => ['pattern' => "\xFF(", 'position' => 2, 'before' => "Expected ) at end of input (found eof).\n", 'after' => "\nSuggestion: \xFF", 'expected' => "Expected ) at end of input (found eof).\nLine 1: \\xFF(\n             ^\nSuggestion: \\xFF"];
        // Valid UTF-8 before the caret is counted in columns, fewer than its
        // bytes: the caret does not move for an invalid byte past it.
        yield 'after characters of two bytes, an invalid byte past the caret' => ['pattern' => "\u{E9}\u{E9}\u{E9}a)\xFF", 'position' => 7, 'before' => '', 'after' => '', 'expected' => "Line 1: \u{E9}\u{E9}\u{E9}a)\\xFF\n            ^"];
        yield 'on an invalid byte after characters of two bytes' => ['pattern' => "\u{E9}\u{E9}\u{E9}a)\xFF", 'position' => 8, 'before' => '', 'after' => '', 'expected' => "Line 1: \u{E9}\u{E9}\u{E9}a)\\xFF\n             ^"];
        yield 'after wide characters, an invalid byte past the caret' => ['pattern' => "\u{4E00}\u{4E00})\xFF", 'position' => 6, 'before' => '', 'after' => '', 'expected' => "Line 1: \u{4E00}\u{4E00})\\xFF\n            ^"];
        yield 'on an invalid byte after wide characters' => ['pattern' => "/\u{4E00}\u{4E00})\xFF/", 'position' => 8, 'before' => '', 'after' => '', 'expected' => "Line 1: /\u{4E00}\u{4E00})\\xFF/\n              ^"];
    }

    #[Test]
    public function test_encode_writes_objects_through_their_mapping_and_enums_through_their_value(): void
    {
        $validation = Regex::create()->validate("/(\xFF/");

        $decoded = json_decode(JsonDocument::encode(['validation' => $validation, 'severity' => LintSeverity::Warning]), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['validation']);
        $this->assertSame(array_keys($validation->jsonSerialize()), array_keys($decoded['validation']));
        $this->assertFalse($decoded['validation']['is_valid']);
        $this->assertSame(LintSeverity::Warning->value, $decoded['severity']);
    }

    #[Test]
    public function test_encode_refuses_an_object_with_no_mapping(): void
    {
        $this->expectException(JsonEncodingException::class);
        $this->expectExceptionMessageIsOrContains('No JSON mapping for an object of class stdClass.');

        JsonDocument::encode(['value' => new \stdClass()]);
    }

    #[Test]
    public function test_encode_reports_a_value_json_cannot_hold_as_an_encoding_failure(): void
    {
        $this->expectException(JsonEncodingException::class);
        $this->expectExceptionMessageIsOrContains('Failed to encode JSON');

        JsonDocument::encode(['value' => \NAN]);
    }

    #[Test]
    public function test_an_encoding_failure_is_a_library_exception(): void
    {
        $this->assertTrue((new \ReflectionClass(JsonEncodingException::class))->implementsInterface(ExceptionInterface::class));
        $this->assertTrue((new \ReflectionClass(JsonEncodingException::class))->isSubclassOf(\RuntimeException::class));
    }

    #[Test]
    public function test_error_is_the_envelope_then_its_sibling_keys(): void
    {
        $validation = Regex::create()->validate('/(a/');

        $decoded = json_decode(JsonDocument::error('Invalid pattern.', JsonDocument::STAGE_PATTERN, ['validation' => $validation, 'stage' => 'ignored']), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame(['error', 'stage', 'validation'], array_keys($decoded));
        $this->assertSame('Invalid pattern.', $decoded['error']);
        $this->assertSame('pattern', $decoded['stage']);
    }

    #[Test]
    public function test_error_alone_is_error_and_stage(): void
    {
        $this->assertSame("{\n    \"error\": \"boom\",\n    \"stage\": \"usage\"\n}\n", JsonDocument::error('boom', JsonDocument::STAGE_USAGE));
    }
}

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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Internal\JsonEncodingFailure;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonDocumentTest extends TestCase
{
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
        $this->expectException(JsonEncodingFailure::class);
        $this->expectExceptionMessage('No JSON mapping for an object of class stdClass.');

        JsonDocument::encode(['value' => new \stdClass()]);
    }

    #[Test]
    public function test_encode_reports_a_value_json_cannot_hold_as_an_encoding_failure(): void
    {
        $this->expectException(JsonEncodingFailure::class);
        $this->expectExceptionMessage('Failed to encode JSON');

        JsonDocument::encode(['value' => \NAN]);
    }

    #[Test]
    public function test_an_encoding_failure_is_a_library_exception(): void
    {
        $this->assertTrue((new \ReflectionClass(JsonEncodingFailure::class))->implementsInterface(ExceptionInterface::class));
        $this->assertTrue((new \ReflectionClass(JsonEncodingFailure::class))->isSubclassOf(\RuntimeException::class));
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

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

namespace RegexParser\Tests\Unit\TestUtils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Tests\TestUtils\JsonSchemaSubsetValidator;

/**
 * The validator the configuration schema tests rely on: a green schema test
 * means nothing unless the validator rejects what it should.
 */
final class JsonSchemaSubsetValidatorTest extends TestCase
{
    private const SCHEMA = <<<'JSON'
        {
            "$schema": "https://json-schema.org/draft/2020-12/schema",
            "type": "object",
            "additionalProperties": false,
            "$defs": {"name": {"type": "string", "minLength": 1}},
            "properties": {
                "paths": {"type": "array", "items": {"$ref": "#/$defs/name"}, "uniqueItems": true},
                "jobs": {"type": "integer", "minimum": 1, "default": 1},
                "mode": {"type": "string", "enum": ["theoretical", "confirmed"]},
                "flag": {"const": true},
                "either": {"oneOf": [{"type": "string"}, {"type": "integer"}]},
                "some": {"anyOf": [{"type": "string"}, {"type": "boolean"}]},
                "nested": {
                    "type": "object",
                    "additionalProperties": false,
                    "required": ["enabled"],
                    "properties": {"enabled": {"type": "boolean"}}
                }
            }
        }
        JSON;

    #[Test]
    #[DataProvider('provideValidDocuments')]
    public function test_validate_accepts_a_conforming_document(string $document): void
    {
        $this->assertSame([], $this->validate($document));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidDocuments(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'every property' => ['{"paths": ["src", "lib"], "jobs": 2, "mode": "confirmed", "flag": true, "either": 3, "some": false, "nested": {"enabled": true}}'];
        yield 'empty list' => ['{"paths": []}'];
    }

    #[Test]
    #[DataProvider('provideInvalidDocuments')]
    public function test_validate_rejects_a_violation(string $document, string $expectedPath): void
    {
        $errors = $this->validate($document);

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString($expectedPath, implode("\n", $errors));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidDocuments(): iterable
    {
        yield 'unknown top-level key' => ['{"nope": 1}', '$.nope'];
        yield 'unknown nested key' => ['{"nested": {"enabled": true, "nope": 1}}', '$.nested.nope'];
        yield 'missing required key' => ['{"nested": {}}', '$.nested.enabled'];
        yield 'list where an object is expected' => ['{"nested": []}', '$.nested'];
        yield 'object where a list is expected' => ['{"paths": {}}', '$.paths'];
        yield 'wrong item type via $ref' => ['{"paths": [1]}', '$.paths[0]'];
        yield 'empty string via $ref' => ['{"paths": [""]}', '$.paths[0]'];
        yield 'duplicate items' => ['{"paths": ["a", "a"]}', '$.paths'];
        yield 'below the minimum' => ['{"jobs": 0}', '$.jobs'];
        yield 'float for an integer' => ['{"jobs": 1.5}', '$.jobs'];
        yield 'value outside the enum' => ['{"mode": "off"}', '$.mode'];
        yield 'enum is case-sensitive' => ['{"mode": "Confirmed"}', '$.mode'];
        yield 'const mismatch' => ['{"flag": false}', '$.flag'];
        yield 'oneOf with no match' => ['{"either": true}', '$.either'];
        yield 'anyOf with no match' => ['{"some": 1}', '$.some'];
        yield 'array at the root' => ['[]', '$'];
    }

    #[Test]
    public function test_validate_reports_every_violation_at_once(): void
    {
        $this->assertCount(3, $this->validate('{"nope": 1, "jobs": 0, "mode": "off"}'));
    }

    #[Test]
    public function test_validate_refuses_a_keyword_it_does_not_implement(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"patternProperties"');

        (new JsonSchemaSubsetValidator())->validate(['patternProperties' => []], new \stdClass());
    }

    /**
     * @return list<string>
     */
    private function validate(string $document): array
    {
        /** @var array<string, mixed> $schema */
        $schema = json_decode(self::SCHEMA, true, 512, \JSON_THROW_ON_ERROR);

        return (new JsonSchemaSubsetValidator())->validate($schema, json_decode($document, false, 512, \JSON_THROW_ON_ERROR));
    }
}

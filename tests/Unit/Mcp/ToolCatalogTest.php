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

namespace PHPRegex\Tests\Unit\Mcp;

use Opis\JsonSchema\Validator;
use PHPRegex\Mcp\Exception\UnknownToolException;
use PHPRegex\Mcp\ToolCatalog;
use PHPRegex\Mcp\ToolDefinition;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The catalog is the contract a client reads: five read-only tools, each
 * with the name, the schemas and the annotations the protocol shows.
 */
final class ToolCatalogTest extends TestCase
{
    /**
     * The SDK's own rule for a tool name, copied from its NameValidator so
     * that a name the catalog chooses is one the SDK accepts.
     */
    private const SDK_NAME_RULE = '/^[a-zA-Z0-9._\/-]{1,64}$/';

    #[Test]
    public function test_v1_serves_five_tools_with_the_regex_prefix(): void
    {
        $names = array_map(static fn (ToolDefinition $tool): string => $tool->name, ToolCatalog::v1()->tools());

        $this->assertSame(['regex_validate', 'regex_explain', 'regex_format', 'regex_transpile', 'regex_redos'], $names);
    }

    /**
     * The schemas are the contract a client reads: every property, type,
     * enum, description, required list and annotation is pinned by the
     * fixture, so a change to one is a deliberate edit of both.
     */
    #[Test]
    public function test_the_schemas_are_the_pinned_contract(): void
    {
        $pinned = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Mcp/schemas.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($pinned);

        $actual = [];
        foreach (ToolCatalog::v1()->tools() as $definition) {
            $actual[$definition->name] = [
                'title' => $definition->title,
                'description' => $definition->description,
                'inputSchema' => $definition->inputSchema,
                'outputSchema' => $definition->outputSchema,
                'annotations' => $definition->annotations,
            ];
        }
        // Through JSON, so the fixture's spelling is the comparison's.
        $actual = json_decode((string) json_encode($actual, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame($pinned, $actual, 'tests/Fixtures/Mcp/schemas.json pins the tools\' contract: regenerate it on purpose, never on a whim.');
    }

    #[Test]
    public function test_every_name_follows_the_sdk_rule(): void
    {
        foreach (ToolCatalog::v1()->tools() as $tool) {
            $this->assertMatchesRegularExpression(self::SDK_NAME_RULE, $tool->name);
        }
    }

    #[Test]
    public function test_every_tool_is_read_only_and_idempotent(): void
    {
        foreach (ToolCatalog::v1()->tools() as $tool) {
            $this->assertSame(
                ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                $tool->annotations,
                $tool->name,
            );
        }
    }

    #[Test]
    public function test_every_tool_says_the_pattern_is_delimited(): void
    {
        foreach (ToolCatalog::v1()->tools() as $tool) {
            $this->assertStringContainsString('/…/flags', $tool->description, $tool->name);
            $properties = JsonContract::asArray($tool->inputSchema['properties'] ?? null, $tool->name);
            $pattern = JsonContract::asArray($properties['pattern'] ?? null, $tool->name);
            $this->assertIsString($pattern['description'] ?? null, $tool->name);
            $this->assertStringContainsString('delimiter', (string) $pattern['description'], $tool->name);
            $this->assertContains('pattern', JsonContract::asArray($tool->inputSchema['required'] ?? null, $tool->name), $tool->name);
            $this->assertFalse($tool->inputSchema['additionalProperties'] ?? true, $tool->name.' must refuse an argument it does not know.');
        }
    }

    #[Test]
    public function test_every_tool_takes_the_target_arguments_but_no_locale(): void
    {
        foreach (ToolCatalog::v1()->tools() as $tool) {
            $properties = JsonContract::asArray($tool->inputSchema['properties'] ?? null, $tool->name);
            $this->assertArrayHasKey('php_version', $properties, $tool->name);
            $this->assertArrayHasKey('pcre_version', $properties, $tool->name);
            $this->assertArrayNotHasKey('locale', $properties, $tool->name);
        }
    }

    #[Test]
    public function test_get_answers_a_tool_by_name_and_refuses_an_unknown_one(): void
    {
        $catalog = ToolCatalog::v1();

        $this->assertSame('regex_format', $catalog->get('regex_format')->name);
        $this->assertTrue($catalog->has('regex_redos'));
        $this->assertFalse($catalog->has('format'));

        try {
            $catalog->get('format');
            $this->fail('An unknown tool must be refused.');
        } catch (UnknownToolException $e) {
            $this->assertSame('Unknown tool "format".', $e->getMessage());
        }
    }

    /**
     * A tool declaring an output schema promises every structured answer it
     * sends: the success document and the error envelope alike.
     *
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideAnswers')]
    public function test_the_output_schema_accepts_the_real_answer(string $tool, array $arguments, bool $isError): void
    {
        $definition = ToolCatalog::v1()->get($tool);
        $result = $definition->tool->execute($arguments);

        $this->assertSame($isError, $result->isError);

        $schema = json_decode((string) json_encode($definition->outputSchema, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsObject($schema);
        $outcome = (new Validator())->validate(
            json_decode((string) json_encode($result->structured, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR),
            $schema,
        );

        $this->assertTrue($outcome->isValid(), $tool.': '.json_encode($result->structured));
    }

    /**
     * @return iterable<string, array{tool: string, arguments: array<string, mixed>, isError: bool}>
     */
    public static function provideAnswers(): iterable
    {
        yield 'validate, a valid pattern' => ['tool' => 'regex_validate', 'arguments' => ['pattern' => '/a+/'], 'isError' => false];
        yield 'validate, an invalid pattern' => ['tool' => 'regex_validate', 'arguments' => ['pattern' => '/(a/'], 'isError' => false];
        yield 'validate, no pattern' => ['tool' => 'regex_validate', 'arguments' => [], 'isError' => true];
        yield 'explain' => ['tool' => 'regex_explain', 'arguments' => ['pattern' => '/a+/'], 'isError' => false];
        yield 'explain, an invalid pattern' => ['tool' => 'regex_explain', 'arguments' => ['pattern' => '/(a/'], 'isError' => true];
        yield 'format' => ['tool' => 'regex_format', 'arguments' => ['pattern' => '/^(?<year>\d{4})-(?<month>\d{2})$/', 'width' => 24], 'isError' => false];
        yield 'format, an invalid pattern' => ['tool' => 'regex_format', 'arguments' => ['pattern' => '/(a/'], 'isError' => true];
        yield 'transpile' => ['tool' => 'regex_transpile', 'arguments' => ['pattern' => '/^a$/m', 'target' => 'js'], 'isError' => false];
        yield 'transpile, a refusal' => ['tool' => 'regex_transpile', 'arguments' => ['pattern' => '/(?>a+)b/', 'target' => 'js'], 'isError' => true];
        yield 'redos' => ['tool' => 'regex_redos', 'arguments' => ['pattern' => '/^(a+)+$/'], 'isError' => false];
        yield 'redos, confirmed' => ['tool' => 'regex_redos', 'arguments' => ['pattern' => '/^(a+)+$/', 'mode' => 'confirmed'], 'isError' => false];
        yield 'redos, an invalid pattern' => ['tool' => 'regex_redos', 'arguments' => ['pattern' => '/(a/'], 'isError' => true];
        yield 'an unknown target' => ['tool' => 'regex_validate', 'arguments' => ['pattern' => '/a/', 'php_version' => 'eight'], 'isError' => true];
    }

    #[Test]
    public function test_the_server_locale_reaches_every_tool(): void
    {
        $result = ToolCatalog::v1(['locale' => 'C'])->get('regex_validate')->tool->execute(['pattern' => '/\w+/']);

        $this->assertFalse($result->isError);
        $this->assertTrue(JsonContract::asArray($result->structured['validation'])['is_valid']);
    }

    #[Test]
    public function test_the_server_target_is_the_default_of_every_tool(): void
    {
        // \p{Script=Latin} is a property name PCRE2 10.35, which PHP 8.0
        // bundles, does not know; 10.40, bundled by PHP 8.2, reads it.
        $onEight = ToolCatalog::v1(['php_version' => '8.0'])->get('regex_validate')->tool->execute(['pattern' => '/\p{Script=Latin}/u']);
        $this->assertFalse(JsonContract::asArray($onEight->structured['validation'])['is_valid']);

        $overridden = ToolCatalog::v1(['php_version' => '8.0'])->get('regex_validate')->tool->execute(['pattern' => '/\p{Script=Latin}/u', 'php_version' => '8.2']);
        $this->assertTrue(JsonContract::asArray($overridden->structured['validation'])['is_valid']);
    }
}

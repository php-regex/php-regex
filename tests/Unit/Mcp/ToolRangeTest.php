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
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Mcp\ToolCatalog;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RangePatterns;
use PHPRegex\Tests\Support\SinglePatternRange;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * php_version, as a call's argument or as the server's default, takes a
 * Composer constraint as well as a single version: each tool judges the
 * pattern at every version of the range, does its work at the floor, and
 * its structured answer gains the "target" ("php_target" for transpile,
 * whose "target" is the dialect) and the "range" of the CLI's documents,
 * which the v1 output schema declares. The server reads no
 * file from its working directory: the call, then the server's flag, then
 * the running PHP.
 */
final class ToolRangeTest extends TestCase
{
    use SinglePatternRange;
    use TemporaryProject;

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideToolsOnARange')]
    public function test_a_tool_judges_a_constraint_at_every_version(string $tool, array $arguments, string $pattern): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineReadsQuantifierWithoutMin();

        $result = ToolCatalog::v1()->get($tool)->tool->execute(['pattern' => $pattern, 'php_version' => '>=8.1', ...$arguments]);

        $this->assertFalse($result->isError, $result->text);
        self::assertTargetFrom81($result->structured, $tool);
        self::assertRangeOf($result->structured['range'] ?? null, $pattern);
    }

    /**
     * The server's --php-version is the default of every call, a range
     * included.
     *
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideToolsOnARange')]
    public function test_the_servers_constraint_is_the_default_of_a_call(string $tool, array $arguments, string $pattern): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineReadsQuantifierWithoutMin();

        $result = ToolCatalog::v1(['php_version' => '>=8.1'])->get($tool)->tool->execute(['pattern' => $pattern, ...$arguments]);

        $this->assertFalse($result->isError, $result->text);
        self::assertTargetFrom81($result->structured, $tool);
        self::assertRangeOf($result->structured['range'] ?? null, $pattern);
    }

    /**
     * @return iterable<string, array{tool: string, arguments: array<string, mixed>, pattern: string}>
     */
    public static function provideToolsOnARange(): iterable
    {
        yield 'validate, refused from 8.5' => ['tool' => 'regex_validate', 'arguments' => [], 'pattern' => RangePatterns::KEEP_IN_LOOKBEHIND];
        yield 'validate, read otherwise from 8.4' => ['tool' => 'regex_validate', 'arguments' => [], 'pattern' => RangePatterns::QUANTIFIER_WITHOUT_MIN];
        yield 'explain' => ['tool' => 'regex_explain', 'arguments' => [], 'pattern' => RangePatterns::KEEP_IN_LOOKBEHIND];
        yield 'format' => ['tool' => 'regex_format', 'arguments' => [], 'pattern' => RangePatterns::KEEP_IN_LOOKBEHIND];
        yield 'redos' => ['tool' => 'regex_redos', 'arguments' => [], 'pattern' => RangePatterns::KEEP_IN_LOOKBEHIND];
        // The translator refuses "\K" at any PHP: the warning row only.
        yield 'transpile' => ['tool' => 'regex_transpile', 'arguments' => ['target' => 'js'], 'pattern' => RangePatterns::QUANTIFIER_WITHOUT_MIN];
    }

    /**
     * A tool's validation keeps the floor's verdict: PHP 8.1 compiles "\K"
     * in a lookbehind.
     */
    #[Test]
    public function test_the_validation_keeps_the_floors_verdict(): void
    {
        self::assertEngineKeepInLookbehind();

        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => RangePatterns::KEEP_IN_LOOKBEHIND, 'php_version' => '>=8.1']);

        $this->assertFalse($result->isError, $result->text);
        $this->assertTrue(JsonContract::asArray($result->structured['validation'] ?? null)['is_valid'] ?? null);
    }

    /**
     * The text a client reads carries what the later versions say, in
     * lint's words: the refusal of the lowest that refuses the pattern, or
     * the lowest that reads it into another tree. A pattern the floor
     * accepts stays no failure of the tool.
     *
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideToolsOnARange')]
    public function test_the_text_answer_carries_the_range_findings(string $tool, array $arguments, string $pattern): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineReadsQuantifierWithoutMin();

        $result = ToolCatalog::v1()->get($tool)->tool->execute(['pattern' => $pattern, 'php_version' => '>=8.1', ...$arguments]);

        $this->assertFalse($result->isError, $result->text);
        $finding = RangePatterns::KEEP_IN_LOOKBEHIND === $pattern
            ? self::refusedOnMessage('PHP 8.5 and later', 80500, $pattern)
            : self::parsesDifferentlyMessage(self::firstPointReadingQuantifierOtherwise(RangePatterns::FROM_8_1));
        $this->assertStringContainsString($finding, $result->text, $tool);
    }

    /**
     * validate's text was "Valid." for a pattern the floor accepts: a later
     * refusal is no longer silent there.
     */
    /**
     * A change of meaning that keeps the tree is said in the text as lint
     * says it: a group that holds a reference to itself is backtracked into
     * from PCRE2 10.35, PHP 8.0.
     */
    #[Test]
    public function test_the_text_answer_carries_a_silent_change_of_meaning(): void
    {
        self::assertEngineBacktracksIntoSelfReference();

        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/^(a|ab|\1x)+b$/', 'php_version' => '>=7.4 <8.1']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString("\nWarning: From PHP 8.0 (PCRE2 ".self::judgedPcre(80000).') a group that holds a reference to itself may be backtracked into', $result->text);
    }

    #[Test]
    public function test_validate_no_longer_answers_valid_alone_over_a_later_refusal(): void
    {
        self::assertEngineKeepInLookbehind();

        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => RangePatterns::KEEP_IN_LOOKBEHIND, 'php_version' => '>=8.1']);

        $this->assertFalse($result->isError, $result->text);
        $this->assertNotSame('Valid.', $result->text);
        $this->assertStringContainsString(self::refusedOnMessage('PHP 8.5 and later', 80500, RangePatterns::KEEP_IN_LOOKBEHIND), $result->text);
    }

    /**
     * What the resolution changed on the way, a range clipped to the PHP
     * versions on PCRE2, is said in the text in lint's words; the source
     * the notice names is the server's own.
     *
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideToolsOnAValidPattern')]
    public function test_the_text_answer_carries_the_resolver_notices(string $tool, array $arguments): void
    {
        $result = ToolCatalog::v1()->get($tool)->tool->execute(['pattern' => '/a/', 'php_version' => '>=7.0', ...$arguments]);

        $this->assertFalse($result->isError, $result->text);
        $this->assertStringContainsString('allows PHP 7.0, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP 7.3, the lowest version it allows on PCRE2.', $result->text, $tool);
    }

    /**
     * @return iterable<string, array{tool: string, arguments: array<string, mixed>}>
     */
    public static function provideToolsOnAValidPattern(): iterable
    {
        yield 'validate' => ['tool' => 'regex_validate', 'arguments' => []];
        yield 'explain' => ['tool' => 'regex_explain', 'arguments' => []];
        yield 'format' => ['tool' => 'regex_format', 'arguments' => []];
        yield 'redos' => ['tool' => 'regex_redos', 'arguments' => []];
        yield 'transpile' => ['tool' => 'regex_transpile', 'arguments' => ['target' => 'js']];
    }

    /**
     * A regex.json and a composer.json in the server's working directory
     * name a target the CLI would use: the server reads neither.
     */
    #[Test]
    public function test_the_server_reads_no_file_from_its_working_directory(): void
    {
        $this->enterProject([
            'composer.json' => '{"name": "acme/app", "require": {"php": ">=8.1"}}',
            'regex.json' => '{"phpVersion": "8.2", "pcreVersion": "10.40"}',
        ]);

        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/a/']);

        $this->assertFalse($result->isError, $result->text);
        $this->assertSame(ProjectTarget::fromSources([], [], null, [])->toArray(), $result->structured['target'] ?? null);
        $this->assertCount(1, JsonContract::asArray($result->structured['range'] ?? null));
    }

    /**
     * A constraint no reader takes is the call's usage failure, as the CLI
     * refuses it.
     */
    #[Test]
    public function test_an_unreadable_constraint_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/a/', 'php_version' => '<8.4']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage'] ?? null);
        $error = $result->structured['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('a Composer constraint like ">=8.1"', (string) $error);
    }

    /**
     * tools/list's v1 output schema declares the two keys, and every
     * answer over a range validates against it.
     *
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('provideToolsOnARange')]
    public function test_the_output_schema_declares_and_accepts_the_target_and_the_range(string $tool, array $arguments, string $pattern): void
    {
        $definition = ToolCatalog::v1()->get($tool);
        $success = JsonContract::asArray(JsonContract::asArray($definition->outputSchema['oneOf'] ?? null)[0] ?? null, $tool);
        $properties = JsonContract::asArray($success['properties'] ?? null, $tool);
        $required = JsonContract::asArray($success['required'] ?? null, $tool);
        $this->assertArrayHasKey('range', $properties, $tool);
        $this->assertContains('range', $required, $tool);
        // transpile's "target" is the dialect already: it names the PHP
        // versions judged "php_target".
        $targetKey = 'regex_transpile' === $tool ? 'php_target' : 'target';
        $this->assertSame('object', JsonContract::asArray($properties[$targetKey] ?? null, $tool)['type'] ?? null, $tool);
        $this->assertContains($targetKey, $required, $tool);
        if ('regex_transpile' === $tool) {
            $this->assertSame('string', JsonContract::asArray($properties['target'] ?? null, $tool)['type'] ?? null, 'The dialect stays a string.');
        }

        $result = $definition->tool->execute(['pattern' => $pattern, 'php_version' => '>=8.1', ...$arguments]);

        $schema = json_decode((string) json_encode($definition->outputSchema, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsObject($schema);
        $outcome = (new Validator())->validate(
            json_decode((string) json_encode($result->structured, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR),
            $schema,
        );
        $this->assertTrue($outcome->isValid(), $tool.': '.json_encode($result->structured));
        $this->assertArrayHasKey('range', $result->structured, $tool);
        $this->assertArrayHasKey($targetKey, $result->structured, $tool);
    }

    /**
     * The target of ">=8.1" as lint names it, "php_target" for transpile,
     * whose "target" is the dialect; where the server names its source is
     * its own.
     *
     * @param array<string, mixed> $structured
     */
    private static function assertTargetFrom81(array $structured, string $tool): void
    {
        $expected = ProjectTarget::fromSources(['--php-version' => '>=8.1'], [], null, [])->toArray();
        unset($expected['source']);

        $key = 'target';
        if ('regex_transpile' === $tool) {
            self::assertSame('javascript', $structured['target'] ?? null, 'transpile keeps the dialect as its target.');
            $key = 'php_target';
        }

        $target = JsonContract::asArray($structured[$key] ?? null, $tool.' has no '.$key.'.');
        self::assertIsString($target['source'] ?? null, $tool);
        unset($target['source']);
        self::assertSame($expected, $target, $tool);
    }

    private static function assertRangeOf(mixed $range, string $pattern): void
    {
        if (RangePatterns::KEEP_IN_LOOKBEHIND === $pattern) {
            self::assertRange($range, RangePatterns::FROM_8_1, $pattern, static fn (int $point): bool => $point < 80500, static fn (int $point): bool => false);

            return;
        }

        self::assertRange($range, RangePatterns::FROM_8_1, $pattern, static fn (int $point): bool => true, self::readsQuantifierOtherwiseThanTheFloor(RangePatterns::FROM_8_1));
    }
}

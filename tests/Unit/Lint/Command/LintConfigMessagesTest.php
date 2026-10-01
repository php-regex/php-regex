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

namespace PhpRegex\Tests\Unit\Lint\Command;

use PhpRegex\Linter\Config\LintConfigLoader;
use PhpRegex\Linter\Config\LintConfigSchema;
use PhpRegex\Linter\Extraction\InteropPresets;
use PhpRegex\Linter\Formatter\FormatterRegistry;
use PhpRegex\Linter\Rule\LintRuleRegistry;
use PhpRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a refused regex.json is told, and the lists the schema must keep in
 * step with the code that reads them.
 */
final class LintConfigMessagesTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    #[DataProvider('provideMessages')]
    public function test_load_says_what_the_value_should_be(string $json, string $message): void
    {
        $error = (new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]))->error;

        $this->assertNotNull($error);
        $this->assertStringContainsString($message, (string) $error);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMessages(): iterable
    {
        yield 'boolean check form' => ['{"checks": {"redos": true}}', 'write {"enabled": true}'];
        yield 'wrong case of a key' => ['{"Paths": ["src"]}', 'Did you mean "paths"?'];
        yield 'snake_case option' => ['{"checks": {"optimizations": {"options": {"min_quantifier_count": 3}}}}', 'Did you mean "minQuantifierCount"?'];
        yield 'unknown lint rule' => ['{"checks": {"lint": {"rules": {"not.a.rule": true}}}}', 'Unknown lint rule "not.a.rule"'];
        yield 'integer minimum' => ['{"jobs": "four"}', 'expected an integer >= 1'];
        yield 'enum' => ['{"format": "xml"}', 'expected one of console, json, github, checkstyle, junit'];
        yield 'boolean' => ['{"checks": {"validation": "yes"}}', 'expected a boolean'];
        yield 'object' => ['{"extraction": []}', 'expected an object'];
        yield 'unknown preset' => ['{"extraction": {"interop": ["composer-pcre", "pcre-x"]}}', 'unknown preset "pcre-x"'];
        yield 'duplicate preset' => ['{"extraction": {"interop": ["nette-utils", "nette-utils"]}}', 'a list of distinct presets'];
        yield 'duplicate function' => ['{"extraction": {"functions": ["a", "a"]}}', 'a list of distinct non-empty strings'];
        yield 'unknown IDE' => ['{"ide": "notepad"}', 'a URL template holding %f or %l'];
        yield 'removed mode off' => ['{"checks": {"redos": {"mode": "off"}}}', 'set checks.redos.enabled to false'];
        yield 'removed key with a replacement' => ['{"minSavings": 2}', 'use "checks.optimizations.minSavings" instead'];
        yield 'PHP_VERSION_ID too small' => ['{"phpVersion": 83}', 'a PHP_VERSION_ID like 80300'];
    }

    #[Test]
    #[DataProvider('provideAcceptedIdes')]
    public function test_load_accepts_an_ide_name_or_a_template(string $ide): void
    {
        $json = json_encode(['ide' => $ide], \JSON_THROW_ON_ERROR);

        $this->assertNull((new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]))->error);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedIdes(): iterable
    {
        yield 'none' => [''];
        yield 'named' => ['vscode'];
        yield 'template' => ['myeditor://open?file=%f&line=%l'];
    }

    #[Test]
    public function test_load_reports_the_errors_of_both_files(): void
    {
        $error = (new LintConfigLoader())->load($this->makeProject([
            'regex.dist.json' => '{"distOnly": 1}',
            'regex.json' => '{"localOnly": 1}',
        ]))->error;

        $this->assertNotNull($error);
        $this->assertStringContainsString('regex.dist.json', (string) $error);
        $this->assertStringContainsString('distOnly', (string) $error);
        $this->assertStringContainsString('localOnly', (string) $error);
    }

    #[Test]
    public function test_load_replaces_a_list_with_a_string_and_merges_objects(): void
    {
        $result = (new LintConfigLoader())->load($this->makeProject([
            'regex.dist.json' => '{"paths": ["src", "lib"], "checks": {"lint": {"rules": {"flag.redundant": false}}}}',
            'regex.json' => '{"paths": "app", "checks": {"lint": {"enabled": false}}}',
        ]));

        $this->assertNull($result->error);
        $this->assertSame(['paths' => 'app', 'checks' => ['lint' => ['rules' => ['flag.redundant' => false], 'enabled' => false]]], $result->config);
    }

    #[Test]
    public function test_schema_names_every_lint_rule_the_registry_runs(): void
    {
        $ids = [];
        foreach ((new LintRuleRegistry())->all() as $rule) {
            foreach ($rule->getRuleIds() as $id) {
                $ids[] = substr($id, \strlen('regex.lint.'));
            }
        }
        sort($ids);

        $schema = LintConfigSchema::lintRuleIds();
        sort($schema);

        $this->assertSame($ids, $schema);
    }

    #[Test]
    public function test_schema_names_every_interop_preset(): void
    {
        $this->assertSame(InteropPresets::names(), LintConfigSchema::interopPresets());
    }

    #[Test]
    public function test_schema_names_every_output_format(): void
    {
        $definition = LintConfigSchema::definition();
        $properties = $definition['properties'] ?? null;
        $this->assertIsArray($properties);
        $format = $properties['format'] ?? null;
        $this->assertIsArray($format);

        $this->assertSame((new FormatterRegistry())->getNames(), $format['enum'] ?? null);
    }
}

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

namespace PHPRegex\Tests\Unit\Lint\Command;

use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintConfigSchema;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Tests\Support\TemporaryProject;
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

    private const PHP_VERSION_EXPECTED = 'a version like "8.3", a PHP_VERSION_ID like 80300, "runtime", a Composer constraint like ">=8.1", or {"min": "8.1", "max": "8.6"}';

    #[Test]
    #[DataProvider('provideMessages')]
    public function test_load_says_what_the_value_should_be(string $json, string $message): void
    {
        $error = (new LintConfigLoader())->load($this->makeProject(['regex.json' => $json]))->error;

        $this->assertNotNull($error);
        $this->assertStringContainsString($message, (string) $error);
        $this->assertStringNotContainsString('removed', (string) $error);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMessages(): iterable
    {
        yield 'boolean check form' => ['{"checks": {"redos": true}}', 'write {"enabled": true}'];
        yield 'wrong case of a key' => ['{"Paths": ["src"]}', 'Did you mean "paths"?'];
        yield 'snake_case option' => ['{"checks": {"optimizations": {"options": {"min_quantifier_count": 3}}}}', 'Did you mean "minQuantifierCount"?'];
        yield 'a mistyped key' => ['{"pths": ["src"]}', 'Did you mean "paths"?'];
        yield 'unknown lint rule' => ['{"checks": {"lint": {"rules": {"not.a.rule": true}}}}', 'Unknown lint rule "not.a.rule"'];
        yield 'misspelt lint rule' => ['{"checks": {"lint": {"rules": {"quantifier.nestd": true}}}}', 'Unknown lint rule "quantifier.nestd" in checks.lint.rules of '];
        yield 'misspelt lint rule, its suggestion' => ['{"checks": {"lint": {"rules": {"quantifier.nestd": true}}}}', 'Did you mean "quantifier.nested"?'];
        yield 'misspelt full lint rule' => ['{"checks": {"lint": {"rules": {"regex.lint.quantifier.nestd": true}}}}', 'Did you mean "regex.lint.quantifier.nested"?'];
        yield 'unknown full lint rule' => ['{"checks": {"lint": {"rules": {"regex.lint.not.a.rule": true}}}}', 'Unknown lint rule "regex.lint.not.a.rule"'];
        yield 'full lint rule, not a boolean' => ['{"checks": {"lint": {"rules": {"regex.lint.quantifier.useless": "no"}}}}', 'Invalid "checks.lint.rules.regex.lint.quantifier.useless" in '];
        yield 'one rule named twice' => ['{"checks": {"lint": {"rules": {"quantifier.useless": true, "regex.lint.quantifier.useless": false}}}}', 'Lint rule "quantifier.useless" is named twice in checks.lint.rules of '];
        yield 'integer minimum' => ['{"jobs": "four"}', 'expected an integer >= 1'];
        yield 'enum' => ['{"format": "xml"}', 'expected one of console, json, github, checkstyle, junit'];
        yield 'boolean' => ['{"checks": {"validation": "yes"}}', 'expected a boolean'];
        yield 'object' => ['{"extraction": []}', 'expected an object'];
        yield 'duplicate preset' => ['{"extraction": {"interop": ["nette-utils", "nette-utils"]}}', 'a list of distinct presets'];
        yield 'duplicate function' => ['{"extraction": {"functions": ["a", "a"]}}', 'a list of distinct non-empty strings'];
        yield 'unknown IDE' => ['{"ide": "notepad"}', 'a URL template holding a placeholder: %f or %file%, %relFile%, %l or %line%, %c or %column%'];
        yield 'template without a placeholder' => ['{"ide": "foo://x"}', 'Invalid "ide" in '];
        yield 'mode off' => ['{"checks": {"redos": {"mode": "off"}}}', 'checks.redos.enabled set to false turns the analysis off'];
        yield '1.x key, once removed with its replacement named' => ['{"minSavings": 2}', 'Unknown key "minSavings"'];
        yield '1.x key without a replacement' => ['{"redosNoJit": true}', 'Unknown key "redosNoJit"'];
        yield 'boolean complexity form' => ['{"checks": {"complexity": false}}', 'expected an object (write {"enabled": false})'];
        yield 'complexity threshold below 1' => ['{"checks": {"complexity": {"threshold": 0}}}', 'Invalid "checks.complexity.threshold" in '];
        yield 'PHP_VERSION_ID too small' => ['{"phpVersion": 83}', 'a PHP_VERSION_ID like 80300'];
        yield 'PHP_VERSION_ID below PHP 7' => ['{"phpVersion": 69999}', 'a PHP_VERSION_ID like 80300'];
        yield 'patch past 99' => ['{"phpVersion": "8.2.100"}', 'a version like "8.3"'];
    }

    /**
     * Each form phpVersion takes is named where a value is refused: a
     * single version, a PHP_VERSION_ID, "runtime", a Composer constraint
     * and the {"min", "max"} object.
     */
    #[Test]
    #[DataProvider('provideRefusedPhpVersions')]
    public function test_load_names_every_form_of_php_version_it_takes(string $json): void
    {
        $directory = $this->makeProject(['regex.json' => $json]);
        $error = (new LintConfigLoader())->load($directory)->error;

        $this->assertNotNull($error, 'The configuration should have been refused.');
        $this->assertStringContainsString('Invalid "phpVersion" in ', (string) $error);
        $this->assertStringContainsString('regex.json: expected '.self::PHP_VERSION_EXPECTED.'.', (string) $error);
    }

    /**
     * @return iterable<string, array{json: string}>
     */
    public static function provideRefusedPhpVersions(): iterable
    {
        yield 'not a version' => ['json' => '{"phpVersion": "eight"}'];
        yield 'a constraint without a lowest version' => ['json' => '{"phpVersion": "<8.4"}'];
        yield 'any version' => ['json' => '{"phpVersion": "*"}'];
        yield 'a branch name' => ['json' => '{"phpVersion": "dev-main"}'];
        yield 'a PHP_VERSION_ID inside a constraint' => ['json' => '{"phpVersion": ">=80100"}'];
        yield 'runtime inside a constraint' => ['json' => '{"phpVersion": "runtime || ^9.0"}'];
        yield 'a float' => ['json' => '{"phpVersion": 8.3}'];
        yield 'an empty object' => ['json' => '{"phpVersion": {}}'];
        yield 'an object without max' => ['json' => '{"phpVersion": {"min": "8.1"}}'];
        yield 'an object with another key' => ['json' => '{"phpVersion": {"min": "8.1", "max": "8.6", "x": 1}}'];
        yield 'a boolean bound' => ['json' => '{"phpVersion": {"min": true, "max": "8.6"}}'];
        yield 'runtime as a bound' => ['json' => '{"phpVersion": {"min": "runtime", "max": "8.6"}}'];
        // No schema compares two values: the loader refuses it, in the
        // same words.
        yield 'min above max, as strings' => ['json' => '{"phpVersion": {"min": "8.6", "max": "8.1"}}'];
        yield 'min above max, as PHP_VERSION_IDs' => ['json' => '{"phpVersion": {"min": 80600, "max": 80100}}'];
        // A two-part max covers its whole series: 8.4.25 is past "8.4" only
        // as a PHP_VERSION_ID.
        yield 'min above max, a patch past a PHP_VERSION_ID' => ['json' => '{"phpVersion": {"min": "8.4.25", "max": 80400}}'];
        // A constraint no PHP satisfies names no range, as min above max
        // does.
        yield 'a hyphen range written backwards' => ['json' => '{"phpVersion": "8.6 - 8.1"}'];
        yield 'an upper bound below the lowest version' => ['json' => '{"phpVersion": ">=8.4 <8.2"}'];
        yield 'strictly above the one version allowed' => ['json' => '{"phpVersion": ">8.4.24 <=8.4.24"}'];
        yield 'an upper bound below the lowest version, comma-separated' => ['json' => '{"phpVersion": ">=8.1,<8.0"}'];
        yield 'two exact versions ANDed' => ['json' => '{"phpVersion": "8.1 8.2"}'];
        // A version past PHP 9.99 is refused inside a constraint as alone.
        yield 'a constraint from PHP 10' => ['json' => '{"phpVersion": ">=10.0"}'];
        yield 'a constraint from PHP 99.1' => ['json' => '{"phpVersion": ">=99.1"}'];
        yield 'a constraint from a major past every integer' => ['json' => '{"phpVersion": ">=99999999999999999999.1"}'];
        // What every other reader of a named PHP version refuses too.
        yield 'a doubled separator' => ['json' => '{"phpVersion": ">=8.1,,<8.3"}'];
        yield 'a bound with a leading zero' => ['json' => '{"phpVersion": {"min": "08.1", "max": "8.3"}}'];
        yield 'a bound past PHP 9.99' => ['json' => '{"phpVersion": {"min": "8.1", "max": "10.0"}}'];
    }

    /**
     * The words the schema gives the editor are those the loader prints.
     */
    #[Test]
    public function test_schema_names_every_form_of_php_version_it_takes(): void
    {
        $definition = LintConfigSchema::definition();
        $this->assertIsArray($definition['properties'] ?? null);
        $this->assertIsArray($definition['properties']['phpVersion'] ?? null);

        $this->assertSame(self::PHP_VERSION_EXPECTED, $definition['properties']['phpVersion']['x-expected'] ?? null);
    }

    /**
     * A 1.x key is told about like any unknown key: its name and the file it
     * sits in, never what 2.0 replaced it with or that it was removed.
     */
    #[Test]
    public function test_load_names_no_replacement_for_a_1_x_key(): void
    {
        $error = (string) (new LintConfigLoader())->load($this->makeProject(['regex.json' => '{"minSavings": 2, "redosNoJit": true}']))->error;

        $this->assertNotSame('', $error);
        $this->assertStringNotContainsString('instead', $error);
        $this->assertStringNotContainsString('was removed', $error);
        $this->assertStringNotContainsString('has no replacement', $error);
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
        yield 'template with the long names' => ['myeditor://open?file=%file%&line=%line%&column=%column%'];
        yield 'template with the relative file' => ['myeditor://%relFile%'];
        yield 'template with a column alone' => ['foo://%c'];
    }

    /**
     * A rule is named in checks.lint.rules as the docs and the issues name
     * it, in full, or without "regex.lint.": the result names it short, so
     * that regex.json overrides what regex.dist.json says of it whatever the
     * spelling of either.
     */
    #[Test]
    public function test_load_reads_a_rule_named_in_full_under_its_short_id(): void
    {
        $result = (new LintConfigLoader())->load($this->makeProject([
            'regex.dist.json' => '{"checks": {"lint": {"rules": {"regex.lint.quantifier.useless": false, "charclass.single": true, "regex.lint.redos.search": false}}}}',
            'regex.json' => '{"checks": {"lint": {"rules": {"regex.lint.charclass.single": false, "quantifier.useless": true}}}}',
        ]));

        $this->assertNull($result->error);
        $this->assertSame(['checks' => ['lint' => ['rules' => ['quantifier.useless' => true, 'charclass.single' => false, 'redos.search' => false]]]], $result->config);
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

        $schema = RuleCatalog::lintRuleIds();
        sort($schema);

        $this->assertSame($ids, $schema);
    }

    #[Test]
    public function test_schema_names_every_interop_preset(): void
    {
        $definition = LintConfigSchema::definition();
        $this->assertIsArray($definition['properties'] ?? null);
        $this->assertIsArray($definition['properties']['extraction'] ?? null);
        $this->assertIsArray($definition['properties']['extraction']['properties'] ?? null);
        $interop = $definition['properties']['extraction']['properties']['interop'] ?? null;
        $this->assertIsArray($interop);
        $this->assertIsArray($interop['items'] ?? null);
        $this->assertIsArray($interop['items']['oneOf'] ?? null);

        $named = [];
        foreach ($interop['items']['oneOf'] as $preset) {
            $this->assertIsArray($preset);
            $this->assertIsString($preset['description'] ?? null);
            $this->assertNotSame('', $preset['description']);
            $named[] = $preset['const'] ?? null;
        }
        $this->assertSame(InteropPresets::names(), $named);
        $this->assertSame(InteropPresets::names(), $interop['default'] ?? null);
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

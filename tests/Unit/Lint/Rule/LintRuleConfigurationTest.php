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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\Config\LintConfigSchema;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every rule the linter runs can be turned on or off in regex.json, under
 * its id without the "regex.lint." prefix, and says whether it runs when
 * the file does not mention it: the bug rules do, the three style and perf
 * rules taken from SonarPHP do not.
 */
final class LintRuleConfigurationTest extends TestCase
{
    private const SCHEMA_FILE = __DIR__.'/../../../../src/Linter/regex.schema.json';

    #[Test]
    public function test_every_registered_rule_has_a_configuration_key(): void
    {
        $missing = array_values(array_diff(self::registeredKeys(), array_keys(LintConfigSchema::LINT_RULES)));

        $this->assertSame([], $missing, 'These rules cannot be configured in regex.json.');
    }

    #[Test]
    public function test_the_schema_file_lists_every_registered_rule(): void
    {
        $missing = array_values(array_diff(self::registeredKeys(), array_keys(self::schemaRules())));

        $this->assertSame([], $missing, 'These rules are missing from regex.schema.json.');
    }

    #[Test]
    #[DataProvider('provideNewRuleDefaults')]
    public function test_a_new_rule_runs_by_default_unless_it_is_a_style_or_perf_rule(string $key, bool $default): void
    {
        $this->assertArrayHasKey($key, LintConfigSchema::LINT_RULES);
        $this->assertSame($default, LintConfigSchema::LINT_RULES[$key][1]);

        $schemaRules = self::schemaRules();
        $this->assertArrayHasKey($key, $schemaRules);
        $this->assertIsArray($schemaRules[$key]);
        $this->assertSame($default, $schemaRules[$key]['default'] ?? null);
    }

    /**
     * The description of checks.lint.rules names the rules a project gets
     * only by turning them on: exactly those whose default is false, no
     * more, no fewer.
     */
    #[Test]
    public function test_the_rules_description_names_every_rule_off_by_default(): void
    {
        $definition = LintConfigSchema::definition();
        $this->assertIsArray($definition['$defs'] ?? null);
        $this->assertIsArray($definition['$defs']['checksLint'] ?? null);
        $this->assertIsArray($definition['$defs']['checksLint']['properties'] ?? null);
        $rules = $definition['$defs']['checksLint']['properties']['rules'] ?? null;
        $this->assertIsArray($rules);
        $this->assertIsString($rules['description'] ?? null);
        $this->assertIsArray($rules['properties'] ?? null);

        $off = [];
        foreach ($rules['properties'] as $key => $property) {
            $this->assertIsArray($property);
            if (false === ($property['default'] ?? null)) {
                $off[] = (string) $key;
            }
        }

        $this->assertSame(1, preg_match('/ except (?<list>.+)\.$/', $rules['description'], $matches), $rules['description']);
        $named = preg_split('/, | and /', $matches['list']);
        $this->assertIsArray($named);

        sort($off);
        sort($named);
        $this->assertSame(['anchor.dollarNewline', 'charclass.single', 'literal.multipleSpaces', 'lookaround.edgeQuantifier', 'lookaround.redundant', 'quantifier.lazyToClass', 'quantifier.uselessLazy', 'unicode.shorthandWithoutU'], $off);
        $this->assertSame($off, $named);
    }

    /**
     * @return iterable<string, array{key: string, default: bool}>
     */
    public static function provideNewRuleDefaults(): iterable
    {
        yield 'quantifier.emptyRepeat' => ['key' => 'quantifier.emptyRepeat', 'default' => true];
        yield 'anchor.alternationPrecedence' => ['key' => 'anchor.alternationPrecedence', 'default' => true];
        yield 'quantifier.possessiveImpossible' => ['key' => 'quantifier.possessiveImpossible', 'default' => true];
        yield 'anchor.impossible.boundary' => ['key' => 'anchor.impossible.boundary', 'default' => true];
        yield 'lookaround.impossible' => ['key' => 'lookaround.impossible', 'default' => true];
        yield 'group.empty' => ['key' => 'group.empty', 'default' => true];
        yield 'charclass.single' => ['key' => 'charclass.single', 'default' => false];
        yield 'literal.multipleSpaces' => ['key' => 'literal.multipleSpaces', 'default' => false];
        yield 'quantifier.lazyToClass' => ['key' => 'quantifier.lazyToClass', 'default' => false];
        yield 'anchor.dollarNewline' => ['key' => 'anchor.dollarNewline', 'default' => false];
        yield 'literal.invisible' => ['key' => 'literal.invisible', 'default' => true];
        yield 'lookaround.redundant' => ['key' => 'lookaround.redundant', 'default' => false];
    }

    /**
     * A rules map that does not mention a rule runs it as the schema says
     * it runs by default: one list of the rules off by default.
     */
    #[Test]
    public function test_the_linter_runs_by_default_exactly_the_rules_the_schema_turns_on(): void
    {
        foreach (LintConfigSchema::LINT_RULES as $key => [, $default]) {
            $this->assertSame($default, PatternLinter::enables([], $key), $key);
            $this->assertSame($default, PatternLinter::enables([], 'regex.lint.'.$key), $key);
            $this->assertSame(!$default, PatternLinter::enables([$key => !$default], $key), $key);
        }
    }

    /**
     * An analysis issue the rules map turns off like a lint rule is on
     * unless the map says false for it, under its full identifier or
     * without "regex.lint.", as regex.json writes it.
     *
     * @param array<string, bool> $rules
     */
    #[Test]
    #[DataProvider('provideAnalysisIssueRules')]
    public function test_an_analysis_issue_is_on_unless_the_rules_map_turns_it_off(array $rules, bool $expected): void
    {
        $this->assertSame($expected, LintConfigSchema::leavesOn($rules, 'regex.lint.redos.search'));
    }

    /**
     * @return iterable<string, array{rules: array<string, bool>, expected: bool}>
     */
    public static function provideAnalysisIssueRules(): iterable
    {
        yield 'not mentioned' => ['rules' => [], 'expected' => true];
        yield 'another rule off' => ['rules' => ['compat.meaningChanges' => false], 'expected' => true];
        yield 'on by its short id' => ['rules' => ['redos.search' => true], 'expected' => true];
        yield 'off by its short id' => ['rules' => ['redos.search' => false], 'expected' => false];
        yield 'off by its full id' => ['rules' => ['regex.lint.redos.search' => false], 'expected' => false];
        yield 'on by one id, off by the other' => ['rules' => ['regex.lint.redos.search' => true, 'redos.search' => false], 'expected' => false];
    }

    /**
     * @return list<string>
     */
    private static function registeredKeys(): array
    {
        $keys = [];
        foreach ((new LintRuleRegistry())->all() as $rule) {
            foreach ($rule->getRuleIds() as $id) {
                self::assertStringStartsWith('regex.lint.', $id);
                $keys[] = substr($id, \strlen('regex.lint.'));
            }
        }

        return $keys;
    }

    /**
     * The properties of checks.lint.rules in regex.schema.json.
     *
     * @return array<mixed>
     */
    private static function schemaRules(): array
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA_FILE), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);

        $rules = self::findRules($schema);
        self::assertIsArray($rules, 'regex.schema.json has no checks.lint.rules properties.');

        return $rules;
    }

    /**
     * The "properties" of the first "rules" object found, wherever the
     * schema nests it.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>|null
     */
    private static function findRules(array $node): ?array
    {
        foreach ($node as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }

            if ('rules' === $key && \is_array($value['properties'] ?? null) && isset($value['properties']['unicode.shorthandWithoutU'])) {
                return $value['properties'];
            }

            $found = self::findRules($value);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }
}

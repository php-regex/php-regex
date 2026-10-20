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
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One catalogue holds what the library knows of each lint rule and each
 * analysis issue: its identifier, what it reports, whether it runs when
 * regex.json does not mention it, and the page that documents it.
 */
final class RuleCatalogTest extends TestCase
{
    /**
     * The catalogue lists the rules the registry runs, no more, no fewer,
     * in full as the issues carry them.
     */
    #[Test]
    public function test_the_catalogue_lists_exactly_the_rules_the_registry_runs(): void
    {
        $registered = [];
        foreach ((new LintRuleRegistry())->all() as $rule) {
            foreach ($rule->getRuleIds() as $id) {
                $registered[] = substr($id, \strlen('regex.lint.'));
            }
        }

        $catalogued = RuleCatalog::lintRuleIds();
        sort($registered);
        sort($catalogued);

        $this->assertSame($catalogued, $registered);
    }

    /**
     * Each rule names what it reports through its own constants, and
     * reports nothing else: ID for a rule of one identifier, ID_* for one
     * of several.
     */
    #[Test]
    public function test_every_rule_declares_each_identifier_it_reports_as_a_public_constant(): void
    {
        foreach ((new LintRuleRegistry())->all() as $rule) {
            $reflection = new \ReflectionClass($rule);
            $constants = [];
            foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ('ID' === $constant->getName() || str_starts_with($constant->getName(), 'ID_')) {
                    $constants[] = $constant->getValue();
                }
            }

            $this->assertSame($rule->getRuleIds(), $constants, $rule::class);
        }
    }

    #[Test]
    public function test_the_rules_off_by_default_are_the_style_perf_and_info_ones(): void
    {
        $this->assertSame([
            'unicode.shorthandWithoutU',
            'charclass.single',
            'literal.multipleSpaces',
            'quantifier.lazyToClass',
            'quantifier.uselessLazy',
            'lookaround.edgeQuantifier',
            'anchor.dollarNewline',
            'lookaround.redundant',
        ], RuleCatalog::rulesOffByDefault());
    }

    /**
     * @return iterable<string, array{id: string, configurable: bool}>
     */
    public static function provideConfigurableIdentifiers(): iterable
    {
        yield 'a lint rule, short' => ['id' => 'quantifier.useless', 'configurable' => true];
        yield 'a lint rule, in full' => ['id' => 'regex.lint.quantifier.useless', 'configurable' => true];
        yield 'an analysis issue the rules map turns off, short' => ['id' => 'redos.search', 'configurable' => true];
        yield 'an analysis issue the rules map turns off, in full' => ['id' => 'regex.lint.compat.meaningChanges', 'configurable' => true];
        yield 'the ReDoS verdict, which its own check turns off' => ['id' => 'redos', 'configurable' => false];
        yield 'the complexity warning, which its own check turns off' => ['id' => 'regex.lint.complexity', 'configurable' => false];
        yield 'a misspelt rule' => ['id' => 'quantifier.nestd', 'configurable' => false];
        yield 'the prefix alone' => ['id' => 'regex.lint.', 'configurable' => false];
        yield 'the prefix twice' => ['id' => 'regex.lint.regex.lint.quantifier.useless', 'configurable' => false];
        yield 'another case' => ['id' => 'Quantifier.Useless', 'configurable' => false];
    }

    #[Test]
    #[DataProvider('provideConfigurableIdentifiers')]
    public function test_a_rules_map_may_name_a_lint_rule_or_an_analysis_issue_in_either_spelling(string $id, bool $configurable): void
    {
        $this->assertSame($configurable, RuleCatalog::isConfigurable($id));
    }

    /**
     * @return iterable<string, array{id: string, suggestion: string|null}>
     */
    public static function provideMisspeltIdentifiers(): iterable
    {
        yield 'a letter off, short' => ['id' => 'quantifier.nestd', 'suggestion' => 'quantifier.nested'];
        yield 'a letter off, in full' => ['id' => 'regex.lint.quantifier.nestd', 'suggestion' => 'regex.lint.quantifier.nested'];
        yield 'another case' => ['id' => 'Quantifier.Useless', 'suggestion' => 'quantifier.useless'];
        yield 'an analysis issue a letter off' => ['id' => 'redos.serch', 'suggestion' => 'redos.search'];
        yield 'nothing near' => ['id' => 'completely.different', 'suggestion' => null];
    }

    /**
     * A name that is no rule is told the one it most likely means, in the
     * spelling it was written in.
     */
    #[Test]
    #[DataProvider('provideMisspeltIdentifiers')]
    public function test_a_misspelt_identifier_is_told_the_nearest_one(string $id, ?string $suggestion): void
    {
        $this->assertSame($suggestion, RuleCatalog::suggestion($id));
    }

    /**
     * @return iterable<string, array{id: string, url: string|null}>
     */
    public static function provideDocsUrls(): iterable
    {
        yield 'a lint rule' => ['id' => 'regex.lint.quantifier.useless', 'url' => 'https://php-regex.com/reference/rules/#useless-quantifier'];
        yield 'a rule with no link before' => ['id' => 'regex.lint.backref.undefined', 'url' => 'https://php-regex.com/reference/rules/#undefined-backreference'];
        yield 'the ReDoS verdict' => ['id' => RedosVerdict::ISSUE_ID, 'url' => 'https://php-regex.com/reference/rules/#catastrophic-backtracking'];
        yield 'the cost of a search' => ['id' => RedosVerdict::SEARCH_ISSUE_ID, 'url' => 'https://php-regex.com/reference/rules/#quadratic-search'];
        yield 'the complexity warning' => ['id' => RuleCatalog::COMPLEXITY, 'url' => 'https://php-regex.com/reference/rules/#complexity-threshold'];
        yield 'a source the lint cannot read' => ['id' => RuleCatalog::SOURCE_UNREADABLE, 'url' => 'https://php-regex.com/reference/rules/#unreadable-source-file'];
        yield 'a marker that silences nothing' => ['id' => LintService::ISSUE_ID_UNMATCHED_IGNORE, 'url' => 'https://php-regex.com/guides/cli/#markers-that-silence-nothing'];
        yield 'a target marker that keeps nothing' => ['id' => LintService::ISSUE_ID_TARGET_EMPTY, 'url' => 'https://php-regex.com/guides/cli/#code-that-runs-on-some-php-versions-only'];
        yield 'a rule written short' => ['id' => 'quantifier.useless', 'url' => null];
        yield 'an error code of an invalid pattern' => ['id' => 'regex.group.unclosed', 'url' => null];
        yield 'an unknown rule' => ['id' => 'regex.lint.quantifier.nestd', 'url' => null];
    }

    /**
     * An issue the library reports links to its section of the docs site;
     * the identifier is read as the issue carries it.
     */
    #[Test]
    #[DataProvider('provideDocsUrls')]
    public function test_an_issue_links_to_its_section_of_the_docs(string $id, ?string $url): void
    {
        $this->assertSame($url, RuleCatalog::docsUrl($id));
    }

    #[Test]
    public function test_every_issue_of_the_catalogue_links_to_the_docs_site(): void
    {
        $this->assertNotSame([], RuleCatalog::docsUrls());
        foreach (RuleCatalog::docsUrls() as $id => $url) {
            $this->assertStringStartsWith('https://php-regex.com/', $url, $id);
            $this->assertStringContainsString('/#', $url, $id);
        }
        foreach (RuleCatalog::lintRuleIds() as $id) {
            $this->assertArrayHasKey('regex.lint.'.$id, RuleCatalog::docsUrls());
        }
    }

    /**
     * A rules map turns a check on or off under either spelling: false
     * under one of them wins, an id it does not name runs as the catalogue
     * runs it by default, and an id the catalogue does not know runs.
     *
     * @param array<string, bool> $rules
     */
    #[Test]
    #[DataProvider('provideRulesMaps')]
    public function test_a_rules_map_turns_a_check_on_or_off_under_either_spelling(array $rules, string $id, bool $expected): void
    {
        $this->assertSame($expected, RuleCatalog::enables($rules, $id));
        $this->assertSame($expected, RuleCatalog::enables($rules, 'regex.lint.'.$id));
    }

    /**
     * @return iterable<string, array{rules: array<string, bool>, id: string, expected: bool}>
     */
    public static function provideRulesMaps(): iterable
    {
        yield 'on by default, not named' => ['rules' => [], 'id' => 'quantifier.useless', 'expected' => true];
        yield 'off by default, not named' => ['rules' => [], 'id' => 'quantifier.uselessLazy', 'expected' => false];
        yield 'turned off, short' => ['rules' => ['quantifier.useless' => false], 'id' => 'quantifier.useless', 'expected' => false];
        yield 'turned off, in full' => ['rules' => ['regex.lint.quantifier.useless' => false], 'id' => 'quantifier.useless', 'expected' => false];
        yield 'turned on, short' => ['rules' => ['quantifier.uselessLazy' => true], 'id' => 'quantifier.uselessLazy', 'expected' => true];
        yield 'turned on, in full' => ['rules' => ['regex.lint.quantifier.uselessLazy' => true], 'id' => 'quantifier.uselessLazy', 'expected' => true];
        yield 'on under one spelling, off under the other' => ['rules' => ['regex.lint.quantifier.useless' => true, 'quantifier.useless' => false], 'id' => 'quantifier.useless', 'expected' => false];
        yield 'another rule turned off' => ['rules' => ['quantifier.zero' => false], 'id' => 'quantifier.useless', 'expected' => true];
        yield 'an analysis issue, not named' => ['rules' => [], 'id' => 'redos.search', 'expected' => true];
        yield 'an analysis issue turned off' => ['rules' => ['redos.search' => false], 'id' => 'redos.search', 'expected' => false];
        yield 'an unknown id' => ['rules' => [], 'id' => 'no.such.rule', 'expected' => true];
    }

    /**
     * The regex.json schema reads its rules, their descriptions and their
     * defaults from the catalogue, and names the rules off by default in
     * the description of the map.
     */
    #[Test]
    public function test_the_schema_reads_the_rules_from_the_catalogue(): void
    {
        $definition = LintConfigSchema::definition();
        $this->assertIsArray($definition['$defs'] ?? null);
        $this->assertIsArray($definition['$defs']['checksLint'] ?? null);
        $this->assertIsArray($definition['$defs']['checksLint']['properties'] ?? null);
        $rules = $definition['$defs']['checksLint']['properties']['rules'] ?? null;
        $this->assertIsArray($rules);
        $this->assertIsArray($rules['properties'] ?? null);

        $this->assertSame([...RuleCatalog::lintRuleIds(), 'redos.search', 'compat.meaningChanges'], array_keys($rules['properties']));
        foreach ($rules['properties'] as $id => $property) {
            $this->assertIsArray($property);
            $this->assertSame(RuleCatalog::description((string) $id), $property['description'] ?? null, (string) $id);
            $this->assertSame(RuleCatalog::enables([], (string) $id), $property['default'] ?? null, (string) $id);
        }

        $this->assertIsString($rules['description'] ?? null);
        $this->assertStringEndsWith('except '.implode(', ', \array_slice(RuleCatalog::rulesOffByDefault(), 0, -1)).' and '.RuleCatalog::rulesOffByDefault()[7].'.', $rules['description']);
    }
}

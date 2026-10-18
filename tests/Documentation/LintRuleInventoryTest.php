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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the package pages say about the lint rules holds: the count of
 * rules they give is the count the registry holds, and the Linter
 * CHANGELOG names every rule that is off by default, in an entry of its
 * own and in its list of them.
 */
final class LintRuleInventoryTest extends TestCase
{
    private const ROOT = __DIR__.'/../../';

    private const LINTER_CHANGELOG = self::ROOT.'src/Linter/CHANGELOG.md';

    #[Test]
    #[DataProvider('providePagesCountingRules')]
    public function test_a_page_counts_the_rules_the_registry_holds(string $page): void
    {
        $text = (string) file_get_contents(self::ROOT.$page);

        $this->assertSame(1, preg_match_all('/\b(\d+) (?:lint )?rules\b/', $text, $counts), $page.' gives one count of rules.');
        $this->assertSame((string) \count(self::ruleIds()), $counts[1][0], $page);
    }

    /**
     * @return iterable<string, array{page: string}>
     */
    public static function providePagesCountingRules(): iterable
    {
        foreach (['src/Linter/README.md', 'src/Cli/README.md', 'src/PHPStan/README.md'] as $page) {
            yield $page => ['page' => $page];
        }
    }

    #[Test]
    #[DataProvider('provideRulesOffByDefault')]
    public function test_the_linter_changelog_names_each_rule_off_by_default(string $rule): void
    {
        $changelog = (string) file_get_contents(self::LINTER_CHANGELOG);

        $this->assertMatchesRegularExpression('/^ \* `regex\.lint\.'.preg_quote($rule, '/').'`(?:(?!\n \* ).)*?off by default/ms', $changelog, $rule.' has an entry that says it is off by default.');

        $this->assertSame(1, preg_match('/^ \* The rules off by default are ((?:(?!\n \* ).)+?);/ms', $changelog, $list));
        preg_match_all('/`([^`]+)`/', $list[1], $listed);
        $this->assertContains($rule, $listed[1]);
    }

    /**
     * @return iterable<string, array{rule: string}>
     */
    public static function provideRulesOffByDefault(): iterable
    {
        $disabled = (new \ReflectionClassConstant(PatternLinter::class, 'DEFAULT_DISABLED_RULES'))->getValue();
        self::assertIsArray($disabled);

        foreach (array_keys($disabled) as $rule) {
            yield (string) $rule => ['rule' => (string) $rule];
        }
    }

    /**
     * @return list<string>
     */
    private static function ruleIds(): array
    {
        $ids = [];
        foreach ((new LintRuleRegistry())->all() as $rule) {
            foreach ($rule->getRuleIds() as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}

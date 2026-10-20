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

use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Linter\Rule\RuleId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleIdTest extends TestCase
{
    /**
     * @return iterable<string, array{id: string, short: string, full: string, lintRule: bool}>
     */
    public static function provideIdentifiers(): iterable
    {
        yield 'a full rule identifier' => ['id' => 'regex.lint.quantifier.useless', 'short' => 'quantifier.useless', 'full' => 'regex.lint.quantifier.useless', 'lintRule' => true];
        yield 'a short rule identifier' => ['id' => 'quantifier.useless', 'short' => 'quantifier.useless', 'full' => 'regex.lint.quantifier.useless', 'lintRule' => false];
        yield 'a full rule identifier off by default' => ['id' => 'regex.lint.quantifier.uselessLazy', 'short' => 'quantifier.uselessLazy', 'full' => 'regex.lint.quantifier.uselessLazy', 'lintRule' => true];
        yield 'an analysis identifier under the prefix' => ['id' => 'regex.lint.complexity', 'short' => 'complexity', 'full' => 'regex.lint.complexity', 'lintRule' => false];
        yield 'an identifier of no lint check' => ['id' => 'regex.optimization', 'short' => 'regex.optimization', 'full' => 'regex.lint.regex.optimization', 'lintRule' => false];
        yield 'a misspelt rule' => ['id' => 'regex.lint.quantifier.nestd', 'short' => 'quantifier.nestd', 'full' => 'regex.lint.quantifier.nestd', 'lintRule' => false];
        yield 'the prefix only' => ['id' => 'regex.lint.', 'short' => '', 'full' => 'regex.lint.', 'lintRule' => false];
        yield 'the prefix inside' => ['id' => 'x.regex.lint.a', 'short' => 'x.regex.lint.a', 'full' => 'regex.lint.x.regex.lint.a', 'lintRule' => false];
    }

    #[Test]
    #[DataProvider('provideIdentifiers')]
    public function test_an_identifier_is_spelled_with_and_without_the_prefix(string $id, string $short, string $full, bool $lintRule): void
    {
        $this->assertSame($short, RuleId::short($id));
        $this->assertSame($full, RuleId::full($id));
        $this->assertSame($lintRule, RuleId::isLintRule($id));
    }

    #[Test]
    public function test_a_rules_map_is_read_under_either_spelling(): void
    {
        $this->assertFalse(RuleCatalog::enables(['quantifier.useless' => false], 'regex.lint.quantifier.useless'));
        $this->assertFalse(RuleCatalog::enables(['quantifier.useless' => false], 'quantifier.useless'));
        $this->assertTrue(RuleCatalog::enables(['quantifier.uselessLazy' => true], 'regex.lint.quantifier.uselessLazy'));
        $this->assertFalse(RuleCatalog::enables([], 'regex.lint.quantifier.uselessLazy'));
        $this->assertTrue(RuleCatalog::enables([], 'regex.lint.quantifier.useless'));
        $this->assertTrue(RuleCatalog::enables([], 'regex.lint.no.such.rule'));
    }
}

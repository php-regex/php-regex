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

namespace PHPRegex\Tests\Unit\Linter;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\AbstractLintRule;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A rule whose every identifier is turned off does no work: the linter
 * neither calls it on the nodes it reads nor at the start and the end of a
 * pattern, where it used to run it and drop what it found. A rule with one
 * identifier on still runs.
 */
final class DisabledRuleDispatchTest extends TestCase
{
    /**
     * @param non-empty-list<string> $ids
     * @param array<string, bool>    $enabled
     */
    #[Test]
    #[DataProvider('provideConfigurations')]
    public function test_a_rule_runs_when_one_of_its_identifiers_is_on(array $ids, array $enabled, bool $runs): void
    {
        $rule = new class($ids) extends AbstractLintRule {
            public int $calls = 0;

            /**
             * @param non-empty-list<string> $ids
             */
            public function __construct(private readonly array $ids) {}

            public function getRuleIds(): array
            {
                return $this->ids;
            }

            public function getNodeTypes(): array
            {
                return [LiteralNode::class];
            }

            public function begin(LintContext $context): void
            {
                $this->calls++;
            }

            public function check(NodeInterface $node, LintContext $context): array
            {
                $this->calls++;

                return [new RuleViolation($this->ids[0], 'seen')];
            }

            public function finish(LintContext $context): array
            {
                $this->calls++;

                return [];
            }
        };
        $registry = new LintRuleRegistry();
        $registry->register($rule);

        $linter = PatternLinter::withRegistry($enabled, $registry);
        Regex::create(['cache' => null])->parse('/ab/')->accept($linter);

        $this->assertSame($runs ? 4 : 0, $rule->calls);
    }

    /**
     * @return iterable<string, array{ids: non-empty-list<string>, enabled: array<string, bool>, runs: bool}>
     */
    public static function provideConfigurations(): iterable
    {
        yield 'on by default' => ['ids' => ['regex.lint.test.one'], 'enabled' => [], 'runs' => true];
        yield 'turned off' => ['ids' => ['regex.lint.test.one'], 'enabled' => ['test.one' => false], 'runs' => false];
        yield 'turned off with its prefix' => ['ids' => ['regex.lint.test.one'], 'enabled' => ['regex.lint.test.one' => false], 'runs' => true];
        yield 'one of two turned off' => ['ids' => ['regex.lint.test.one', 'regex.lint.test.two'], 'enabled' => ['test.one' => false], 'runs' => true];
        yield 'both turned off' => ['ids' => ['regex.lint.test.one', 'regex.lint.test.two'], 'enabled' => ['test.one' => false, 'test.two' => false], 'runs' => false];
        yield 'off by default' => ['ids' => ['regex.lint.quantifier.uselessLazy'], 'enabled' => [], 'runs' => false];
        yield 'off by default, turned on' => ['ids' => ['regex.lint.quantifier.uselessLazy'], 'enabled' => ['quantifier.uselessLazy' => true], 'runs' => true];
    }
}

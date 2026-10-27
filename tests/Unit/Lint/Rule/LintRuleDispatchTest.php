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

use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\LintRuleInterface;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The linter hands each rule the nodes of the types it names, and only
 * those; a rule handed any other node reports nothing, whatever the node.
 * Each rule of the registry is handed every node of a parsed pattern that
 * holds most kinds of node, save the ones it subscribes to.
 */
final class LintRuleDispatchTest extends TestCase
{
    /**
     * Groups of each kind, a class with a range and a POSIX class, types,
     * escapes, anchors, a reference, a call, a conditional, a lookaround,
     * a quantifier, a comment, a verb and a run of spaces.
     */
    private const PATTERN = '/^(a)(?<n>b)[c-e[:alpha:]]\d.\x41\cA\1\k<n>(?1)(?(1)f|g)(?=h)(?:i|j)*k+?(?#note)(*SKIP)\pL  l$/u';

    #[Test]
    #[DataProvider('provideRules')]
    public function test_a_rule_handed_a_node_it_does_not_subscribe_to_reports_nothing(string $rule): void
    {
        // Oracle: the pattern compiles.
        $this->assertNotFalse(preg_match(self::PATTERN, ''));

        $instance = self::ruleNamed($rule);
        $tree = Regex::create(['cache' => null])->parse(self::PATTERN);
        $context = new LintContext(
            new PatternInfo($tree->flags, $tree->delimiter, '', true),
            new GroupIndex(0, [], [], [], false),
            new CharSetAnalyzer($tree->flags),
        );
        $instance->begin($context);

        $handed = 0;
        foreach (self::nodesOf($tree->pattern) as $node) {
            if (self::subscribes($instance, $node)) {
                continue;
            }

            $this->assertSame([], $instance->check($node, $context), $node::class);
            $handed++;
        }

        $this->assertGreaterThan(0, $handed, 'The pattern holds a node the rule does not subscribe to.');
    }

    /**
     * @return iterable<string, array{rule: class-string<LintRuleInterface>}>
     */
    public static function provideRules(): iterable
    {
        foreach ((new LintRuleRegistry())->all() as $rule) {
            yield (new \ReflectionClass($rule))->getShortName() => ['rule' => $rule::class];
        }
    }

    /**
     * @return LintRuleInterface<NodeInterface>
     */
    private static function ruleNamed(string $class): LintRuleInterface
    {
        foreach ((new LintRuleRegistry())->all() as $rule) {
            if ($rule::class === $class) {
                return $rule;
            }
        }

        throw new \LogicException('No rule '.$class.' in the registry.');
    }

    /**
     * @param LintRuleInterface<NodeInterface> $rule
     */
    private static function subscribes(LintRuleInterface $rule, NodeInterface $node): bool
    {
        foreach ($rule->getNodeTypes() as $type) {
            if ($node instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Generator<int, NodeInterface>
     */
    private static function nodesOf(NodeInterface $node): \Generator
    {
        yield $node;
        foreach ($node->getChildren() as $child) {
            yield from self::nodesOf($child);
        }
    }
}

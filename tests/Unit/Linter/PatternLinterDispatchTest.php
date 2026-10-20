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
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The linter hands every node it walks to the rules subscribed to its
 * class, the operations of an extended class among them.
 */
final class PatternLinterDispatchTest extends TestCase
{
    #[Test]
    public function test_an_operation_of_an_extended_class_reaches_the_rules_subscribed_to_it(): void
    {
        $rule = new class extends AbstractLintRule {
            public function getRuleIds(): array
            {
                return ['test.dispatch'];
            }

            public function getNodeTypes(): array
            {
                return [ExtendedCharClassNode::class, ClassSetOperationNode::class, CharClassNode::class];
            }

            public function check(NodeInterface $node, LintContext $context): array
            {
                return [new RuleViolation('test.dispatch', (new \ReflectionClass($node))->getShortName(), $node->getStartPosition())];
            }
        };
        $registry = new LintRuleRegistry();
        $registry->register($rule);

        $linter = PatternLinter::withRegistry([], $registry);
        Regex::create(['php_version' => '8.4', 'pcre_version' => '10.45'])->parse('/(?[ [a-z] & [aeiou] ])/')->accept($linter);

        $this->assertSame(
            ['ExtendedCharClassNode', 'ClassSetOperationNode', 'CharClassNode', 'CharClassNode'],
            array_map(static fn (RuleViolation $issue): string => $issue->message, $linter->getIssues()),
        );
    }
}

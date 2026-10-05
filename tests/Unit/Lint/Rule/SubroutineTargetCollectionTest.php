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

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\AbstractLintRule;
use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LazyEndRule;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which group a subroutine call runs again, read as PCRE numbers and names
 * groups. A lazy quantifier that ends the pattern is reported unless its
 * group is the one a call reaches.
 */
final class SubroutineTargetCollectionTest extends TestCase
{
    private const LAZY_END = 'regex.lint.quantifier.lazyEnd';

    /**
     * Each row: the pattern, a subject, the engine's match, and whether the
     * lazy quantifier is reported. A reported one stops at its minimum in
     * the match; one a call reaches takes more there.
     *
     * @return iterable<string, array{pattern: string, subject: string, match: string, reported: bool}>
     */
    public static function provideCalls(): iterable
    {
        // Under (?J) two groups share a name, and (?&n) calls the first.
        yield 'call by a duplicated name reaches the first group' => ['pattern' => '/(?J)(?<n>b)(?&n)x(?<n>a+?)/', 'subject' => 'bbxaaa', 'match' => 'bbxa', 'reported' => true];
        // A branch reset with one alternative still numbers its group.
        yield 'branch reset with one alternative' => ['pattern' => '/(?1)x(?|(a+?))/', 'subject' => 'aaaxa', 'match' => 'aaaxa', 'reported' => false];
        // Both alternatives hold group 1, so the group after them is 2.
        yield 'group after a branch reset, called before it' => ['pattern' => '/(?2)x(?|(a)|(b))(c+?)/', 'subject' => 'cccxac', 'match' => 'cccxac', 'reported' => false];
        yield 'group after a branch reset, called after it' => ['pattern' => '/(?|(a)|(b))(?2)x(c+?)/', 'subject' => 'acccxc', 'match' => 'acccxc', 'reported' => false];
        // A call by name is no recursion of the whole pattern.
        yield 'call by name outside the lazy group' => ['pattern' => '/(?&n)x(?<n>b)a+?/', 'subject' => 'bxbaaa', 'match' => 'bxba', 'reported' => true];
    }

    #[Test]
    #[DataProvider('provideCalls')]
    public function test_lazy_end_reads_which_group_a_call_reaches(string $pattern, string $subject, string $match, bool $reported): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches));
        $this->assertSame($match, $matches[0]);

        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($reported, \in_array(self::LAZY_END, $ids, true), $pattern);
    }

    /**
     * Control for the rows above: with the called group taking one
     * character, the calls fail or match less.
     */
    #[Test]
    public function test_the_called_groups_take_more_than_one_character(): void
    {
        $this->assertSame(1, preg_match('/(?1)x(?|(a))/', 'aaaxa', $matches));
        $this->assertSame('axa', $matches[0]);
        $this->assertSame(1, preg_match('/(?2)x(?|(a)|(b))(c)/', 'cccxac', $matches));
        $this->assertSame('cxac', $matches[0]);
        $this->assertSame(0, preg_match('/(?|(a)|(b))(?2)x(c)/', 'acccxc'));
    }

    /**
     * A rule registered for subroutine calls receives them.
     */
    #[Test]
    public function test_a_rule_on_subroutine_calls_is_dispatched(): void
    {
        $registry = new LintRuleRegistry();
        $registry->register(new class extends AbstractLintRule {
            public function getRuleIds(): array
            {
                return ['test.subroutine'];
            }

            public function getNodeTypes(): array
            {
                return [SubroutineNode::class];
            }

            public function check(NodeInterface $node, LintContext $context): array
            {
                return $node instanceof SubroutineNode ? [new RuleViolation('test.subroutine', 'call to '.$node->reference)] : [];
            }
        });

        $linter = new PatternLinter([], $registry);
        Regex::create()->parse('/(a)(?1)(?&n)(?<n>b)/')->accept($linter);
        $messages = array_map(static fn ($issue): string => $issue->message, array_values(array_filter($linter->getIssues(), static fn ($issue): bool => 'test.subroutine' === $issue->id)));

        $this->assertSame(['call to 1', 'call to n'], $messages);
    }

    /**
     * A rule run outside the linter, with a group index built without the
     * subroutine facts, treats the pattern as not recursive: "a+?" at the
     * end stops at its minimum.
     */
    #[Test]
    public function test_a_group_index_without_subroutine_facts_does_not_claim_recursion(): void
    {
        $this->assertSame(1, preg_match('/a+?/', 'aaa', $matches));
        $this->assertSame('a', $matches[0]);

        $groups = new GroupIndex(0, [], [], [], false);
        $this->assertFalse($groups->recurses);

        $context = new LintContext(new PatternInfo('', '/', '', false), $groups, new CharSetAnalyzer(''));
        $lazy = new QuantifierNode(new LiteralNode('a', 0, 1), '+', QuantifierType::Lazy, 0, 3);

        $this->assertCount(1, (new LazyEndRule())->check($lazy, $context));
    }
}

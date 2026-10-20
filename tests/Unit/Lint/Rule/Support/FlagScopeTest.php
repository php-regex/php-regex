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

namespace PHPRegex\Tests\Unit\Lint\Rule\Support;

use PHPRegex\Linter\Rule\Support\FlagScope;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Tests\Unit\Lint\Rule\InlineFlagScopeTest;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlagScopeTest extends TestCase
{
    #[Test]
    #[DataProviderExternal(InlineFlagScopeTest::class, 'provideFlagScopes')]
    public function test_the_flags_at_a_node_follow_the_scope_of_each_inline_flag(string $pattern, string $subject, int $matches, string $flag, bool $active): void
    {
        $this->assertSame($matches, preg_match($pattern, $subject));

        $regex = Regex::create()->parse($pattern);
        $targets = self::dotsAndAnchors($regex->pattern);
        $this->assertNotSame([], $targets);

        foreach ($targets as $target) {
            $flags = FlagScope::flagsAt($target, $regex->pattern, $regex->flags);
            $this->assertNotNull($flags);
            $this->assertSame($active, str_contains($flags, $flag), \sprintf('%s: flags "%s".', $pattern, $flags));
        }
    }

    #[Test]
    public function test_a_node_outside_the_tree_has_no_flags(): void
    {
        $regex = Regex::create()->parse('/a(?s)b/');

        $this->assertNull(FlagScope::flagsAt(new DotNode(0, 1), $regex->pattern, 'i'));
    }

    #[Test]
    public function test_each_alternative_reads_the_flags_the_ones_before_it_set(): void
    {
        $regex = Regex::create()->parse('/a(?i)|b(?-i)(?m)|c|(?s:d)/');
        $this->assertInstanceOf(AlternationNode::class, $regex->pattern);

        $this->assertSame(['x', 'xi', 'xm', 'xm'], array_values(FlagScope::flagsAtEachChild($regex->pattern, 'x')));
    }

    #[Test]
    public function test_each_item_reads_the_flags_the_standalone_groups_before_it_set(): void
    {
        $regex = Regex::create()->parse('/a(?i)b(?^s)c(?m:d)e/');
        $this->assertInstanceOf(SequenceNode::class, $regex->pattern);

        $this->assertSame(['x', 'x', 'xi', 'xi', 's', 's', 's'], array_values(FlagScope::flagsAtEachChild($regex->pattern, 'x')));
    }

    #[Test]
    public function test_only_a_scoped_group_sets_flags_inside_itself(): void
    {
        $scoped = Regex::create()->parse('/(?i:a)/')->pattern;
        $standalone = Regex::create()->parse('/(?i)/')->pattern;
        $capturing = Regex::create()->parse('/(a)/')->pattern;
        $this->assertInstanceOf(GroupNode::class, $scoped);
        $this->assertInstanceOf(GroupNode::class, $standalone);
        $this->assertInstanceOf(GroupNode::class, $capturing);

        $this->assertSame('mi', FlagScope::flagsInside($scoped, 'm'));
        $this->assertSame('m', FlagScope::flagsInside($standalone, 'm'));
        $this->assertSame('m', FlagScope::flagsInside($capturing, 'm'));
    }

    #[Test]
    public function test_only_a_standalone_group_sets_flags_after_itself(): void
    {
        $this->assertSame('mi', FlagScope::flagsAfter(Regex::create()->parse('/(?i)/')->pattern, 'm'));
        $this->assertSame('m', FlagScope::flagsAfter(Regex::create()->parse('/(?i:a)/')->pattern, 'm'));
        $this->assertSame('m', FlagScope::flagsAfter(Regex::create()->parse('/(?:(?i))/')->pattern, 'm'));
        $this->assertSame('mis', FlagScope::flagsAfter(Regex::create()->parse('/a(?i)b(?s)/')->pattern, 'm'));
        $this->assertSame('m', FlagScope::flagsAfter(new LiteralNode('a', 0, 1), 'm'));
    }

    #[Test]
    public function test_an_inline_flag_group_is_found_at_any_depth(): void
    {
        $this->assertTrue(FlagScope::containsInlineFlags(Regex::create()->parse('/(?i)/')->pattern));
        $this->assertTrue(FlagScope::containsInlineFlags(Regex::create()->parse('/a(?:b|c(?:(?s:d)))/')->pattern));
        $this->assertFalse(FlagScope::containsInlineFlags(Regex::create()->parse('/a(?:b|c(d))/')->pattern));
    }

    /**
     * @return list<NodeInterface>
     */
    private static function dotsAndAnchors(NodeInterface $node): array
    {
        $found = $node instanceof DotNode || $node instanceof AnchorNode ? [$node] : [];
        foreach ($node->getChildren() as $child) {
            $found = [...$found, ...self::dotsAndAnchors($child)];
        }

        return $found;
    }
}

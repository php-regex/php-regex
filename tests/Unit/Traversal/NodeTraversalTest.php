<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Traversal;

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\NodeFinder;
use PhpRegex\Parser\NodeWalker;
use PhpRegex\Parser\RegexParser;
use PhpRegex\Parser\TraversalAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A tree is walked without a visitor for each node type: every node gives
 * its children in the order they stand in the pattern, and the walker and
 * the finder reach each node once.
 */
final class NodeTraversalTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_walk_reaches_every_node_once(string $pattern): void
    {
        $tree = RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse($pattern);
        $reached = 0;

        NodeWalker::walk($tree, static function () use (&$reached): null {
            $reached++;

            return null;
        });

        $this->assertSame(self::nodesHeld($tree), $reached, $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'alternation and quantifier' => ['/(a|bc)+d/'];
        yield 'class with range and type' => ['/[a-z\\d_]/'];
        yield 'condition' => ['/(a)?(?(1)b|c)/'];
        yield 'define and call' => ['/(?(DEFINE)(?<n>x))(?&n)/'];
        yield 'extended class' => ['/(?[ \\d - [3] ])/'];
        yield 'extended class complement' => ['/(?[ ![a] & \\w ])/'];
        yield 'script run' => ['/(*sr:\\d+)/'];
        yield 'lookarounds and references' => ['/(?<=a)(b)(?!c)\\1/'];
        yield 'nested deep' => ['/'.str_repeat('(?:', 200).'a'.str_repeat(')', 200).'/'];
    }

    #[Test]
    public function test_children_stand_in_pattern_order(): void
    {
        $tree = RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse('/ab(c)d/');
        $literals = array_map(static fn (LiteralNode $node): string => $node->value, NodeFinder::findInstanceOf($tree, LiteralNode::class));

        $this->assertSame(['a', 'b', 'c', 'd'], $literals);
    }

    /**
     * A node built by hand with keys still gives a list.
     */
    #[Test]
    public function test_children_are_a_list_whatever_the_keys(): void
    {
        $a = new LiteralNode('a', 0, 1);
        $b = new LiteralNode('b', 1, 2);

        $this->assertSame([$a, $b], (new SequenceNode(['x' => $a, 'y' => $b], 0, 2))->getChildren());
        $this->assertSame([$a, $b], (new AlternationNode([3 => $a, 7 => $b], 0, 2))->getChildren());
    }

    #[Test]
    public function test_the_walk_stops_on_the_way_out(): void
    {
        $tree = RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse('/(a)(b)/');
        $left = [];

        NodeWalker::walk(
            $tree,
            static fn (): null => null,
            static function (NodeInterface $node) use (&$left): ?TraversalAction {
                $left[] = $node instanceof LiteralNode ? $node->value : null;

                return $node instanceof LiteralNode ? TraversalAction::Stop : null;
            },
        );

        $this->assertSame(['a'], $left);
    }

    #[Test]
    public function test_a_leaf_has_no_children(): void
    {
        $this->assertSame([], (new LiteralNode('a', 0, 1))->getChildren());
    }

    #[Test]
    public function test_the_walk_can_skip_a_subtree_and_stop(): void
    {
        $tree = RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse('/x(ab)y(c)z/');
        $seen = [];

        NodeWalker::walk($tree, static function (NodeInterface $node) use (&$seen): ?TraversalAction {
            if ($node instanceof LiteralNode) {
                $seen[] = $node->value;
            }
            if ($node instanceof GroupNode) {
                return TraversalAction::SkipChildren;
            }

            return $node instanceof LiteralNode && 'y' === $node->value ? TraversalAction::Stop : null;
        });

        $this->assertSame(['x', 'y'], $seen);
    }

    #[Test]
    public function test_the_walk_gives_the_ancestors_and_leaves_in_order(): void
    {
        $tree = RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse('/(a)+/');
        $depths = [];
        $left = [];

        NodeWalker::walk(
            $tree,
            static function (NodeInterface $node, array $ancestors) use (&$depths): null {
                if ($node instanceof LiteralNode) {
                    $depths[] = array_map(static fn (NodeInterface $ancestor): string => (new \ReflectionClass($ancestor))->getShortName(), $ancestors);
                }

                return null;
            },
            static function (NodeInterface $node) use (&$left): null {
                $left[] = (new \ReflectionClass($node))->getShortName();

                return null;
            },
        );

        $this->assertSame([['RegexNode', 'QuantifierNode', 'GroupNode']], $depths);
        $this->assertSame(['LiteralNode', 'GroupNode', 'QuantifierNode', 'RegexNode'], $left);
    }

    #[Test]
    public function test_the_finder_finds_by_class_and_by_test(): void
    {
        $tree = RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse('/(a)(b)\\2\\1+/');

        $this->assertSame(['\\2', '\\1'], array_map(static fn (BackrefNode $node): string => $node->ref, NodeFinder::findInstanceOf($tree, BackrefNode::class)));
        $this->assertInstanceOf(QuantifierNode::class, NodeFinder::findFirst($tree, static fn (NodeInterface $node): bool => $node instanceof QuantifierNode));
        $this->assertNull(NodeFinder::findFirst($tree, static fn (NodeInterface $node): bool => false));
        $this->assertCount(2, NodeFinder::find($tree, static fn (NodeInterface $node): bool => $node instanceof GroupNode));
    }

    /**
     * Every node an object holds in its public properties, itself included:
     * counted without getChildren(), so a child it forgets shows.
     */
    private static function nodesHeld(NodeInterface $node): int
    {
        $count = 1;
        foreach ((new \ReflectionObject($node))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($node);
            foreach (\is_array($value) ? $value : [$value] as $item) {
                if ($item instanceof NodeInterface) {
                    $count += self::nodesHeld($item);
                }
            }
        }

        return $count;
    }
}

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

namespace PhpRegex\Tests\Unit\Node;

use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefineNodeTest extends TestCase
{
    public static function data_provider_define_patterns(): \Iterator
    {
        // Simple DEFINE with a single named group
        yield 'simple_define_with_named_group' => [
            '/(?(DEFINE)(?<digit>[0-9]))/',
            'digit',
        ];

        // DEFINE with multiple named groups
        yield 'define_with_multiple_groups' => [
            '/(?(DEFINE)(?<letter>[a-z])(?<number>[0-9]))/',
            'letter',
        ];

        // DEFINE followed by subroutine call
        yield 'define_with_subroutine' => [
            '/(?(DEFINE)(?<word>\w+))(?&word)/',
            'word',
        ];

        // Complex DEFINE pattern
        yield 'complex_define' => [
            '/(?(DEFINE)(?<A>a)(?<B>b)(?<AB>(?&A)(?&B)))(?&AB)+/',
            'A',
        ];
    }

    #[DataProvider('data_provider_define_patterns')]
    public function test_parse_define_creates_define_node(string $pattern, string $expectedFirstGroupName): void
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);

        // The pattern should contain a DefineNode somewhere in the tree
        $defineNode = $this->findDefineNode($ast->pattern);
        $this->assertInstanceOf(DefineNode::class, $defineNode, "Pattern '{$pattern}' should contain a DefineNode");

        // The content of the DefineNode should contain the named group
        $firstNamedGroup = $this->findFirstNamedGroup($defineNode->content);
        $this->assertInstanceOf(GroupNode::class, $firstNamedGroup);
        $this->assertSame(GroupType::T_GROUP_NAMED, $firstNamedGroup->type);
        $this->assertSame($expectedFirstGroupName, $firstNamedGroup->name);
    }

    public function test_constructor_and_getters(): void
    {
        $content = new LiteralNode('test', 10, 14);
        $node = new DefineNode($content, 0, 20);

        $this->assertSame($content, $node->content);
        $this->assertSame(0, $node->getStartPosition());
        $this->assertSame(20, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_define(): void
    {
        $content = new LiteralNode('x', 10, 11);
        $node = new DefineNode($content, 0, 15);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitDefine')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }

    public function test_define_node_positions(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?(DEFINE)(?<A>a))/');

        $defineNode = $this->findDefineNode($ast->pattern);
        $this->assertInstanceOf(DefineNode::class, $defineNode);

        // The DefineNode should have valid start and end positions
        $this->assertGreaterThanOrEqual(0, $defineNode->getStartPosition());
        $this->assertGreaterThan($defineNode->getStartPosition(), $defineNode->getEndPosition());
    }

    public function test_define_content_is_accessible(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?(DEFINE)(?<test>abc))/');

        $defineNode = $this->findDefineNode($ast->pattern);
        $this->assertInstanceOf(DefineNode::class, $defineNode);
    }

    /**
     * Recursively find a DefineNode in the AST.
     */
    private function findDefineNode(mixed $node): ?DefineNode
    {
        if ($node instanceof DefineNode) {
            return $node;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $result = $this->findDefineNode($child);
                if (null !== $result) {
                    return $result;
                }
            }
        }

        if ($node instanceof GroupNode) {
            return $this->findDefineNode($node->child);
        }

        return null;
    }

    /**
     * Recursively find the first named group in the AST.
     */
    private function findFirstNamedGroup(mixed $node): ?GroupNode
    {
        if ($node instanceof GroupNode && GroupType::T_GROUP_NAMED === $node->type) {
            return $node;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $result = $this->findFirstNamedGroup($child);
                if (null !== $result) {
                    return $result;
                }
            }
        }

        if ($node instanceof GroupNode) {
            return $this->findFirstNamedGroup($node->child);
        }

        return null;
    }
}

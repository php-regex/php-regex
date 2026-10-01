<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Node;

use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class ConditionalNodeTest extends TestCase
{
    public function test_constructor_and_getters(): void
    {
        $condition = new BackrefNode('1', 2, 3);
        $yes = new LiteralNode('a', 4, 5);
        $no = new LiteralNode('b', 6, 7);

        $node = new ConditionalNode($condition, $yes, $no, 0, 10);

        $this->assertSame($condition, $node->condition);
        $this->assertSame($yes, $node->yes);
        $this->assertSame($no, $node->no);
        $this->assertSame(0, $node->getStartPosition());
        $this->assertSame(10, $node->getEndPosition());
    }

    public function test_constructor_with_empty_no_branch(): void
    {
        $condition = new BackrefNode('1', 2, 3);
        $yes = new LiteralNode('a', 4, 5);
        $no = new LiteralNode('', 5, 5); // Empty node for no branch

        $node = new ConditionalNode($condition, $yes, $no, 0, 6);
        $this->assertInstanceOf(LiteralNode::class, $node->no);
        $this->assertSame('', $node->no->value);
    }

    public function test_accept_visitor_calls_visit_conditional(): void
    {
        $condition = $this->createStub(NodeInterface::class);
        $yes = $this->createStub(NodeInterface::class);
        $no = $this->createStub(NodeInterface::class);

        $node = new ConditionalNode($condition, $yes, $no, 0, 10);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitConditional')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

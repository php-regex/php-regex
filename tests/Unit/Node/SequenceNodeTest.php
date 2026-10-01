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

use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class SequenceNodeTest extends TestCase
{
    public function test_constructor_and_getters(): void
    {
        $child1 = new LiteralNode('a', 1, 2);
        $child2 = new LiteralNode('b', 2, 3);

        $node = new SequenceNode([$child1, $child2], 1, 3);

        $this->assertCount(2, $node->children);
        $this->assertSame($child1, $node->children[0]);
        $this->assertSame(1, $node->getStartPosition());
        $this->assertSame(3, $node->getEndPosition());
    }

    public function test_empty_sequence(): void
    {
        $node = new SequenceNode([], 5, 5);

        $this->assertEmpty($node->children);
        $this->assertSame(5, $node->getStartPosition());
        $this->assertSame(5, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_sequence(): void
    {
        $child = $this->createStub(NodeInterface::class);
        $node = new SequenceNode([$child], 0, 1);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitSequence')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

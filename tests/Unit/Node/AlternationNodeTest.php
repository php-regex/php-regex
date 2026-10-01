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

namespace PHPRegex\Tests\Unit\Node;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class AlternationNodeTest extends TestCase
{
    public function test_constructor_and_getters(): void
    {
        $alt1 = new LiteralNode('a', 1, 2);
        $alt2 = new LiteralNode('b', 3, 4);

        $node = new AlternationNode([$alt1, $alt2], 1, 4);

        $this->assertCount(2, $node->alternatives);
        $this->assertSame($alt1, $node->alternatives[0]);
        $this->assertSame(1, $node->getStartPosition());
        $this->assertSame(4, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_alternation(): void
    {
        $alt1 = $this->createStub(NodeInterface::class);
        $alt2 = $this->createStub(NodeInterface::class);

        $node = new AlternationNode([$alt1, $alt2], 0, 10);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitAlternation')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

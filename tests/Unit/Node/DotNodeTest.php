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

use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class DotNodeTest extends TestCase
{
    public function test_constructor_and_getters(): void
    {
        $node = new DotNode(5, 6);

        $this->assertSame(5, $node->getStartPosition());
        $this->assertSame(6, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_dot(): void
    {
        $node = new DotNode(0, 1);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitDot')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

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

use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class KeepNodeTest extends TestCase
{
    public function test_constructor_and_getters(): void
    {
        $node = new KeepNode(10, 12); // \K est de longueur 2

        $this->assertSame(10, $node->getStartPosition());
        $this->assertSame(12, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_keep(): void
    {
        $node = new KeepNode(0, 2);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitKeep')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

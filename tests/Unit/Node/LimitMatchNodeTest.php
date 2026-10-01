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

use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class LimitMatchNodeTest extends TestCase
{
    public function test_constructor_and_getters(): void
    {
        $node = new LimitMatchNode(1000, 5, 20);

        $this->assertSame(1000, $node->limit);
        $this->assertSame(5, $node->getStartPosition());
        $this->assertSame(20, $node->getEndPosition());
    }

    public function test_limit_match_with_different_values(): void
    {
        $node = new LimitMatchNode(100, 10, 30);

        $this->assertSame(100, $node->limit);
        $this->assertSame(10, $node->getStartPosition());
        $this->assertSame(30, $node->getEndPosition());
    }

    public function test_limit_match_with_zero(): void
    {
        $node = new LimitMatchNode(0, 0, 15);

        $this->assertSame(0, $node->limit);
        $this->assertSame(0, $node->getStartPosition());
        $this->assertSame(15, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_limit_match(): void
    {
        $node = new LimitMatchNode(500, 0, 10);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitLimitMatch')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

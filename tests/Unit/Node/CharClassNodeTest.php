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

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class CharClassNodeTest extends TestCase
{
    public function test_constructor_and_getters_positive(): void
    {
        $part1 = new LiteralNode('a', 2, 3);
        $part2 = new CharTypeNode('d', 4, 6);
        $expression = new AlternationNode([$part1, $part2], 1, 7);

        $node = new CharClassNode($expression, false, 1, 7);

        $this->assertFalse($node->isNegated);
        $this->assertInstanceOf(AlternationNode::class, $node->expression);
        $this->assertCount(2, $node->expression->alternatives);
        $this->assertSame($part1, $node->expression->alternatives[0]);
        $this->assertSame(1, $node->getStartPosition());
        $this->assertSame(7, $node->getEndPosition());
    }

    public function test_constructor_and_getters_negated(): void
    {
        $part1 = new LiteralNode('a', 3, 4);
        $expression = new AlternationNode([$part1], 1, 5);
        $node = new CharClassNode($expression, true, 1, 5);

        $this->assertTrue($node->isNegated);
        $this->assertInstanceOf(AlternationNode::class, $node->expression);
        $this->assertCount(1, $node->expression->alternatives);
    }

    public function test_accept_visitor_calls_visit_char_class(): void
    {
        $part1 = $this->createStub(NodeInterface::class);
        $node = new CharClassNode($part1, false, 0, 5);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitCharClass')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

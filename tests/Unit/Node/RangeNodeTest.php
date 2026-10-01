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

use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class RangeNodeTest extends TestCase
{
    public function test_constructor_with_literals(): void
    {
        $start = new LiteralNode('a', 1, 2);
        $end = new LiteralNode('z', 4, 5);

        $node = new RangeNode($start, $end, 1, 5);

        $this->assertSame($start, $node->start);
        $this->assertSame($end, $node->end);
        $this->assertSame(1, $node->getStartPosition());
        $this->assertSame(5, $node->getEndPosition());
    }

    public function test_constructor_with_char_types(): void
    {
        // A range between CharTypeNode is semantically invalid but syntactically possible in the AST before validation.
        $start = new CharTypeNode('d', 1, 3);
        $end = new CharTypeNode('w', 5, 7);

        $node = new RangeNode($start, $end, 1, 7);
        $this->assertSame($start, $node->start);
        $this->assertSame($end, $node->end);
    }

    public function test_accept_visitor_calls_visit_range(): void
    {
        $start = new LiteralNode('a', 0, 1);
        $end = new LiteralNode('z', 2, 3);
        $node = new RangeNode($start, $end, 0, 3);

        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitRange')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

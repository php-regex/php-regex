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

use PhpRegex\Parser\Node\VersionConditionNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class VersionConditionNodeTest extends TestCase
{
    public function test_constructor_and_getters(): void
    {
        $node = new VersionConditionNode('>=', '10.0', 5, 20);

        $this->assertSame('>=', $node->operator);
        $this->assertSame('10.0', $node->version);
        $this->assertSame(5, $node->getStartPosition());
        $this->assertSame(20, $node->getEndPosition());
    }

    public function test_version_condition_with_equals(): void
    {
        $node = new VersionConditionNode('==', '10.33', 10, 25);

        $this->assertSame('==', $node->operator);
        $this->assertSame('10.33', $node->version);
        $this->assertSame(10, $node->getStartPosition());
        $this->assertSame(25, $node->getEndPosition());
    }

    public function test_version_condition_with_less_than(): void
    {
        $node = new VersionConditionNode('<', '8.0', 15, 30);

        $this->assertSame('<', $node->operator);
        $this->assertSame('8.0', $node->version);
        $this->assertSame(15, $node->getStartPosition());
        $this->assertSame(30, $node->getEndPosition());
    }

    public function test_version_condition_with_not_equal(): void
    {
        $node = new VersionConditionNode('!=', '10.42', 20, 35);

        $this->assertSame('!=', $node->operator);
        $this->assertSame('10.42', $node->version);
        $this->assertSame(20, $node->getStartPosition());
        $this->assertSame(35, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_version_condition(): void
    {
        $node = new VersionConditionNode('>=', '10.30', 0, 15);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitVersionCondition')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

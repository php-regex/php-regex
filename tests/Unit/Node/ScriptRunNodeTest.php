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

use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\TestCase;

final class ScriptRunNodeTest extends TestCase
{
    public function test_constructor_and_getters(): void
    {
        $node = new ScriptRunNode('Latin', 10, 30);

        $this->assertSame('Latin', $node->script);
        $this->assertSame(10, $node->getStartPosition());
        $this->assertSame(30, $node->getEndPosition());
    }

    public function test_script_run_with_arabic(): void
    {
        $node = new ScriptRunNode('Arabic', 5, 25);

        $this->assertSame('Arabic', $node->script);
        $this->assertSame(5, $node->getStartPosition());
        $this->assertSame(25, $node->getEndPosition());
    }

    public function test_script_run_with_devanagari(): void
    {
        $node = new ScriptRunNode('Devanagari', 15, 40);

        $this->assertSame('Devanagari', $node->script);
        $this->assertSame(15, $node->getStartPosition());
        $this->assertSame(40, $node->getEndPosition());
    }

    public function test_accept_visitor_calls_visit_script_run(): void
    {
        $node = new ScriptRunNode('Cyrillic', 0, 15);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitScriptRun')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

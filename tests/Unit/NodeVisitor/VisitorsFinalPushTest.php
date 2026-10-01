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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPUnit\Framework\TestCase;

final class VisitorsFinalPushTest extends TestCase
{
    /**
     * Tests the branch "if ($optimizedPart !== $part)" in Rewriter::visitCharClass.
     * Normally, the children of a character class don't change.
     * We use a Mock to force a change.
     */
    public function test_optimizer_detects_change_in_char_class_parts(): void
    {
        $optimizer = new Rewriter();

        // We create a Mock of a node that returns a NEW instance when visited
        $mockPart = $this->createMock(NodeInterface::class);
        $mockPart->expects($this->once())
            ->method('accept')
            ->willReturn(new LiteralNode('changed', 0, 0));

        // On l'injecte dans une CharClassNode
        $node = new CharClassNode($mockPart, false, 0, 0);

        // On visite
        $result = $node->accept($optimizer);

        // The result must be a new CharClassNode (not the same instance)
        $this->assertNotSame($node, $result);
        $this->assertInstanceOf(CharClassNode::class, $result);
        $this->assertInstanceOf(LiteralNode::class, $result->expression);
        $this->assertSame('changed', $result->expression->value);
    }

    /**
     * Teste le fallback "default" du SampleGenerator pour un CharType inconnu.
     * (Impossible via le parser car il valide les types, donc on injecte manuellement).
     */
    public function test_sample_generator_unknown_char_type(): void
    {
        $generator = new SampleGenerator();
        // 'Z' is not a standard type known to the generator
        $node = new CharTypeNode('Z', 0, 0);

        $result = $node->accept($generator);

        // Must return '?' (the default)
        $this->assertSame('?', $result);
    }
}

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

namespace PhpRegex\Tests\Integration;

use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\LiteralNode;
use PHPUnit\Framework\TestCase;

final class ManualNodeInjectionTest extends TestCase
{
    public function test_sample_generator_fallbacks(): void
    {
        $generator = new SampleGenerator();

        // 1. CharTypeNode with unknown type
        // Parser only allows d, D, s, S, etc. We force '?' to hit the default match arm.
        $node = new CharTypeNode('?', 0, 0);
        $this->assertSame('?', $node->accept($generator));

        // 2. CharLiteralNode with invalid format
        // Parser ensures format. We force garbage to hit the fallback.
        $node = new CharLiteralNode('invalid', -1, CharLiteralType::Unicode, 0, 0);
        $this->assertSame('?', $node->accept($generator));

        // 3. CharLiteralNode with invalid format
        $node = new CharLiteralNode('invalid', -1, CharLiteralType::Octal, 0, 0);
        $this->assertSame('?', $node->accept($generator));

        // 4. Alternation with no alternatives (Parser prevents this usually)
        $node = new AlternationNode([], 0, 0);
        $this->assertSame('', $node->accept($generator));
    }

    public function test_optimizer_alternation_logic(): void
    {
        $optimizer = new Rewriter();

        // 1. Alternation containing non-literals (should NOT optimize to CharClass)
        // Case: (a|\d) -> Non-literal child
        $node = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new CharTypeNode('d', 0, 0)
        ], 0, 0);

        $result = $node->accept($optimizer);
        $this->assertInstanceOf(AlternationNode::class, $result);

        // 2. Alternation containing multi-char literals (should NOT optimize)
        // Case: (a|abc) -> Literal length > 1
        $node = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new LiteralNode('abc', 0, 0)
        ], 0, 0);
        $result = $node->accept($optimizer);
        $this->assertInstanceOf(AlternationNode::class, $result);

        // 3. Alternation containing meta-characters inside CharClass (should NOT optimize)
        // Case: (a|-) -> Hyphen is a meta-char in CharClass, unsafe to convert to [a-] without escaping logic
        $node = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new LiteralNode('-', 0, 0)
        ], 0, 0);
        $result = $node->accept($optimizer);
        $this->assertInstanceOf(AlternationNode::class, $result);
    }
}

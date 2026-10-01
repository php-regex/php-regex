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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Optimizer\Modernizer;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\RegexNode;
use PHPUnit\Framework\TestCase;

final class ModernizerNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_char_class_multiple_literals_builds_alternation(): void
    {
        $visitor = new Modernizer();
        $expression = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0),
        ], 0, 0);
        $charClass = new CharClassNode($expression, false, 0, 0);

        $modernized = $charClass->accept($visitor);

        $this->assertInstanceOf(CharClassNode::class, $modernized);
        $this->assertInstanceOf(AlternationNode::class, $modernized->expression);
    }

    public function test_literal_unescape_respects_custom_delimiter(): void
    {
        $visitor = new Modernizer();
        $regex = new RegexNode(new LiteralNode('\\a', 0, 0), '', '#', 0, 0);

        $modernized = $regex->accept($visitor);

        $this->assertInstanceOf(RegexNode::class, $modernized);
        $this->assertInstanceOf(LiteralNode::class, $modernized->pattern);
        $this->assertSame('a', $modernized->pattern->value);
    }
}

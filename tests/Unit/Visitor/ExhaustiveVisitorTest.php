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

namespace PhpRegex\Tests\Unit\Visitor;

use PhpRegex\Explain\HtmlExplainer;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PHPUnit\Framework\TestCase;

final class ExhaustiveVisitorTest extends TestCase
{
    public function test_compiler_special_literals(): void
    {
        $compiler = new PatternPrinter();

        // Special case: ']' is not escaped outside a char class
        $node = new LiteralNode(']', 0, 0);
        $this->assertSame(']', $node->accept($compiler));

        // Escaped characters outside char class
        $node = new LiteralNode('.', 0, 0);
        $this->assertSame('\.', $node->accept($compiler));
    }

    public function test_compiler_unicode_properties(): void
    {
        $compiler = new PatternPrinter();

        // \p{L} (short)
        $node = new UnicodePropNode('L', 0, 0);
        $this->assertSame('\pL', $node->accept($compiler));

        // \p{Lu} (long)
        $node = new UnicodePropNode('Lu', 0, 0);
        $this->assertSame('\p{Lu}', $node->accept($compiler));

        // \P{L} (negated)
        $node = new UnicodePropNode('^L', 0, 0);
        $this->assertSame('\p{^L}', $node->accept($compiler));
    }

    public function test_compiler_subroutines_syntax(): void
    {
        $compiler = new PatternPrinter();

        // (?&name)
        $node = new SubroutineNode('name', '&', 0, 0);
        $this->assertSame('(?&name)', $node->accept($compiler));

        // (?P>name)
        $node = new SubroutineNode('name', 'P>', 0, 0);
        $this->assertSame('(?P>name)', $node->accept($compiler));

        // \g<name>
        $node = new SubroutineNode('name', 'g', 0, 0);
        $this->assertSame('\g<name>', $node->accept($compiler));

        // (?1) default
        $node = new SubroutineNode('1', '', 0, 0);
        $this->assertSame('(?1)', $node->accept($compiler));
    }

    public function test_compiler_conditionals(): void
    {
        $compiler = new PatternPrinter();

        $condition = new LiteralNode('cond', 0, 0);
        $yes = new LiteralNode('yes', 0, 0);
        $no = new LiteralNode('no', 0, 0);

        // With Else
        $node = new ConditionalNode($condition, $yes, $no, 0, 0);
        $this->assertSame('(?(cond)yes|no)', $node->accept($compiler));

        // Without Else (empty string)
        $emptyNo = new LiteralNode('', 0, 0);
        $node = new ConditionalNode($condition, $yes, $emptyNo, 0, 0);
        $this->assertSame('(?(cond)yes)', $node->accept($compiler));
    }

    public function test_explain_all_char_types_and_assertions(): void
    {
        $explainer = new TextExplainer();
        $htmlExplainer = new HtmlExplainer();

        // Char Types: d, D, s, S, w, W, h, H, v, V, R
        $types = ['d', 'D', 's', 'S', 'w', 'W', 'h', 'H', 'v', 'V', 'R'];
        foreach ($types as $type) {
            $node = new CharTypeNode($type, 0, 0);
            $this->assertNotEmpty($node->accept($explainer));
            $this->assertNotEmpty($node->accept($htmlExplainer));
        }

        // Unknown Char Type
        $node = new CharTypeNode('?', 0, 0);
        $this->assertStringContainsString('unknown', $node->accept($explainer));

        // Assertions: A, z, Z, G, b, B
        $assertions = ['A', 'z', 'Z', 'G', 'b', 'B'];
        foreach ($assertions as $val) {
            $node = new AssertionNode($val, 0, 0);
            $this->assertNotEmpty($node->accept($explainer));
            $this->assertNotEmpty($node->accept($htmlExplainer));
        }

        // Unknown Assertion
        $node = new AssertionNode('?', 0, 0);
        $this->assertStringContainsString('\?', $node->accept($explainer));
    }

    public function test_explain_group_types(): void
    {
        $explainer = new TextExplainer();

        // Lookbehind Positive
        $node = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_LOOKBEHIND_POSITIVE);
        $this->assertStringContainsString('Positive lookbehind', $node->accept($explainer));

        // Lookbehind Negative
        $node = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_LOOKBEHIND_NEGATIVE);
        $this->assertStringContainsString('Negative lookbehind', $node->accept($explainer));

        // Atomic
        $node = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_ATOMIC);
        $this->assertStringContainsString('Atomic', $node->accept($explainer));
    }

    public function test_explain_quantifiers(): void
    {
        $explainer = new TextExplainer();
        $node = new LiteralNode('a', 0, 0);

        // Range {1,3}
        $q = new QuantifierNode($node, '{1,3}', QuantifierType::T_GREEDY, 0, 0);
        $this->assertStringContainsString('at least 1 but not more than 3', $q->accept($explainer));

        // At least {1,}
        $q = new QuantifierNode($node, '{1,}', QuantifierType::T_GREEDY, 0, 0);
        $this->assertStringContainsString('at least 1', $q->accept($explainer));

        // Exact {5}
        $q = new QuantifierNode($node, '{5}', QuantifierType::T_GREEDY, 0, 0);
        $this->assertStringContainsString('exactly 5', $q->accept($explainer));
    }
}

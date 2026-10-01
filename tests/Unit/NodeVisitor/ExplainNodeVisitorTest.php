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

use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPUnit\Framework\TestCase;

final class ExplainNodeVisitorTest extends TestCase
{
    public function test_visit_octal_legacy_node(): void
    {
        $node = new CharLiteralNode('077', 0o77, CharLiteralType::OctalLegacy, 0, 3);
        $visitor = new TextExplainer();

        $this->assertSame('Character with octal value 077', $node->accept($visitor));
    }

    public function test_visit_alternation_node(): void
    {
        $node = new AlternationNode([
            new LiteralNode('a', 0, 1),
            new LiteralNode('b', 2, 3),
        ], 0, 3);
        $visitor = new TextExplainer();

        $this->assertSame("  EITHER\n    'a'\n  OR\n    'b'", $node->accept($visitor));
    }

    public function test_visit_sequence_node(): void
    {
        $node = new SequenceNode([
            new LiteralNode('a', 0, 1),
            new LiteralNode('b', 1, 2),
        ], 0, 2);
        $visitor = new TextExplainer();

        $this->assertSame("'a'\n'b'", $node->accept($visitor));
    }

    public function test_visit_group_node(): void
    {
        $node = new GroupNode(
            new LiteralNode('a', 1, 2),
            GroupType::Capturing,
            null,
            null,
            0,
            3,
        );
        $visitor = new TextExplainer();

        $this->assertSame("Capturing group\n  'a'\nEnd group", $node->accept($visitor));
    }

    public function test_visit_quantifier_node(): void
    {
        $node = new QuantifierNode(
            new LiteralNode('a', 0, 1),
            '*',
            QuantifierType::Greedy,
            0,
            2,
        );
        $visitor = new TextExplainer();

        $this->assertSame("'a' (zero or more times)", $node->accept($visitor));
    }

    public function test_visit_char_class_node(): void
    {
        $node = new CharClassNode(
            new AlternationNode([new LiteralNode('a', 1, 2)], 0, 3),
            false,
            0,
            3,
        );
        $visitor = new TextExplainer();

        $this->assertSame("Character Class: any character in [ 'a' ]", $node->accept($visitor));
    }

    public function test_visit_range_node(): void
    {
        $node = new RangeNode(
            new LiteralNode('a', 1, 2),
            new LiteralNode('z', 3, 4),
            0,
            5,
        );
        $visitor = new TextExplainer();

        $this->assertSame("Range: from 'a' to 'z'", $node->accept($visitor));
    }

    public function test_visit_backref_node(): void
    {
        $node = new BackrefNode('1', 0, 2);
        $visitor = new TextExplainer();

        $this->assertSame('Backreference: whatever the capturing group "1" matched', $node->accept($visitor));
    }

    public function test_visit_unicode_node(): void
    {
        $node = new CharLiteralNode('\x{2603}', 0x2603, CharLiteralType::Unicode, 0, 7);
        $visitor = new TextExplainer();

        $this->assertSame('Character with hexadecimal value 0x2603', $node->accept($visitor));
    }

    public function test_visit_unicode_prop_node(): void
    {
        $node = new UnicodePropNode('L', 0, 2);
        $visitor = new TextExplainer();

        $this->assertSame('Unicode Property: any character matching "L"', $node->accept($visitor));
    }

    public function test_visit_posix_class_node(): void
    {
        $node = new PosixClassNode('alpha', 0, 8);
        $visitor = new TextExplainer();

        $this->assertSame('POSIX Class: alpha', $node->accept($visitor));
    }

    public function test_visit_comment_node(): void
    {
        $node = new CommentNode('test', 0, 7);
        $visitor = new TextExplainer();

        $this->assertSame("Comment: 'test'", $node->accept($visitor));
    }

    public function test_visit_conditional_node(): void
    {
        $node = new ConditionalNode(
            new BackrefNode('1', 2, 3),
            new LiteralNode('a', 4, 5),
            new LiteralNode('b', 6, 7),
            0,
            8,
        );
        $visitor = new TextExplainer();

        $this->assertSame("IF (  Backreference: whatever the capturing group \"1\" matched) THEN\n  'a'\nELSE\n  'b'", $node->accept($visitor));
    }

    public function test_visit_subroutine_node(): void
    {
        $node = new SubroutineNode('R', '', 0, 3);
        $visitor = new TextExplainer();

        $this->assertSame('Subroutine Call: recurses to the entire pattern', $node->accept($visitor));
    }

    public function test_visit_pcre_verb_node(): void
    {
        $node = new PcreVerbNode('FAIL', 0, 7);
        $visitor = new TextExplainer();

        $this->assertSame('PCRE Verb: (*FAIL)', $node->accept($visitor));
    }

    public function test_visit_define_node(): void
    {
        $node = new DefineNode(
            new LiteralNode('a', 9, 10),
            0,
            11,
        );
        $visitor = new TextExplainer();

        $this->assertSame("DEFINE block (defines subpatterns without matching)\n  'a'\nEnd DEFINE Block", $node->accept($visitor));
    }
}

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

use PHPRegex\Optimizer\Modernizer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPUnit\Framework\TestCase;

final class ModernizerNodeVisitorTest extends TestCase
{
    private Modernizer $visitor;

    protected function setUp(): void
    {
        $this->visitor = new Modernizer();
    }

    public function test_modernizes_digit_range_to_char_type(): void
    {
        $range = new RangeNode(new LiteralNode('0', 0, 1), new LiteralNode('9', 2, 3), 0, 3);
        $charClass = new CharClassNode($range, false, 0, 4);

        $result = $charClass->accept($this->visitor);

        $this->assertInstanceOf(CharTypeNode::class, $result);
        $this->assertSame('d', $result->value);
    }

    public function test_removes_unnecessary_escaping(): void
    {
        $literal = new LiteralNode('\\@', 0, 2);

        $result = $literal->accept($this->visitor);

        $this->assertInstanceOf(LiteralNode::class, $result);
        /* @var LiteralNode $result */
        $this->assertSame('@', $result->value);
    }

    public function test_modernizes_numeric_backref(): void
    {
        $backref = new BackrefNode('1', 0, 2);

        $result = $backref->accept($this->visitor);

        $this->assertInstanceOf(BackrefNode::class, $result);
        /* @var BackrefNode $result */
        $this->assertSame('\g{1}', $result->ref);
    }

    public function test_preserves_named_backref(): void
    {
        $backref = new BackrefNode('name', 0, 6);

        $result = $backref->accept($this->visitor);

        $this->assertSame($backref, $result);
    }

    public function test_preserves_alternation(): void
    {
        $alternation = new AlternationNode(
            [new LiteralNode('a', 0, 1), new LiteralNode('b', 3, 4)],
            0, 5,
        );

        $result = $alternation->accept($this->visitor);

        $this->assertInstanceOf(AlternationNode::class, $result);
    }

    public function test_preserves_sequence(): void
    {
        $sequence = new SequenceNode(
            [new LiteralNode('a', 0, 1), new LiteralNode('b', 2, 3)],
            0, 4,
        );

        $result = $sequence->accept($this->visitor);

        $this->assertInstanceOf(SequenceNode::class, $result);
    }

    public function test_preserves_quantifier(): void
    {
        $quantifier = new QuantifierNode(
            new LiteralNode('a', 0, 1),
            '*',
            QuantifierType::Greedy,
            1,
            2,
        );

        $result = $quantifier->accept($this->visitor);

        $this->assertInstanceOf(QuantifierNode::class, $result);
    }

    public function test_preserves_anchor(): void
    {
        $anchor = new AnchorNode('^', 0, 1);
        $result = $anchor->accept($this->visitor);

        $this->assertSame($anchor, $result);
    }

    public function test_preserves_assertion(): void
    {
        $assertion = new AssertionNode('b', 0, 2);
        $result = $assertion->accept($this->visitor);

        $this->assertSame($assertion, $result);
    }

    public function test_preserves_dot(): void
    {
        $dot = new DotNode(0, 1);
        $result = $dot->accept($this->visitor);

        $this->assertSame($dot, $result);
    }

    public function test_preserves_char_type(): void
    {
        $charType = new CharTypeNode('w', 0, 2);
        $result = $charType->accept($this->visitor);

        $this->assertSame($charType, $result);
    }

    public function test_preserves_range(): void
    {
        $range = new RangeNode(
            new LiteralNode('a', 0, 1),
            new LiteralNode('z', 3, 4),
            0, 5,
        );
        $result = $range->accept($this->visitor);

        $this->assertSame($range, $result);
    }

    public function test_preserves_unicode_prop(): void
    {
        $unicodeProp = new UnicodePropNode('L', 0, 5);
        $result = $unicodeProp->accept($this->visitor);

        $this->assertSame($unicodeProp, $result);
    }

    public function test_preserves_char_literal(): void
    {
        $charLiteral = new CharLiteralNode('a', 65, CharLiteralType::Unicode, 0, 1);
        $result = $charLiteral->accept($this->visitor);

        $this->assertSame($charLiteral, $result);
    }

    public function test_preserves_posix_class(): void
    {
        $posixClass = new PosixClassNode('alpha', 0, 9);
        $result = $posixClass->accept($this->visitor);

        $this->assertSame($posixClass, $result);
    }

    public function test_preserves_comment(): void
    {
        $comment = new CommentNode('test comment', 0, 13);
        $result = $comment->accept($this->visitor);

        $this->assertSame($comment, $result);
    }

    public function test_preserves_conditional(): void
    {
        $conditional = new ConditionalNode(
            new LiteralNode('a', 1, 2),
            new LiteralNode('b', 4, 5),
            new LiteralNode('c', 7, 8),
            0, 10,
        );
        $result = $conditional->accept($this->visitor);

        $this->assertInstanceOf(ConditionalNode::class, $result);
    }

    public function test_preserves_subroutine(): void
    {
        $subroutine = new SubroutineNode('1', 'R', 0, 4);
        $result = $subroutine->accept($this->visitor);

        $this->assertSame($subroutine, $result);
    }

    public function test_preserves_pcre_verb(): void
    {
        $pcreVerb = new PcreVerbNode('FAIL', 0, 6);
        $result = $pcreVerb->accept($this->visitor);

        $this->assertSame($pcreVerb, $result);
    }

    public function test_preserves_define(): void
    {
        $define = new DefineNode(
            new LiteralNode('test', 1, 5),
            0, 12,
        );
        $result = $define->accept($this->visitor);

        $this->assertSame($define, $result);
    }

    public function test_preserves_limit_match(): void
    {
        $limitMatch = new LimitMatchNode(1000, 0, 17);
        $result = $limitMatch->accept($this->visitor);

        $this->assertSame($limitMatch, $result);
    }

    public function test_preserves_callout(): void
    {
        $callout = new CalloutNode(null, false, 0, 3);
        $result = $callout->accept($this->visitor);

        $this->assertSame($callout, $result);
    }

    public function test_preserves_script_run(): void
    {
        $scriptRun = new ScriptRunNode('Latin', 0, 20);
        $result = $scriptRun->accept($this->visitor);

        $this->assertSame($scriptRun, $result);
    }

    public function test_preserves_version_condition(): void
    {
        $versionCondition = new VersionConditionNode('>=', '10.0', 0, 15);
        $result = $versionCondition->accept($this->visitor);

        $this->assertSame($versionCondition, $result);
    }

    public function test_preserves_keep(): void
    {
        $keep = new KeepNode(0, 2);
        $result = $keep->accept($this->visitor);

        $this->assertSame($keep, $result);
    }

    public function test_preserves_control_char(): void
    {
        $controlChar = new ControlCharNode('n', 10, 0, 3);
        $result = $controlChar->accept($this->visitor);

        $this->assertSame($controlChar, $result);
    }

    public function test_modernizes_whitespace_char_class(): void
    {
        $whitespaceClass = new CharClassNode(
            new AlternationNode(
                [
                    new LiteralNode("\t", 1, 4),
                    new LiteralNode("\n", 5, 8),
                    new LiteralNode("\r", 9, 12),
                    new LiteralNode("\f", 13, 16),
                    new LiteralNode("\v", 17, 20),
                ],
                0, 21,
            ),
            false,
            0, 22,
        );

        $result = $whitespaceClass->accept($this->visitor);

        $this->assertInstanceOf(CharTypeNode::class, $result);
        $this->assertSame('s', $result->value);
    }
}

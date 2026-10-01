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

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PHPUnit\Framework\TestCase;

/**
 * Concrete implementation of AbstractNodeVisitor for testing purposes.
 *
 * @template-extends AbstractNodeVisitor<string>
 */
class TestNodeVisitor extends AbstractNodeVisitor
{
    protected function defaultReturn(): string
    {
        return 'default';
    }
}

final class AbstractNodeVisitorTest extends TestCase
{
    private TestNodeVisitor $visitor;

    protected function setUp(): void
    {
        $this->visitor = new TestNodeVisitor();
    }

    public function test_visit_regex(): void
    {
        $node = new RegexNode(new SequenceNode([], 0, 0), '', '/', 0, 0);
        $result = $this->visitor->visitRegex($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_alternation(): void
    {
        $node = new AlternationNode([], 0, 0);
        $result = $this->visitor->visitAlternation($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_sequence(): void
    {
        $node = new SequenceNode([], 0, 0);
        $result = $this->visitor->visitSequence($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_group(): void
    {
        $node = new GroupNode(new SequenceNode([], 0, 0), GroupType::Capturing, null, null, 0, 0);
        $result = $this->visitor->visitGroup($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_quantifier(): void
    {
        $node = new QuantifierNode(new LiteralNode('a', 0, 0), '*', QuantifierType::Greedy, 0, 0);
        $result = $this->visitor->visitQuantifier($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_literal(): void
    {
        $node = new LiteralNode('a', 0, 0);
        $result = $this->visitor->visitLiteral($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_char_literal(): void
    {
        $node = new CharLiteralNode('a', 97, CharLiteralType::Unicode, 0, 0);
        $result = $this->visitor->visitCharLiteral($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_char_type(): void
    {
        $node = new CharTypeNode('d', 0, 0);
        $result = $this->visitor->visitCharType($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_dot(): void
    {
        $node = new DotNode(0, 0);
        $result = $this->visitor->visitDot($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_anchor(): void
    {
        $node = new AnchorNode('^', 0, 0);
        $result = $this->visitor->visitAnchor($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_assertion(): void
    {
        $node = new AssertionNode('b', 0, 0);
        $result = $this->visitor->visitAssertion($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_keep(): void
    {
        $node = new KeepNode(0, 0);
        $result = $this->visitor->visitKeep($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_char_class(): void
    {
        $node = new CharClassNode(new LiteralNode('a', 0, 0), false, 0, 0);
        $result = $this->visitor->visitCharClass($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_range(): void
    {
        $node = new RangeNode(new LiteralNode('a', 0, 0), new LiteralNode('z', 0, 0), 0, 0);
        $result = $this->visitor->visitRange($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_backref(): void
    {
        $node = new BackrefNode('1', 0, 0);
        $result = $this->visitor->visitBackref($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_control_char(): void
    {
        $node = new ControlCharNode('M', 13, 0, 0);
        $result = $this->visitor->visitControlChar($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_script_run(): void
    {
        $node = new ScriptRunNode('Latin', 0, 0);
        $result = $this->visitor->visitScriptRun($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_version_condition(): void
    {
        $node = new VersionConditionNode('>=', '10.0', 0, 0);
        $result = $this->visitor->visitVersionCondition($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_posix_class(): void
    {
        $node = new PosixClassNode('alnum', 0, 0);
        $result = $this->visitor->visitPosixClass($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_comment(): void
    {
        $node = new CommentNode('test', 0, 0);
        $result = $this->visitor->visitComment($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_conditional(): void
    {
        $node = new ConditionalNode(new LiteralNode('1', 0, 0), new SequenceNode([], 0, 0), new SequenceNode([], 0, 0), 0, 0);
        $result = $this->visitor->visitConditional($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_subroutine(): void
    {
        $node = new SubroutineNode('1', '', 0, 0);
        $result = $this->visitor->visitSubroutine($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_pcre_verb(): void
    {
        $node = new PcreVerbNode('FAIL', 0, 0);
        $result = $this->visitor->visitPcreVerb($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_define(): void
    {
        $node = new DefineNode(new SequenceNode([], 0, 0), 0, 0);
        $result = $this->visitor->visitDefine($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_limit_match(): void
    {
        $node = new LimitMatchNode(1, 0, 0);
        $result = $this->visitor->visitLimitMatch($node);
        $this->assertSame('default', $result);
    }

    public function test_visit_callout(): void
    {
        $node = new CalloutNode(1, false, 0, 0);
        $result = $this->visitor->visitCallout($node);
        $this->assertSame('default', $result);
    }

    public function test_default_return(): void
    {
        // Test the protected defaultReturn method through reflection
        $reflection = new \ReflectionClass($this->visitor);
        $method = $reflection->getMethod('defaultReturn');
        $result = $method->invoke($this->visitor);
        $this->assertSame('default', $result);
    }
}

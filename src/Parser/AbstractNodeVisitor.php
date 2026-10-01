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

namespace PhpRegex\Parser;

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;

/**
 * Base visitor that returns a default value for every node.
 *
 * @template-covariant TReturn
 *
 * @implements \PhpRegex\Parser\NodeVisitorInterface<TReturn>
 */
abstract class AbstractNodeVisitor implements NodeVisitorInterface
{
    public function visitRegex(RegexNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitAlternation(AlternationNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitSequence(SequenceNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitGroup(GroupNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitQuantifier(QuantifierNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitLiteral(LiteralNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitCharLiteral(CharLiteralNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitCharType(CharTypeNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitDot(DotNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitAnchor(AnchorNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitAssertion(AssertionNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitKeep(KeepNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitCharClass(CharClassNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitRange(RangeNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitBackref(BackrefNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitControlChar(ControlCharNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitScriptRun(ScriptRunNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitExtendedCharClass(ExtendedCharClassNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitClassSetOperation(ClassSetOperationNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitVersionCondition(VersionConditionNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitUnicodeProp(UnicodePropNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitPosixClass(PosixClassNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitComment(CommentNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitConditional(ConditionalNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitSubroutine(SubroutineNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitPcreVerb(PcreVerbNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitDefine(DefineNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitLimitMatch(LimitMatchNode $node)
    {
        return $this->defaultReturn();
    }

    public function visitCallout(CalloutNode $node)
    {
        return $this->defaultReturn();
    }

    /**
     * @return TReturn
     */
    protected function defaultReturn()
    {
        /** @var TReturn $result */
        $result = null;

        return $result;
    }
}

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

namespace RegexParser\NodeVisitor;

use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Base visitor that returns a default value for every node.
 *
 * @template-covariant TReturn
 *
 * @implements NodeVisitorInterface<TReturn>
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

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    public function visitClassOperation(ClassOperationNode $node)
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

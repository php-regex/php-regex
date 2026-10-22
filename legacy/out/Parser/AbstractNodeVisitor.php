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

namespace PHPRegex\Parser;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;

/**
 * Base visitor that returns a default value for every node.
 *
 * @template-covariant TReturn = null
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

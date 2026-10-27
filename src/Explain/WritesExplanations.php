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

namespace PHPRegex\Explain;

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
use PHPRegex\Parser\Node\NodeInterface;
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
 * Explains every node the same way: the meaning the explanation builder
 * words, written out in the format of the class using it.
 *
 * @internal
 */
trait WritesExplanations
{
    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        return $this->write($node);
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        return $this->write($node);
    }

    /**
     * The explanation of a node and of what it holds, in this format, for
     * the target the class was given.
     */
    private function write(NodeInterface $node): string
    {
        return $this->render($node->accept(new ExplanationBuilder($this->target)));
    }

    /**
     * An explanation written out in this format.
     */
    abstract private function render(Explanation $explanation): string;
}

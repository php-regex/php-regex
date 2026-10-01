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
use PhpRegex\Parser\Node\NodeInterface;
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
 * Base visitor that walks the whole tree: every visit method visits the
 * node's children, in the order they stand in the pattern, and returns null.
 *
 * Override the node types you care about and call the parent method to keep
 * descending below them; an override that does not call it skips the
 * subtree. A node type added in a minor release is walked through as well,
 * so the nodes below it still reach your overrides.
 *
 * @template-covariant TReturn
 *
 * @extends \PhpRegex\Parser\AbstractNodeVisitor<TReturn>
 */
abstract class AbstractTraversingVisitor extends AbstractNodeVisitor
{
    public function visitRegex(RegexNode $node)
    {
        return $this->traverse($node);
    }

    public function visitAlternation(AlternationNode $node)
    {
        return $this->traverse($node);
    }

    public function visitSequence(SequenceNode $node)
    {
        return $this->traverse($node);
    }

    public function visitGroup(GroupNode $node)
    {
        return $this->traverse($node);
    }

    public function visitQuantifier(QuantifierNode $node)
    {
        return $this->traverse($node);
    }

    public function visitLiteral(LiteralNode $node)
    {
        return $this->traverse($node);
    }

    public function visitCharLiteral(CharLiteralNode $node)
    {
        return $this->traverse($node);
    }

    public function visitCharType(CharTypeNode $node)
    {
        return $this->traverse($node);
    }

    public function visitDot(DotNode $node)
    {
        return $this->traverse($node);
    }

    public function visitAnchor(AnchorNode $node)
    {
        return $this->traverse($node);
    }

    public function visitAssertion(AssertionNode $node)
    {
        return $this->traverse($node);
    }

    public function visitKeep(KeepNode $node)
    {
        return $this->traverse($node);
    }

    public function visitCharClass(CharClassNode $node)
    {
        return $this->traverse($node);
    }

    public function visitRange(RangeNode $node)
    {
        return $this->traverse($node);
    }

    public function visitBackref(BackrefNode $node)
    {
        return $this->traverse($node);
    }

    public function visitControlChar(ControlCharNode $node)
    {
        return $this->traverse($node);
    }

    public function visitScriptRun(ScriptRunNode $node)
    {
        return $this->traverse($node);
    }

    public function visitExtendedCharClass(ExtendedCharClassNode $node)
    {
        return $this->traverse($node);
    }

    public function visitClassSetOperation(ClassSetOperationNode $node)
    {
        return $this->traverse($node);
    }

    public function visitVersionCondition(VersionConditionNode $node)
    {
        return $this->traverse($node);
    }

    public function visitUnicodeProp(UnicodePropNode $node)
    {
        return $this->traverse($node);
    }

    public function visitPosixClass(PosixClassNode $node)
    {
        return $this->traverse($node);
    }

    public function visitComment(CommentNode $node)
    {
        return $this->traverse($node);
    }

    public function visitConditional(ConditionalNode $node)
    {
        return $this->traverse($node);
    }

    public function visitSubroutine(SubroutineNode $node)
    {
        return $this->traverse($node);
    }

    public function visitPcreVerb(PcreVerbNode $node)
    {
        return $this->traverse($node);
    }

    public function visitDefine(DefineNode $node)
    {
        return $this->traverse($node);
    }

    public function visitLimitMatch(LimitMatchNode $node)
    {
        return $this->traverse($node);
    }

    public function visitCallout(CalloutNode $node)
    {
        return $this->traverse($node);
    }

    /**
     * Visits each child of the node, then returns the default value.
     *
     * @return TReturn
     */
    protected function traverse(NodeInterface $node)
    {
        foreach ($node->getChildren() as $child) {
            $child->accept($this);
        }

        return $this->defaultReturn();
    }
}

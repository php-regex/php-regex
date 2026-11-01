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

namespace PHPRegex\Redos;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Whether a node can match without consuming a character, read from the
 * tree alone: true when it can, false when every match of it consumes,
 * null when a back reference or a call decides at match time. A range, a
 * POSIX class or a set operation is read through the class that holds it,
 * and a version condition through its conditional: visited on their own, as
 * a hand-built tree may do, they answer null.
 *
 * @extends AbstractNodeVisitor<?bool>
 *
 * @internal
 */
final class Nullability extends AbstractNodeVisitor
{
    #[\Override]
    public function visitRegex(RegexNode $node): ?bool
    {
        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): bool
    {
        return '' === $node->value;
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): bool
    {
        return false;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): bool
    {
        return false;
    }

    #[\Override]
    public function visitDot(DotNode $node): bool
    {
        return false;
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): bool
    {
        return false;
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): bool
    {
        return false;
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): bool
    {
        return false;
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): bool
    {
        return false;
    }

    /**
     * A script run matches what its body matches, in one script.
     */
    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): ?bool
    {
        return null === $node->content ? true : $node->content->accept($this);
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitComment(CommentNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): bool
    {
        return true;
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): ?bool
    {
        return null;
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): ?bool
    {
        return null;
    }

    /**
     * A lookaround consumes nothing; any other group matches as its body does.
     */
    #[\Override]
    public function visitGroup(GroupNode $node): ?bool
    {
        if (\in_array($node->type, [
            GroupType::LookaheadPositive,
            GroupType::LookaheadNegative,
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
            GroupType::ScanSubstring,
        ], true)) {
            return true;
        }

        return $node->child->accept($this);
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): ?bool
    {
        if (0 === ($node->bounds->min ?? 0)) {
            return true;
        }

        return $node->node->accept($this);
    }

    /**
     * A sequence consumes as soon as one item does; it is nullable when every
     * item is, unknown when none consumes for sure and one is unknown.
     */
    #[\Override]
    public function visitSequence(SequenceNode $node): ?bool
    {
        $unknown = false;
        foreach ($node->children as $child) {
            $nullable = $child->accept($this);
            if (false === $nullable) {
                return false;
            }
            if (null === $nullable) {
                $unknown = true;
            }
        }

        return $unknown ? null : true;
    }

    /**
     * An alternation is nullable as soon as one branch is; it consumes when
     * every branch does, unknown when none is nullable for sure and one is
     * unknown.
     */
    #[\Override]
    public function visitAlternation(AlternationNode $node): ?bool
    {
        $unknown = false;
        foreach ($node->alternatives as $alternative) {
            $nullable = $alternative->accept($this);
            if (true === $nullable) {
                return true;
            }
            if (null === $nullable) {
                $unknown = true;
            }
        }

        return $unknown ? null : false;
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): ?bool
    {
        $yes = $node->yes->accept($this);
        $no = $node->no->accept($this);

        if (true === $yes || true === $no) {
            return true;
        }
        if (false === $yes && false === $no) {
            return false;
        }

        return null;
    }
}

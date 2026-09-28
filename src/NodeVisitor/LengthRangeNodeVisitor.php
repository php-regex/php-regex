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
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierBounds;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Calculates min/max string lengths that match the regex.
 *
 * @extends AbstractNodeVisitor<array{0: int, 1: int|null}>
 */
final class LengthRangeNodeVisitor extends AbstractNodeVisitor
{
    #[\Override]
    public function visitRegex(RegexNode $node): array
    {
        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): array
    {
        $min = \PHP_INT_MAX;
        $max = 0;
        $hasInfinite = false;

        foreach ($node->alternatives as $alt) {
            [$altMin, $altMax] = $alt->accept($this);
            $min = min($min, $altMin);
            if (null === $altMax) {
                $hasInfinite = true;
            } else {
                $max = max($max, $altMax);
            }
        }

        return [$min, $hasInfinite ? null : $max];
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): array
    {
        $totalMin = 0;
        $totalMax = 0;
        $hasInfinite = false;

        foreach ($node->children as $child) {
            [$childMin, $childMax] = $child->accept($this);
            $totalMin += $childMin;
            if (null === $childMax) {
                $hasInfinite = true;
            } else {
                $totalMax += $childMax;
            }
        }

        return [$totalMin, $hasInfinite ? null : $totalMax];
    }

    #[\Override]
    public function visitGroup(GroupNode $node): array
    {
        // Lookarounds are zero-width assertions
        if (\in_array($node->type, [
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
        ], true)) {
            return [0, 0];
        }

        return $node->child->accept($this);
    }

    /**
     * @return array{0: int, 1: int|null}
     */
    #[\Override]
    public function visitQuantifier(QuantifierNode $node): array
    {
        [$childMin, $childMax] = $node->node->accept($this);

        // Parse quantifier
        $range = $this->parseQuantifierRange($node->quantifier);
        $qMin = $range[0];
        $qMax = $range[1];

        $min = $childMin * $qMin;
        $max = null;
        if (null !== $childMax && null !== $qMax) {
            $max = $childMax * $qMax;
        }

        return [$min, $max];
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitDot(DotNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): array
    {
        return [0, 0];
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): array
    {
        return [0, 0];
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitRange(RangeNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): array
    {
        return [0, null]; // Backrefs can match variable lengths
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitComment(CommentNode $node): array
    {
        return [0, 0];
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): array
    {
        // For simplicity, take the max of yes and no
        [$yesMin, $yesMax] = $node->yes->accept($this);
        [$noMin, $noMax] = $node->no->accept($this);

        $min = min($yesMin, $noMin);
        $max = null;
        if (null !== $yesMax && null !== $noMax) {
            $max = max($yesMax, $noMax);
        }

        return [$min, $max];
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): array
    {
        return [0, null]; // Subroutines can be complex
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): array
    {
        return [1, 1];
    }

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): array
    {
        return $node->content?->accept($this) ?? [0, null];
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): array
    {
        return [0, 0];
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): array
    {
        return [0, 0];
    }

    #[\Override]
    public function visitDefine(DefineNode $node): array
    {
        return [0, 0];
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): array
    {
        return [0, 0];
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): array
    {
        return [0, 0];
    }

    #[\Override]
    public function visitKeep(KeepNode $node): array
    {
        return [0, 0];
    }

    /**
     * @return array{0: int, 1: int|null}
     */
    private function parseQuantifierRange(string $q): array
    {
        $bounds = QuantifierBounds::parse($q);

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max];
    }
}

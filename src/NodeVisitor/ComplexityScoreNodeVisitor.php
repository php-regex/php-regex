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

use RegexParser\Internal\StaticCaches;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\ClassSetOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\ExtendedCharClassNode;
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
 * Visitor that calculates numeric complexity scores for regex patterns.
 *
 * This visitor provides complexity analysis with caching and
 * streamlined scoring algorithms for efficiency while detecting ReDoS patterns.
 *
 * @extends AbstractNodeVisitor<int>
 */
final class ComplexityScoreNodeVisitor extends AbstractNodeVisitor
{
    // Optimized scoring constants
    private const BASE_SCORE = 1;
    private const UNBOUNDED_QUANTIFIER_SCORE = 10;
    private const COMPLEX_CONSTRUCT_SCORE = 5;
    private const NESTING_MULTIPLIER = 2;

    // Caching for expensive operations
    /**
     * @var array<string, bool>
     */
    private static array $unboundedQuantifierCache = [];

    // Minimal state tracking
    private int $quantifierDepth = 0;

    #[\Override]
    public function visitRegex(RegexNode $node): int
    {
        // Reset state for this run
        $this->quantifierDepth = 0;

        // The score of a regex is the score of its pattern
        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): int
    {
        // Optimized: sum all alternatives with base score
        $score = self::BASE_SCORE;
        foreach ($node->alternatives as $alt) {
            $score += $alt->accept($this);
        }

        return $score;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): int
    {
        // Optimized: direct sum of all children
        $score = 0;
        foreach ($node->children as $child) {
            $score += $child->accept($this);
        }

        return $score;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): int
    {
        $childScore = $node->child->accept($this);

        // Lookarounds are considered complex - optimized enum check
        return match ($node->type) {
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
            GroupType::T_GROUP_SCAN_SUBSTRING => self::COMPLEX_CONSTRUCT_SCORE + $childScore,
            default => self::BASE_SCORE + $childScore,
        };
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): int
    {
        $quant = $node->quantifier;
        $isUnbounded = $this->isUnboundedQuantifier($quant);
        $score = 0;

        if ($isUnbounded) {
            $score += self::UNBOUNDED_QUANTIFIER_SCORE;
            if ($this->quantifierDepth > 0) {
                // Exponentially penalize nested unbounded quantifiers (ReDoS detection)
                $score *= (self::NESTING_MULTIPLIER * $this->quantifierDepth);
            }
            $this->quantifierDepth++;
        } else {
            // Bounded quantifiers are simpler
            $score += self::BASE_SCORE;
        }

        // Add the score of the quantified node
        $score += $node->node->accept($this);

        if ($isUnbounded) {
            $this->quantifierDepth--;
        }

        return $score;
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): int
    {
        // Optimized: sum parts inside character class
        $score = self::BASE_SCORE;
        $expression = $node->expression;

        if ($expression instanceof AlternationNode) {
            foreach ($expression->alternatives as $part) {
                $score += $part->accept($this);
            }
        } else {
            $score += $expression->accept($this);
        }

        return $score;
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): int
    {
        return self::COMPLEX_CONSTRUCT_SCORE;
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): int
    {
        // Conditionals are highly complex - optimized calculation
        return self::COMPLEX_CONSTRUCT_SCORE * 2
            + $node->condition->accept($this)
            + $node->yes->accept($this)
            + $node->no->accept($this);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): int
    {
        // Subroutines/recursion are highly complex
        return self::COMPLEX_CONSTRUCT_SCORE * 2;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitDot(DotNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitRange(RangeNode $node): int
    {
        // Optimized: range score includes start and end nodes
        return self::BASE_SCORE + $node->start->accept($this) + $node->end->accept($this);
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): int
    {
        return self::BASE_SCORE + $node->expression->accept($this);
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): int
    {
        return self::BASE_SCORE + ($node->left?->accept($this) ?? 0) + $node->right->accept($this);
    }

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): int
    {
        return self::BASE_SCORE + $node->left->accept($this) + $node->right->accept($this);
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): int
    {
        return self::BASE_SCORE;
    }

    #[\Override]
    public function visitComment(CommentNode $node): int
    {
        // Comments do not add to complexity
        return 0;
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): int
    {
        return self::COMPLEX_CONSTRUCT_SCORE;
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): int
    {
        return self::COMPLEX_CONSTRUCT_SCORE + ($node->content?->accept($this) ?? 0);
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): int
    {
        return self::COMPLEX_CONSTRUCT_SCORE;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): int
    {
        // DEFINE blocks add complexity from their content
        return self::COMPLEX_CONSTRUCT_SCORE + $node->content->accept($this);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): int
    {
        return self::COMPLEX_CONSTRUCT_SCORE;
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): int
    {
        // Callouts introduce external logic and break regex flow, making them complex.
        return self::COMPLEX_CONSTRUCT_SCORE;
    }

    /**
     * Cached unbounded quantifier detection.
     */
    private function isUnboundedQuantifier(string $quant): bool
    {
        // Return cached result if available
        if (isset(self::$unboundedQuantifierCache[$quant])) {
            return self::$unboundedQuantifierCache[$quant];
        }

        self::$unboundedQuantifierCache = StaticCaches::makeRoom(self::$unboundedQuantifierCache);
        StaticCaches::register(self::class, self::clearCaches(...));

        return self::$unboundedQuantifierCache[$quant] = QuantifierBounds::parse($quant)?->isUnbounded() ?? false;
    }

    private static function clearCaches(): void
    {
        self::$unboundedQuantifierCache = [];
    }
}

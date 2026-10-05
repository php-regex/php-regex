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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Internal\LibraryPcre;
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
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;

/**
 * Calculates the min/max length of the text a match consumes, from where
 * matching starts to where it ends: "\K", which only moves the start of the
 * reported match, does not shorten it; "(*ACCEPT)", which ends the match,
 * does. Lengths count bytes, or UTF-8 characters in UTF mode.
 *
 * @extends AbstractNodeVisitor<array{0: int, 1: int|null}>
 */
final class LengthRangeCalculator extends AbstractNodeVisitor
{
    private const LOOKAROUNDS = [
        GroupType::LookaheadPositive,
        GroupType::LookaheadNegative,
        GroupType::LookbehindPositive,
        GroupType::LookbehindNegative,
        GroupType::ScanSubstring,
    ];

    /**
     * @param bool $unicode whether lengths count UTF-8 characters rather than
     *                      bytes; a whole pattern sets it from its flags
     */
    public function __construct(private bool $unicode = false) {}

    #[\Override]
    public function visitRegex(RegexNode $node): array
    {
        $this->unicode = $node->isUnicode();

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

        $accepted = false;

        foreach ($node->children as $child) {
            [$childMin, $childMax] = $child->accept($this);

            // Past a "(*ACCEPT)" the match may already be over.
            if (!$accepted) {
                $totalMin += $childMin;
            }
            $accepted = $accepted || $this->mayAccept($child);

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
        if (\in_array($node->type, self::LOOKAROUNDS, true)) {
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
        // A literal holds a run of characters, or none at all for an empty
        // group, branch or option setting.
        $length = $this->unicode && 1 === LibraryPcre::match('//u', $node->value)
            ? mb_strlen($node->value, 'UTF-8')
            : \strlen($node->value);

        return [$length, $length];
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): array
    {
        return match ($node->value) {
            // "\R" matches "\r\n" as well, "\X" a whole grapheme cluster.
            'R' => [1, 2],
            'X' => [1, null],
            default => [1, 1],
        };
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

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): array
    {
        return [1, 1];
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): array
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
     * Whether a "(*ACCEPT)" inside the node may end the match there: not one
     * in a lookaround, which only ends the assertion, nor in a (DEFINE),
     * which is never run in place.
     */
    private function mayAccept(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode) {
            return str_starts_with($node->verb, 'ACCEPT');
        }

        if ($node instanceof GroupNode && \in_array($node->type, self::LOOKAROUNDS, true)) {
            return false;
        }

        $children = match (true) {
            $node instanceof GroupNode => [$node->child],
            $node instanceof SequenceNode => $node->children,
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof QuantifierNode => [$node->node],
            $node instanceof ConditionalNode => [$node->yes, $node->no],
            $node instanceof ScriptRunNode => null === $node->content ? [] : [$node->content],
            default => [],
        };

        foreach ($children as $child) {
            if ($this->mayAccept($child)) {
                return true;
            }
        }

        return false;
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

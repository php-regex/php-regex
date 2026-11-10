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
use PHPRegex\Parser\Engine\LibraryPcre;
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
 * @phpstan-type LengthRange array{0: int, 1: int|null}
 *
 * @extends AbstractNodeVisitor<LengthRange>
 */
final class LengthRangeCalculator extends AbstractNodeVisitor
{
    private const MAX_WORK = 100_000;

    /**
     * Calls followed inside calls: a pattern PCRE compiles nests a few
     * thousand at most, past which the stack of a debugging PHP gives out.
     */
    private const MAX_CALL_DEPTH = 1024;

    private const LOOKAROUNDS = [
        GroupType::LookaheadPositive,
        GroupType::LookaheadNegative,
        GroupType::LookbehindPositive,
        GroupType::LookbehindNegative,
        GroupType::ScanSubstring,
    ];

    /**
     * @var \WeakMap<NodeInterface, LengthRange>
     */
    private \WeakMap $lengths;

    private int $work = 0;

    private int $callDepth = 0;

    /**
     * A call reads as unbounded from zero, unless $calledBody gives the body
     * it runs: the call then reads as long as that body. It must give none
     * for a recursion, whose length is unknown. A call followed past 1024
     * nested calls, or past 100,000 measured nodes, reads as unbounded.
     *
     * @param bool                                            $unicode    whether lengths count UTF-8 characters rather than bytes; a whole pattern sets it from its flags
     * @param (\Closure(SubroutineNode): ?NodeInterface)|null $calledBody the body a call runs, or null where it is unknown
     */
    public function __construct(private bool $unicode = false, private readonly ?\Closure $calledBody = null)
    {
        $this->lengths = new \WeakMap();
    }

    #[\Override]
    public function visitRegex(RegexNode $node): array
    {
        $this->unicode = $node->isUnicode();
        $this->lengths = new \WeakMap();
        $this->work = 0;

        return $this->length($node->pattern);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): array
    {
        $min = \PHP_INT_MAX;
        $max = 0;
        $hasInfinite = false;

        foreach ($node->alternatives as $alt) {
            [$altMin, $altMax] = $this->length($alt);
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
            [$childMin, $childMax] = $this->length($child);

            // Past a "(*ACCEPT)" the match may already be over.
            if (!$accepted) {
                $totalMin = self::sum($totalMin, $childMin) ?? \PHP_INT_MAX;
            }
            $accepted = $accepted || AcceptVerb::mayEndMatch($child);

            $sum = null === $childMax ? null : self::sum($totalMax, $childMax);
            if (null === $sum) {
                $hasInfinite = true;
            } else {
                $totalMax = $sum;
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

        return $this->length($node->child);
    }

    /**
     * @return LengthRange
     */
    #[\Override]
    public function visitQuantifier(QuantifierNode $node): array
    {
        [$childMin, $childMax] = $this->length($node->node);

        // Parse quantifier
        $range = $this->parseQuantifierRange($node);
        $qMin = $range[0];
        $qMax = $range[1];

        $min = self::product($childMin, $qMin) ?? \PHP_INT_MAX;
        $max = null;
        if (null !== $childMax && null !== $qMax) {
            $max = self::product($childMax, $qMax);
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
        [$yesMin, $yesMax] = $this->length($node->yes);
        [$noMin, $noMax] = $this->length($node->no);

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
        if ($this->callDepth >= self::MAX_CALL_DEPTH) {
            return [0, null];
        }

        $body = null === $this->calledBody ? null : ($this->calledBody)($node);
        if (null === $body) {
            return [0, null];
        }

        $this->callDepth++;

        try {
            return $this->length($body);
        } finally {
            $this->callDepth--;
        }
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
        return null === $node->content ? [0, null] : $this->length($node->content);
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
     * Each node is measured once per pattern, so a body that several calls
     * share is read once. The work budget stops a resolver that gives ever
     * new bodies; a node it leaves unread may consume any length.
     *
     * @return LengthRange
     */
    private function length(NodeInterface $node): array
    {
        if (isset($this->lengths[$node])) {
            return $this->lengths[$node];
        }
        if ($this->work >= self::MAX_WORK) {
            return [0, null];
        }

        $this->work++;

        return $this->lengths[$node] = $node->accept($this);
    }

    /**
     * The sum of two lengths, or null past PHP_INT_MAX: the parser takes a
     * pattern longer than any integer, which PCRE refuses as too large. A
     * minimum saturates there, a maximum reads as unbounded.
     */
    private static function sum(int $a, int $b): ?int
    {
        return $a > \PHP_INT_MAX - $b ? null : $a + $b;
    }

    /**
     * The product of a length and a count, or null past PHP_INT_MAX.
     */
    private static function product(int $length, int $count): ?int
    {
        return 0 !== $count && $length > intdiv(\PHP_INT_MAX, $count) ? null : $length * $count;
    }

    /**
     * @return LengthRange
     */
    private function parseQuantifierRange(QuantifierNode $node): array
    {
        $bounds = $node->bounds;

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max];
    }
}

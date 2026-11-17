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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Linter\Rule\Support\GroupLabel;
use PHPRegex\Linter\Rule\Support\LoopShape;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects nested variable quantifiers that can cause catastrophic
 * backtracking.
 *
 * @internal
 */
final class NestedQuantifierRule extends AbstractLintRule
{
    public const ID = 'regex.lint.quantifier.nested';

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [QuantifierNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof QuantifierNode) {
            return [];
        }

        $isAtomicQuantifier = QuantifierType::Possessive === $node->type
            || ($node->node instanceof GroupNode && GroupType::Atomic === $node->node->type);

        if (!QuantifierMath::isVariable($node)) {
            return [];
        }

        if ($isAtomicQuantifier || !QuantifierMath::isRepeatable($node)) {
            return [];
        }

        if ($context->isTheTailOfAnAtomicGroup($node)) {
            return [];
        }

        $nested = $this->findNestedQuantifier($node->node);
        if (null === $nested) {
            return [];
        }

        if (LoopShape::isTrailingLoopFromOneIteration($node, $context)
            || LoopShape::isShortRunBeforeTheEnd($node, $context)
        ) {
            return [];
        }

        $iteration = LoopShape::iteration($node);
        if ($this->keepsTheIterationsApart($node, $nested, $iteration, $context)) {
            // A later loop of the iteration, behind items that may all match
            // nothing, meets the iteration before as the first one does: in
            // "(?:b?c+)+" the "c+" of one iteration meets the next one's
            // where "b?" matches nothing. Once one keeps the iterations apart,
            // what follows it reads a character: no later loop is asked.
            foreach ($this->loopsBehindNothing($iteration, $nested) as $later) {
                if (!$this->keepsTheIterationsApart($node, $later, $iteration, $context)) {
                    return $this->violation($node, $context);
                }

                break;
            }

            return null !== $iteration && $this->hasLoopMeetingWhatFollows($node, $iteration, $context) ? $this->violation($node, $context) : [];
        }

        return $this->violation($node, $context);
    }

    /**
     * Whether a loop of the iteration meets what follows it, the iteration
     * read as a ring, the next one starting where it ends: an item that
     * may take one of the loop's characters, reached past items that may
     * match nothing and take none. In "(?:\s+\w+\s*)*" the "\s*" that ends
     * one iteration meets the "\s+" that starts the next; in
     * "(?:\w+\s*\w+)*" the two runs of letters meet where "\s*" matches
     * nothing; a loop met again after every other item meets itself.
     *
     * One walk around the ring, then on to the last loop again, reads each
     * item once against the characters of every loop still walking to it:
     * an item that always reads a character ends the walk of each. A body
     * of many loops that may match nothing costs no more than one of them.
     */
    private function hasLoopMeetingWhatFollows(QuantifierNode $outer, SequenceNode $iteration, LintContext $context): bool
    {
        $children = array_values($iteration->children);
        $count = \count($children);
        $flags = array_values($context->flagsAtEachChild($iteration, $context->flagsAt($iteration, $outer->node)));

        // The characters of the loops still walking: those of the loops
        // read with case as they are, those of the caseless ones folded;
        // null while none of the kind walks.
        $cased = null;
        $caseless = null;
        $aboveAscii = false;
        $lastLoop = 0;

        for ($position = 0; $position < 2 * $count; $position++) {
            $index = $position % $count;
            $item = $children[$index];
            if (null !== $cased || null !== $caseless) {
                if ($this->meetsTheLoops($item, $flags[$index], $cased, $caseless, $aboveAscii, $context)) {
                    return true;
                }

                if (!NodePredicates::canBeEmpty($item)) {
                    $cased = null;
                    $caseless = null;
                    $aboveAscii = false;
                }
            }

            // Past the ring, each walk still going ends at its own loop.
            if ($position >= $count) {
                if ((null === $cased && null === $caseless) || $index >= $lastLoop) {
                    break;
                }

                continue;
            }

            $loop = NodePredicates::unwrapTransparentNode($item);
            if (!$loop instanceof QuantifierNode || QuantifierType::Possessive === $loop->type || !QuantifierMath::isVariable($loop)) {
                continue;
            }

            $readCaseless = self::isReadCaseless($loop->node, $flags[$index]);
            $boundary = $this->correctlyFoldedBoundary($loop->node, $context, $flags[$index], $readCaseless);
            if ($boundary->isUnknown() || $boundary->isEmpty()) {
                return true;
            }

            if ($readCaseless) {
                $boundary = $this->fold($boundary, true);
                $caseless = $caseless?->union($boundary) ?? $boundary;
            } else {
                $cased = $cased?->union($boundary) ?? $boundary;
            }

            $aboveAscii = $aboveAscii || LoopShape::mayTakeACharacterAboveAscii($loop->node, $context);
            $lastLoop = $index;
        }

        return false;
    }

    /**
     * Whether the item may take a character of the loops walking to it,
     * read as tailCanTake() reads it against each: caseless where either
     * is, above ASCII where both may go.
     */
    private function meetsTheLoops(NodeInterface $item, string $flags, ?ByteCharSet $cased, ?ByteCharSet $caseless, bool $aboveAscii, LintContext $context): bool
    {
        if ($item instanceof AnchorNode
            || $item instanceof AssertionNode
            || $item instanceof CommentNode
            || NodePredicates::isStandaloneInlineFlagsGroup($item)
        ) {
            return false;
        }

        if ($aboveAscii && LoopShape::mayTakeACharacterAboveAscii($item, $context)) {
            return true;
        }

        if (self::isReadCaseless($item, $flags)) {
            $loops = $caseless;
            if (null !== $cased) {
                $folded = $this->fold($cased, true);
                $loops = null === $loops ? $folded : $folded->union($loops);
            }

            return null !== $loops && $this->correctlyFoldedFirstChars($item, $context, $flags, true)->intersects($loops);
        }

        return (null !== $cased && $this->correctlyFoldedFirstChars($item, $context, $flags, false)->intersects($cased))
            || (null !== $caseless && $this->correctlyFoldedFirstChars($item, $context, $flags, true)->intersects($caseless));
    }

    /**
     * Whether a separator keeps the runs of the inner loop of two
     * iterations apart.
     */
    private function keepsTheIterationsApart(QuantifierNode $outer, QuantifierNode $nested, ?SequenceNode $iteration, LintContext $context): bool
    {
        return $this->isSafelySeparatedNestedQuantifier($outer, $nested, $context)
            || (null !== $iteration && LoopShape::isSeparatedIteration($iteration, $nested, $context));
    }

    /**
     * The loops written in the iteration, other than the first one found,
     * that only items which may match nothing come before.
     *
     * @return iterable<QuantifierNode>
     */
    private function loopsBehindNothing(?SequenceNode $iteration, QuantifierNode $first): iterable
    {
        foreach ($iteration->children ?? [] as $child) {
            $item = NodePredicates::unwrapTransparentNode($child);
            if ($item !== $first
                && $item instanceof QuantifierNode
                && QuantifierType::Possessive !== $item->type
                && QuantifierMath::isVariable($item)
            ) {
                yield $item;
            }

            if (!NodePredicates::canBeEmpty($child)) {
                return;
            }
        }
    }

    /**
     * @return list<RuleViolation>
     */
    private function violation(QuantifierNode $node, LintContext $context): array
    {
        return [new RuleViolation(
            self::ID,
            \sprintf(
                'Nested quantifiers in %s can cause catastrophic backtracking.',
                GroupLabel::of($node->node, $context) ?? GroupLabel::at($node->node, $context),
            ),
            $node->startPosition,
            'Consider atomic groups (?>...) or possessive quantifiers — verify the rewrite still matches everything you need.',
            end: $node->getEndPosition(),
        )];
    }

    /**
     * The first loop inside the node that may repeat a variable number of
     * times. A count fixed above zero repeats its body that many times, so
     * a loop inside it is looked for there, and past it otherwise: neither
     * "\d{4}" in "(?:\d{4}|[a-z]+)+" nor the "{2}" of "(?:(?:a+){2})+"
     * hides one.
     */
    private function findNestedQuantifier(NodeInterface $node): ?QuantifierNode
    {
        if ($node instanceof QuantifierNode) {
            if (QuantifierType::Possessive === $node->type) {
                return null;
            }

            if (QuantifierMath::isVariable($node)) {
                return $node;
            }

            [$count] = QuantifierMath::parseRange($node);

            return $count > 0 ? $this->findNestedQuantifier($node->node) : null;
        }

        // An atomic group, and a lookaround once it has matched, is never
        // tried again: a loop inside splits no run of the loop around.
        // "(*napla:...)" and "(?*...)" are backtracked into.
        if ($node instanceof GroupNode) {
            if (GroupType::Atomic === $node->type
                || ('*' !== $node->flags && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true))
            ) {
                return null;
            }

            return $this->findNestedQuantifier($node->child);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $nested = $this->findNestedQuantifier($child);
                if (null !== $nested) {
                    return $nested;
                }
            }
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                $nested = $this->findNestedQuantifier($alt);
                if (null !== $nested) {
                    return $nested;
                }
            }
        }

        if ($node instanceof ConditionalNode) {
            return $this->findNestedQuantifier($node->yes) ?? $this->findNestedQuantifier($node->no);
        }

        if ($node instanceof DefineNode) {
            return $this->findNestedQuantifier($node->content);
        }

        return null;
    }

    /**
     * Whether an item next to the inner loop keeps the iterations apart: it
     * must consume a character the inner loop can never take. When the i
     * flag is in force at the inner loop or at the item, or turned on
     * inside either, the two sets are compared case-folded; an i set where
     * neither is read folds nothing: in "(?:a+A(?i))+" the next iteration
     * starts without it.
     *
     * The item before the inner loop only separates when what follows the
     * inner loop in the iteration cannot take the inner loop's characters
     * either: in ",a*a*" the two runs share every "a" of an iteration.
     *
     * Each item is read under the flags in effect there: in "(?:(?s).+\n)+"
     * the dot takes the "\n".
     *
     * Inside the body of a fixed count, only an item after the inner loop
     * keeps it apart: past the body come the next copy and the rest of the
     * iteration, which the item before it does not see, as in
     * "(?:(?:,a*){2}a*)*".
     */
    private function isSafelySeparatedNestedQuantifier(QuantifierNode $outer, QuantifierNode $nested, LintContext $context): bool
    {
        $sequenceInfo = $this->findSequenceForNestedQuantifier($outer->node, $nested);
        if (null === $sequenceInfo) {
            return false;
        }

        $innerFlags = $context->flagsAt($nested, $outer->node);
        $innerCaseless = self::isReadCaseless($nested->node, $innerFlags);
        $innerBoundary = $this->correctlyFoldedBoundary($nested->node, $context, $innerFlags, $innerCaseless);
        if ($innerBoundary->isUnknown() || $innerBoundary->isEmpty()) {
            return false;
        }

        $innerTakesAboveAscii = LoopShape::mayTakeACharacterAboveAscii($nested->node, $context);

        $sequence = $sequenceInfo['sequence'];
        $children = $sequence->children;
        $flags = $context->flagsAtEachChild($sequence, $context->flagsAt($sequence, $outer->node));
        $index = $sequenceInfo['index'];

        if ($index > 0
            && !$sequenceInfo['inFixedCount']
            && $this->separates($children[$index - 1], $flags[$index - 1], $innerBoundary, $innerCaseless, $innerTakesAboveAscii, $context)
            && !$this->tailCanTake(\array_slice($children, $index + 1, null, true), $flags, $innerBoundary, $innerCaseless, $innerTakesAboveAscii, $context)
        ) {
            return true;
        }

        // The separator may stand behind items that may match nothing and
        // take none of the inner loop's characters: the "," behind "\s*" in
        // "(?:\d+\s*,)*".
        $count = \count($children);
        for ($afterIdx = $index + 1; $afterIdx < $count; $afterIdx++) {
            if (!NodePredicates::isConsuming($children[$afterIdx])) {
                continue;
            }

            if (NodePredicates::isOptionalNode($children[$afterIdx])
                && $this->separates($children[$afterIdx], $flags[$afterIdx], $innerBoundary, $innerCaseless, $innerTakesAboveAscii, $context, true)
            ) {
                continue;
            }

            return $this->separates($children[$afterIdx], $flags[$afterIdx], $innerBoundary, $innerCaseless, $innerTakesAboveAscii, $context);
        }

        return false;
    }

    /**
     * Whether the item next to the inner loop is a separator, both sets
     * folded when either of the two is read under the i flag.
     *
     * @param bool $mayMatchNothing whether an item that may match nothing is
     *                              asked only to take none of the inner
     *                              loop's characters
     */
    private function separates(NodeInterface $item, string $flags, ByteCharSet $innerBoundary, bool $innerCaseless, bool $innerTakesAboveAscii, LintContext $context, bool $mayMatchNothing = false): bool
    {
        $caseless = $innerCaseless || self::isReadCaseless($item, $flags);

        return $this->isExclusiveSeparator($item, $this->fold($innerBoundary, $caseless), $context, $caseless, $flags, $innerTakesAboveAscii, $mayMatchNothing);
    }

    /**
     * Whether the node is read under the i flag: in force at its start, or
     * turned on somewhere inside it.
     */
    private static function isReadCaseless(NodeInterface $node, string $flags): bool
    {
        return str_contains($flags, 'i') || NodePredicates::turnsCaselessOn($node);
    }

    /**
     * Whether the items after the inner loop can start with one of its
     * characters, looking past the ones that may match nothing. Above
     * ASCII the sets say nothing: an item that may take such a character
     * can take one of an inner loop that may too.
     *
     * @param array<NodeInterface> $tail  the items, keyed by their place in the sequence
     * @param array<string>        $flags the flags in effect at each item of the sequence
     */
    private function tailCanTake(array $tail, array $flags, ByteCharSet $innerBoundary, bool $innerCaseless, bool $innerTakesAboveAscii, LintContext $context): bool
    {
        foreach ($tail as $index => $item) {
            if ($item instanceof AnchorNode
                || $item instanceof AssertionNode
                || $item instanceof CommentNode
                || NodePredicates::isStandaloneInlineFlagsGroup($item)
            ) {
                continue;
            }

            $caseless = $innerCaseless || self::isReadCaseless($item, $flags[$index]);
            $first = $this->correctlyFoldedFirstChars($item, $context, $flags[$index], $caseless);
            if ($first->intersects($this->fold($innerBoundary, $caseless))
                || ($innerTakesAboveAscii && LoopShape::mayTakeACharacterAboveAscii($item, $context))
            ) {
                return true;
            }

            if (!NodePredicates::canBeEmpty($item)) {
                return false;
            }
        }

        return false;
    }

    /**
     * The sequence holding the inner loop, looking through groups and
     * through the body of a fixed count written in it.
     *
     * @return array{sequence: SequenceNode, index: int, inFixedCount: bool}|null
     */
    private function findSequenceForNestedQuantifier(NodeInterface $node, QuantifierNode $nested, bool $inFixedCount = false): ?array
    {
        if ($node instanceof GroupNode) {
            return $this->findSequenceForNestedQuantifier($node->child, $nested, $inFixedCount);
        }

        if ($node instanceof QuantifierNode && !QuantifierMath::isVariable($node)) {
            return $this->findSequenceForNestedQuantifier($node->node, $nested, true);
        }

        if (!($node instanceof SequenceNode)) {
            return null;
        }

        foreach ($node->children as $index => $child) {
            $unwrapped = NodePredicates::unwrapTransparentNode($child);
            if ($unwrapped === $nested) {
                return ['sequence' => $node, 'index' => $index, 'inFixedCount' => $inFixedCount];
            }

            if ($unwrapped instanceof QuantifierNode) {
                $found = $this->findSequenceForNestedQuantifier($unwrapped, $nested, $inFixedCount);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function boundaryCharSet(NodeInterface $node, LintContext $context, ?string $flags): ByteCharSet
    {
        return $context->firstChars($node, $flags)->union($context->lastChars($node, $flags));
    }

    /**
     * The boundary set of the node, correctly folded under caseless mode.
     *
     * For a negated class `[^x]` under the i flag, the raw boundary is
     * complement({x}), which contains the partner case (e.g. `X`). Naively
     * folding that set adds `x` back in, making the boundary look like
     * "all ASCII". The correct fold is complement(fold(excluded_set)):
     * fold {x} to {x, X}, then negate to get "all ASCII except x and X".
     */
    private function correctlyFoldedBoundary(NodeInterface $node, LintContext $context, ?string $flags, bool $caseless): ByteCharSet
    {
        $boundary = $this->boundaryCharSet($node, $context, $flags);

        if ($caseless && $node instanceof CharClassNode && $node->isNegated) {
            $excludedSet = $context->firstChars($node->expression, $flags);
            if (!$excludedSet->isUnknown()) {
                $foldedExcluded = $this->fold($excludedSet, true);
                if (!$foldedExcluded->isUnknown()) {
                    return $foldedExcluded->complement();
                }
            }
        }

        return $boundary;
    }

    /**
     * The characters the item starts with, folded as correctlyFoldedBoundary()
     * folds a negated class, through the loops and the groups around it:
     * "[^z]+" takes no "Z" under the i flag.
     */
    private function correctlyFoldedFirstChars(NodeInterface $item, LintContext $context, string $flags, bool $caseless): ByteCharSet
    {
        $node = NodePredicates::unwrapTransparentNode($item);
        while ($node instanceof QuantifierNode) {
            $node = NodePredicates::unwrapTransparentNode($node->node);
        }

        return $node instanceof CharClassNode && $node->isNegated
            ? $this->correctlyFoldedBoundary($node, $context, $flags, $caseless)
            : $this->fold($context->firstChars($item, $flags), $caseless);
    }

    /**
     * The set with the other case of each ASCII letter added. A byte above
     * ASCII stands for a character whose case variants the byte set cannot
     * hold (the Kelvin sign is a "k" under iu), so such a set is unknown.
     * The part of each range in "A" to "Z" or in "a" to "z" is added again
     * shifted to the other case: the rule asks it at every step of a walk.
     */
    private function fold(ByteCharSet $set, bool $caseless): ByteCharSet
    {
        if (!$caseless || $set->isUnknown()) {
            return $set;
        }

        $folded = $set;
        foreach ($set->ranges() as [$start, $end]) {
            if ($end > 0x7F) {
                return ByteCharSet::unknown();
            }

            foreach ([[0x41, 0x5A, 0x20], [0x61, 0x7A, -0x20]] as [$first, $last, $shift]) {
                $from = max($start, $first);
                $to = min($end, $last);
                if ($from <= $to) {
                    $folded = $folded->union(ByteCharSet::fromRange($from + $shift, $to + $shift));
                }
            }
        }

        return $folded;
    }

    /**
     * Above ASCII the sets say nothing, so a separator that may take such a
     * character never keeps apart an inner loop that may too: "é" after
     * ".+".
     *
     * @param string|null $flags                the flags in effect at the separator; null
     *                                          reads the ones at the current position
     * @param bool        $innerTakesAboveAscii whether the inner loop may take a character
     *                                          above ASCII; assumed when not said
     * @param bool        $mayMatchNothing      whether a separator that may match nothing is
     *                                          read as one
     */
    private function isExclusiveSeparator(NodeInterface $separator, ByteCharSet $innerBoundary, LintContext $context, bool $caseless, ?string $flags = null, bool $innerTakesAboveAscii = true, bool $mayMatchNothing = false): bool
    {
        if ((!$mayMatchNothing && NodePredicates::isOptionalNode($separator))
            || !NodePredicates::isConsuming($separator)
            || ($innerTakesAboveAscii && LoopShape::mayTakeACharacterAboveAscii($separator, $context))
        ) {
            return false;
        }

        $separatorSet = $this->fold($this->boundaryCharSet($separator, $context, $flags), $caseless);
        if ($separatorSet->isUnknown() || $separatorSet->isEmpty()) {
            return false;
        }

        return !$separatorSet->intersects($innerBoundary);
    }
}

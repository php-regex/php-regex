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

use PHPRegex\Linter\Rule\Support\LoopShape;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
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
    public function getRuleIds(): array
    {
        return ['regex.lint.quantifier.nested'];
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

        if (!QuantifierMath::isVariable($node->quantifier)) {
            return [];
        }

        if ($isAtomicQuantifier || !QuantifierMath::isRepeatable($node->quantifier)) {
            return [];
        }

        $nested = $this->findNestedQuantifier($node->node);
        if (null === $nested || !QuantifierMath::isVariable($nested->quantifier)) {
            return [];
        }

        if (LoopShape::isTrailingLoopFromOneIteration($node, $context)
            || LoopShape::isShortRunBeforeTheEnd($node, $context)
            || $this->isSafelySeparatedNestedQuantifier($node, $nested, $context)
        ) {
            return [];
        }

        $iteration = LoopShape::iteration($node);
        if (null !== $iteration && LoopShape::isSeparatedIteration($iteration, $nested, $context)) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.quantifier.nested',
            'Nested quantifiers can cause catastrophic backtracking.',
            $node->startPosition,
            'Consider atomic groups (?>...) or possessive quantifiers — verify the rewrite still matches everything you need.',
        )];
    }

    private function findNestedQuantifier(NodeInterface $node): ?QuantifierNode
    {
        if ($node instanceof QuantifierNode) {
            if (QuantifierType::Possessive === $node->type) {
                return null;
            }

            return $node;
        }

        if ($node instanceof GroupNode) {
            if (GroupType::Atomic === $node->type) {
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
     */
    private function isSafelySeparatedNestedQuantifier(QuantifierNode $outer, QuantifierNode $nested, LintContext $context): bool
    {
        $sequenceInfo = $this->findSequenceForNestedQuantifier($outer->node, $nested);
        if (null === $sequenceInfo) {
            return false;
        }

        $innerFlags = $context->flagsAt($nested, $outer->node);
        $innerCaseless = self::isReadCaseless($nested->node, $innerFlags);
        $innerBoundary = $this->boundaryCharSet($nested->node, $context, $innerFlags);
        if ($innerBoundary->isUnknown() || $innerBoundary->isEmpty()) {
            return false;
        }

        $innerTakesAboveAscii = LoopShape::mayTakeACharacterAboveAscii($nested->node, $context);

        $sequence = $sequenceInfo['sequence'];
        $children = $sequence->children;
        $flags = $context->flagsAtEachChild($sequence, $context->flagsAt($sequence, $outer->node));
        $index = $sequenceInfo['index'];

        if ($index > 0
            && $this->separates($children[$index - 1], $flags[$index - 1], $innerBoundary, $innerCaseless, $innerTakesAboveAscii, $context)
            && !$this->tailCanTake(\array_slice($children, $index + 1, null, true), $flags, $innerBoundary, $innerCaseless, $innerTakesAboveAscii, $context)
        ) {
            return true;
        }

        return $index + 1 < \count($children)
            && $this->separates($children[$index + 1], $flags[$index + 1], $innerBoundary, $innerCaseless, $innerTakesAboveAscii, $context);
    }

    /**
     * Whether the item next to the inner loop is a separator, both sets
     * folded when either of the two is read under the i flag.
     */
    private function separates(NodeInterface $item, string $flags, ByteCharSet $innerBoundary, bool $innerCaseless, bool $innerTakesAboveAscii, LintContext $context): bool
    {
        $caseless = $innerCaseless || self::isReadCaseless($item, $flags);

        return $this->isExclusiveSeparator($item, $this->fold($innerBoundary, $caseless), $context, $caseless, $flags, $innerTakesAboveAscii);
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
            $first = $this->fold($context->firstChars($item, $flags[$index]), $caseless);
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
     * @return array{sequence: SequenceNode, index: int}|null
     */
    private function findSequenceForNestedQuantifier(NodeInterface $node, QuantifierNode $nested): ?array
    {
        if ($node instanceof GroupNode) {
            return $this->findSequenceForNestedQuantifier($node->child, $nested);
        }

        if (!($node instanceof SequenceNode)) {
            return null;
        }

        foreach ($node->children as $index => $child) {
            $unwrapped = NodePredicates::unwrapTransparentNode($child);
            if ($unwrapped === $nested) {
                return ['sequence' => $node, 'index' => $index];
            }
        }

        return null;
    }

    private function boundaryCharSet(NodeInterface $node, LintContext $context, ?string $flags): ByteCharSet
    {
        return $context->firstChars($node, $flags)->union($context->lastChars($node, $flags));
    }

    /**
     * The set with the other case of each ASCII letter added. A byte above
     * ASCII stands for a character whose case variants the byte set cannot
     * hold (the Kelvin sign is a "k" under iu), so such a set is unknown.
     */
    private function fold(ByteCharSet $set, bool $caseless): ByteCharSet
    {
        if (!$caseless || $set->isUnknown()) {
            return $set;
        }

        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            if ($set->intersects(ByteCharSet::fromChar(\chr($byte)))) {
                return ByteCharSet::unknown();
            }
        }

        $folded = $set;
        foreach (range('a', 'z') as $lower) {
            $pair = ByteCharSet::fromChar($lower)->union(ByteCharSet::fromChar(strtoupper($lower)));
            if ($set->intersects($pair)) {
                $folded = $folded->union($pair);
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
     */
    private function isExclusiveSeparator(NodeInterface $separator, ByteCharSet $innerBoundary, LintContext $context, bool $caseless, ?string $flags = null, bool $innerTakesAboveAscii = true): bool
    {
        if (NodePredicates::isOptionalNode($separator)
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

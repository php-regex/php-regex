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

use PHPRegex\Linter\Rule\Support\CharClassSets;
use PHPRegex\Linter\Rule\Support\LoopShape;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects concatenated variable quantifiers where one character set is a
 * subset of the other and the quantifier can be tightened.
 *
 * @internal
 */
final class QuantifierConcatenationRule extends AbstractLintRule
{
    public const ID = 'regex.lint.quantifier.concatenation';

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [SequenceNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof SequenceNode) {
            return [];
        }

        $issues = [];
        $children = $node->children;
        $count = \count($children);
        $flags = $context->flagsAtEachChild($node);

        for ($i = 0; $i < $count - 1; $i++) {
            $left = $children[$i];
            $right = $children[$i + 1];

            if (!$left instanceof QuantifierNode || !$right instanceof QuantifierNode) {
                continue;
            }

            if ($left->type !== $right->type || QuantifierType::Possessive === $left->type) {
                continue;
            }

            if (!QuantifierMath::isVariable($left->quantifier) || !QuantifierMath::isVariable($right->quantifier)) {
                continue;
            }

            if ($left->node instanceof GroupNode && null !== $left->node->flags) {
                $turnsIOn = GroupType::InlineFlags === $left->node->type
                    && !str_contains($flags[$i], 'i')
                    && str_contains(NodePredicates::applyInlineFlags($flags[$i], $left->node->flags), 'i');
                if (!$turnsIOn) {
                    continue;
                }
            }

            if ($right->node instanceof GroupNode && null !== $right->node->flags) {
                $turnsIOn = GroupType::InlineFlags === $right->node->type
                    && !str_contains($flags[$i], 'i')
                    && str_contains(NodePredicates::applyInlineFlags($flags[$i], $right->node->flags), 'i');
                if (!$turnsIOn) {
                    continue;
                }
            }

            if ($this->nodeContainsCapturingGroup($left->node) || $this->nodeContainsCapturingGroup($right->node)) {
                continue;
            }

            // Nothing between the two runs changes the flags: both read the
            // ones in effect at the first.
            $leftAtom = $this->singleCharAtom($left->node, $context, $flags[$i]);
            $rightAtom = $this->singleCharAtom($right->node, $context, $flags[$i]);
            if (null === $leftAtom || null === $rightAtom) {
                continue;
            }

            [$leftSet, $leftGuards] = $leftAtom;
            [$rightSet, $rightGuards] = $rightAtom;
            [$leftMin, $leftMax] = QuantifierMath::parseRange($left->quantifier);
            [$rightMin, $rightMax] = QuantifierMath::parseRange($right->quantifier);

            // The unbounded side takes over the characters the other one
            // gives up; a guard on the side that gives them up goes with
            // them. A guard on the side that takes them over must hold after
            // each: it does when one of them still follows (the other side
            // keeps at least one) and what the guard refuses cannot start
            // with a character of that set.
            if (null === $rightMax && $this->isKnownSubset($left->node, $leftSet, $right->node, $rightSet, $context, $flags[$i]) && [] === $rightGuards) {
                $issues[] = new RuleViolation(
                    self::ID,
                    'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                    $left->startPosition,
                    0 === $leftMin
                        ? 'The first quantifier can match zero times already: consider dropping the whole quantified term.'
                        : 'Consider tightening the first quantifier to its minimum.',
                    end: $left->getEndPosition(),
                );

                while ($i + 1 < $count && $children[$i + 1] instanceof QuantifierNode) {
                    $i++;
                }

                continue;
            }

            if (null === $leftMax && $this->isKnownSubset($right->node, $rightSet, $left->node, $leftSet, $context, $flags[$i])
                && ([] === $leftGuards || ($rightMin > 0 && $this->guardsHoldBefore($leftGuards, $rightSet, $context, $flags[$i])))
            ) {
                $issues[] = new RuleViolation(
                    self::ID,
                    'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                    $right->startPosition,
                    0 === $rightMin
                        ? 'The second quantifier can match zero times already: consider dropping the whole quantified term.'
                        : 'Consider tightening the second quantifier to its minimum.',
                    end: $right->getEndPosition(),
                );

                while ($i + 1 < $count && $children[$i + 1] instanceof QuantifierNode) {
                    $i++;
                }
            }
        }

        return $issues;
    }

    /**
     * Whether every character the run takes is one the other run takes. The
     * sets stop at 0x7F. Above ASCII the run is known to fit only when it
     * is held to ASCII, or the other takes every character there.
     *
     * Each run is case-folded when it reads its letters in either case,
     * the other run only when all of it does. For a negated class `[^x]`
     * the fold is `complement(fold(excluded))` to avoid the unsoundness of
     * folding a complement directly. In UTF mode a caseless "k" or "s" also
     * takes the Kelvin sign or the long s, which a case-sensitive run does
     * not hold.
     */
    private function isKnownSubset(NodeInterface $run, ByteCharSet $set, NodeInterface $other, ByteCharSet $otherSet, LintContext $context, string $flags): bool
    {
        if (LoopShape::mayTakeACharacterAboveAscii($run, $context) && !LoopShape::takesEveryCharacterAboveAscii($other, $context)) {
            return false;
        }

        // Folding the run only widens it: a run partly caseless is folded.
        $runCaseless = self::readsCaseless($run, $flags) ?? true;
        $otherCaseless = self::readsCaseless($other, $flags);
        if (null === $otherCaseless) {
            return false;
        }

        if (!$runCaseless && !$otherCaseless) {
            return CharClassSets::isSubset($set, $otherSet);
        }

        $runSet = $runCaseless ? self::foldByteSet($set) : $set;
        if ($runSet->isUnknown()) {
            return false;
        }

        if ($runCaseless && !$otherCaseless && $context->pattern->unicodeMode && $runSet->intersects(self::lettersWithAPartnerAboveAscii())) {
            return false;
        }

        $otherRead = $otherCaseless ? $this->foldedSetFor($other, $otherSet, $context, $flags) : $otherSet;
        if ($otherRead->isUnknown()) {
            return false;
        }

        return CharClassSets::isSubset($runSet, $otherRead);
    }

    /**
     * Whether the run reads its letters in either case, through the groups
     * around it that only set flags; null when a group inside it sets
     * flags of its own, so that part of it may read them otherwise.
     */
    private static function readsCaseless(NodeInterface $node, string $flags): ?bool
    {
        while ($node instanceof GroupNode
            && (GroupType::NonCapturing === $node->type || GroupType::InlineFlags === $node->type)
        ) {
            if (null !== $node->flags) {
                $flags = NodePredicates::applyInlineFlags($flags, $node->flags);
            }
            $node = $node->child;
        }

        return self::holdsFlagGroup($node) ? null : str_contains($flags, 'i');
    }

    /**
     * Whether a group inside the node sets flags its character is read
     * under: a lookaround guard reads no character of the run.
     */
    private static function holdsFlagGroup(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && !NodePredicates::isTransparentGroup($node->type)) {
            return false;
        }

        if ($node instanceof GroupNode && null !== $node->flags) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsFlagGroup($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "k", "K", "s" and "S": under a caseless UTF option they also match
     * U+212A KELVIN SIGN and U+017F LATIN SMALL LETTER LONG S.
     */
    private static function lettersWithAPartnerAboveAscii(): ByteCharSet
    {
        return ByteCharSet::fromChar('k')->union(ByteCharSet::fromChar('K'))
            ->union(ByteCharSet::fromChar('s'))->union(ByteCharSet::fromChar('S'));
    }

    /**
     * The correctly case-folded character set for the other run in a subset
     * check. For a negated class, the fold is applied to the excluded set
     * and the result complemented, which avoids the soundness problem of
     * folding a wide complement directly.
     */
    private function foldedSetFor(NodeInterface $other, ByteCharSet $otherSet, LintContext $context, string $flags): ByteCharSet
    {
        $core = $other;
        while ($core instanceof GroupNode
            && (GroupType::NonCapturing === $core->type || GroupType::InlineFlags === $core->type)
        ) {
            if (GroupType::InlineFlags === $core->type && null !== $core->flags) {
                $flags = NodePredicates::applyInlineFlags($flags, $core->flags);
            }
            $core = $core->child;
        }

        if ($core instanceof CharClassNode && $core->isNegated) {
            $excludedSet = $context->firstChars($core->expression, $flags);
            if (!$excludedSet->isUnknown()) {
                $foldedExcluded = self::foldByteSet($excludedSet);
                if (!$foldedExcluded->isUnknown()) {
                    return $foldedExcluded->complement();
                }
            }

            return ByteCharSet::unknown();
        }

        return self::foldByteSet($otherSet);
    }

    /**
     * The set with the other ASCII case of each letter added. Returns
     * unknown when the set holds a byte above ASCII (case variants of those
     * code points are not representable in the byte set).
     */
    private static function foldByteSet(ByteCharSet $set): ByteCharSet
    {
        if ($set->isUnknown()) {
            return $set;
        }

        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            if ($set->intersects(ByteCharSet::fromChar(\chr($byte)))) {
                return ByteCharSet::unknown();
            }
        }

        $folded = $set;
        for ($lower = \ord('a'); $lower <= \ord('z'); $lower++) {
            $upper = $lower - 0x20;
            $lowerSet = ByteCharSet::fromChar(\chr($lower));
            $upperSet = ByteCharSet::fromChar(\chr($upper));
            if ($set->intersects($lowerSet) || $set->intersects($upperSet)) {
                $folded = $folded->union($lowerSet)->union($upperSet);
            }
        }

        return $folded;
    }

    /**
     * The character set of an atom matching one character, and the bodies
     * of the negative lookaheads that may follow that character inside it,
     * as in (?:[a-z](?!__)).
     *
     * The set is read under the flags in effect at the atom. Under i a
     * guard is refused: the character sets are read without case folding,
     * and "(?!A)" also refuses an "a" there.
     *
     * @return array{ByteCharSet, list<NodeInterface>}|null
     */
    private function singleCharAtom(NodeInterface $node, LintContext $context, string $flags): ?array
    {
        $core = $node;
        $guards = [];
        $inner = $node instanceof GroupNode && GroupType::NonCapturing === $node->type ? $node->child : $node;
        if ($inner instanceof SequenceNode) {
            $children = $inner->children;
            while ([] !== $children) {
                $last = $children[\count($children) - 1];
                if (!$last instanceof GroupNode || GroupType::LookaheadNegative !== $last->type) {
                    break;
                }
                array_unshift($guards, $last->child);
                array_pop($children);
            }

            if ([] !== $guards) {
                if (1 !== \count($children) || str_contains($flags, 'i') || NodePredicates::turnsCaselessOn(...$guards)) {
                    return null;
                }
                $core = $children[0];
            }
        }

        if (!NodePredicates::nodeIsSingleChar($core) || !NodePredicates::isConsuming($core)) {
            return null;
        }

        $set = $context->firstChars($core, $flags);

        if ($set->isUnknown() || $set->isEmpty()) {
            return null;
        }

        return [$set, $guards];
    }

    /**
     * Whether each (?!X) holds wherever the text starts with a character of
     * the set: X needs at least one character, and none it can start with
     * is in the set. A lookaround in X is refused: the first characters
     * read "(?<!b)a" as starting with a "b", yet it matches an "a". A
     * backreference or a subroutine call needs no such refusal: where one
     * can come first in X, its first characters are unknown.
     *
     * @param list<NodeInterface> $guards
     */
    private function guardsHoldBefore(array $guards, ByteCharSet $next, LintContext $context, string $flags): bool
    {
        foreach ($guards as $guard) {
            if (NodePredicates::readsBeyondItsCharacter($guard)) {
                return false;
            }

            [$min] = $guard->accept(new LengthRangeCalculator());
            $first = $context->firstChars($guard, $flags);
            if (0 === $min || $first->isUnknown() || $first->intersects($next)) {
                return false;
            }
        }

        return true;
    }

    private function nodeContainsCapturingGroup(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode) {
            if (GroupType::BranchReset === $node->type
                || GroupType::Capturing === $node->type
                || GroupType::Named === $node->type
            ) {
                return true;
            }

            return $this->nodeContainsCapturingGroup($node->child);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                if ($this->nodeContainsCapturingGroup($alt)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if ($this->nodeContainsCapturingGroup($child)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof QuantifierNode) {
            return $this->nodeContainsCapturingGroup($node->node);
        }

        if ($node instanceof ConditionalNode) {
            return $this->nodeContainsCapturingGroup($node->condition)
                || $this->nodeContainsCapturingGroup($node->yes)
                || $this->nodeContainsCapturingGroup($node->no);
        }

        if ($node instanceof DefineNode) {
            return $this->nodeContainsCapturingGroup($node->content);
        }

        if ($node instanceof CharClassNode) {
            return $this->nodeContainsCapturingGroup($node->expression);
        }

        if ($node instanceof RangeNode) {
            return $this->nodeContainsCapturingGroup($node->start)
                || $this->nodeContainsCapturingGroup($node->end);
        }

        return false;
    }
}

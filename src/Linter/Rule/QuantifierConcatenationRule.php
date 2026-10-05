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
    public function getRuleIds(): array
    {
        return ['regex.lint.quantifier.concatenation'];
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
                continue;
            }

            if ($right->node instanceof GroupNode && null !== $right->node->flags) {
                continue;
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
            if (null === $rightMax && CharClassSets::isSubset($leftSet, $rightSet) && [] === $rightGuards) {
                $issues[] = new RuleViolation(
                    'regex.lint.quantifier.concatenation',
                    'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                    $left->startPosition,
                    0 === $leftMin
                        ? 'The first quantifier can match zero times already: consider dropping the whole quantified term.'
                        : 'Consider tightening the first quantifier to its minimum.',
                );

                while ($i + 1 < $count && $children[$i + 1] instanceof QuantifierNode) {
                    $i++;
                }

                continue;
            }

            if (null === $leftMax && CharClassSets::isSubset($rightSet, $leftSet)
                && ([] === $leftGuards || ($rightMin > 0 && $this->guardsHoldBefore($leftGuards, $rightSet, $context, $flags[$i])))
            ) {
                $issues[] = new RuleViolation(
                    'regex.lint.quantifier.concatenation',
                    'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                    $right->startPosition,
                    0 === $rightMin
                        ? 'The second quantifier can match zero times already: consider dropping the whole quantified term.'
                        : 'Consider tightening the second quantifier to its minimum.',
                );

                while ($i + 1 < $count && $children[$i + 1] instanceof QuantifierNode) {
                    $i++;
                }
            }
        }

        return $issues;
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

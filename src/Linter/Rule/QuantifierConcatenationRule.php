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

            $leftSet = $this->singleCharNodeCharSet($left->node, $context);
            $rightSet = $this->singleCharNodeCharSet($right->node, $context);
            if (null === $leftSet || null === $rightSet) {
                continue;
            }

            [, $leftMax] = QuantifierMath::parseRange($left->quantifier);
            [, $rightMax] = QuantifierMath::parseRange($right->quantifier);

            if (null === $rightMax && CharClassSets::isSubset($leftSet, $rightSet)) {
                [$leftMin] = QuantifierMath::parseRange($left->quantifier);
                $issues[] = new RuleViolation(
                    'regex.lint.quantifier.concatenation',
                    'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                    $left->startPosition,
                    0 === $leftMin
                        ? 'The first quantifier can match zero times already: consider dropping it entirely.'
                        : 'Consider tightening the first quantifier to its minimum.',
                );

                while ($i + 1 < $count && $children[$i + 1] instanceof QuantifierNode) {
                    $i++;
                }

                continue;
            }

            if (null === $leftMax && CharClassSets::isSubset($rightSet, $leftSet)) {
                [$rightMin] = QuantifierMath::parseRange($right->quantifier);
                $issues[] = new RuleViolation(
                    'regex.lint.quantifier.concatenation',
                    'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                    $right->startPosition,
                    0 === $rightMin
                        ? 'The second quantifier can match zero times already: consider dropping it entirely.'
                        : 'Consider tightening the second quantifier to its minimum.',
                );

                while ($i + 1 < $count && $children[$i + 1] instanceof QuantifierNode) {
                    $i++;
                }
            }
        }

        return $issues;
    }

    private function singleCharNodeCharSet(NodeInterface $node, LintContext $context): ?ByteCharSet
    {
        if (!NodePredicates::nodeIsSingleChar($node) || !NodePredicates::isConsuming($node)) {
            return null;
        }

        $set = $context->charSetAnalyzer->firstChars($node);

        if ($set->isUnknown() || $set->isEmpty()) {
            return null;
        }

        return $set;
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

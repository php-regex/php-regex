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

use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Node\AlternationNode;
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

        if ($this->isSafelySeparatedNestedQuantifier($node, $nested, $context)) {
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

    private function isSafelySeparatedNestedQuantifier(QuantifierNode $outer, QuantifierNode $nested, LintContext $context): bool
    {
        $sequenceInfo = $this->findSequenceForNestedQuantifier($outer->node, $nested);
        if (null === $sequenceInfo) {
            return false;
        }

        $innerBoundary = $this->boundaryCharSet($nested->node, $context);
        if ($innerBoundary->isUnknown() || $innerBoundary->isEmpty()) {
            return false;
        }

        $sequence = $sequenceInfo['sequence'];
        $index = $sequenceInfo['index'];
        $neighbors = [];
        if ($index > 0) {
            $neighbors[] = $sequence->children[$index - 1];
        }
        if ($index + 1 < \count($sequence->children)) {
            $neighbors[] = $sequence->children[$index + 1];
        }

        foreach ($neighbors as $neighbor) {
            if ($this->isExclusiveSeparator($neighbor, $innerBoundary, $context)) {
                return true;
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

    private function boundaryCharSet(NodeInterface $node, LintContext $context): ByteCharSet
    {
        $first = $context->charSetAnalyzer->firstChars($node);
        $last = $context->charSetAnalyzer->lastChars($node);

        return $first->union($last);
    }

    private function isExclusiveSeparator(NodeInterface $separator, ByteCharSet $innerBoundary, LintContext $context): bool
    {
        if (NodePredicates::isOptionalNode($separator) || !NodePredicates::isConsuming($separator)) {
            return false;
        }

        $separatorSet = $this->boundaryCharSet($separator, $context);
        if ($separatorSet->isUnknown() || $separatorSet->isEmpty()) {
            return false;
        }

        return !$separatorSet->intersects($innerBoundary);
    }
}

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

namespace PhpRegex\Linter\Rule;

use PhpRegex\Linter\Rule\Support\QuantifierMath;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\SequenceNode;

/**
 * Detects an unbounded quantifier wrapping a dot-star, which can cause
 * severe backtracking.
 */
final class NestedDotStarRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.dotstar.nested'];
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

        if (!QuantifierMath::isVariable($node->quantifier)) {
            return [];
        }

        $isAtomicQuantifier = QuantifierType::Possessive === $node->type
            || ($node->node instanceof GroupNode && GroupType::Atomic === $node->node->type);

        if ($isAtomicQuantifier
            || !QuantifierMath::isUnbounded($node->quantifier)
            || !$this->containsDotStar($node->node)
        ) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.dotstar.nested',
            'An unbounded quantifier wraps a dot-star, which can cause severe backtracking.',
            $node->startPosition,
            'Refactor with atomic groups or a more specific character class.',
        )];
    }

    private function containsDotStar(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode && $node->node instanceof DotNode) {
            return QuantifierMath::isUnbounded($node->quantifier);
        }

        if ($node instanceof GroupNode) {
            return $this->containsDotStar($node->child);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if ($this->containsDotStar($child)) {
                    return true;
                }
            }
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                if ($this->containsDotStar($alt)) {
                    return true;
                }
            }
        }

        if ($node instanceof ConditionalNode) {
            return $this->containsDotStar($node->yes) || $this->containsDotStar($node->no);
        }

        if ($node instanceof DefineNode) {
            return $this->containsDotStar($node->content);
        }

        return false;
    }
}

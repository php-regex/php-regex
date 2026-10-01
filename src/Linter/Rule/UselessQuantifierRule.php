<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule;

use PhpRegex\Linter\Rule\Support\QuantifierMath;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\QuantifierNode;

/**
 * Detects {1} quantifiers, which match exactly once and can be removed.
 */
final class UselessQuantifierRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.quantifier.useless'];
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

        if (!str_starts_with($node->quantifier, '{')) {
            return [];
        }

        [$min, $max] = QuantifierMath::parseRange($node->quantifier);
        if (1 !== $min || 1 !== $max) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.quantifier.useless',
            'Quantifier is redundant; it matches exactly once.',
            $node->startPosition,
            'Remove the {1} quantifier; it does not change the match.',
        )];
    }
}

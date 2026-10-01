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

use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;

/**
 * Detects {0} quantifiers, which always repeat zero times.
 */
final class ZeroQuantifierRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.quantifier.zero'];
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
        if (0 !== $min || 0 !== $max) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.quantifier.zero',
            'Quantifier always repeats zero times; it can be removed.',
            $node->startPosition,
            'Remove the quantified element or replace it with an empty pattern.',
        )];
    }
}

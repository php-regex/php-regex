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

use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;

/**
 * Detects a quantifier on a lookaround. With a minimum of zero PCRE tries the
 * rest of the pattern with and without the assertion, so "/(?=a)?b/" matches
 * "b"; with a minimum of one or more the assertion is checked once.
 *
 * @internal
 */
final class QuantifiedAssertionRule extends AbstractLintRule
{
    private const ID = 'regex.lint.quantifier.assertion';

    private const LOOKAROUNDS = [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative];

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
        if (!$node instanceof QuantifierNode || !$node->node instanceof GroupNode || !\in_array($node->node->type, self::LOOKAROUNDS, true)) {
            return [];
        }

        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null === $bounds || 0 === $bounds->max || (1 === $bounds->min && 1 === $bounds->max)) {
            return [];
        }

        if (0 === $bounds->min) {
            // A lookaround that captures still sets its groups when it holds.
            if (self::captures($node->node->child)) {
                return [];
            }

            return [new RuleViolation(
                self::ID,
                \sprintf('Quantifier "%s" lets the match skip the assertion, so it constrains nothing.', $node->quantifier),
                $node->getStartPosition(),
                'Remove the quantifier to require the assertion, or remove the assertion.',
            )];
        }

        return [new RuleViolation(
            self::ID,
            \sprintf('Quantifier "%s" on an assertion changes nothing: PCRE checks it once.', $node->quantifier),
            $node->getStartPosition(),
            'Remove the quantifier.',
        )];
    }

    private static function captures(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::Named], true)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::captures($child)) {
                return true;
            }
        }

        return false;
    }
}

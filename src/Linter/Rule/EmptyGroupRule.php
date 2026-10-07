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

use PHPRegex\Linter\Rule\Support\EscapeJoin;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects an empty non-capturing or atomic group, "(?:)" or "(?>)": it
 * matches the empty string and can go. An empty capturing group "()" is a
 * placeholder that numbers a group, and an empty lookaround has rules of
 * its own; a "(?:)" that keeps an escape apart from the digit after it, as
 * in "(a)\1(?:)0", or braces from a count, as in "a{(?:)2}", stays. So does
 * the operand of a quantifier: without it, "a(?:)?" is "a?". An unbounded
 * repeat of it is quantifier.emptyRepeat's.
 *
 * @internal
 */
final class EmptyGroupRule extends AbstractLintRule
{
    private const ID = 'regex.lint.group.empty';

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [GroupNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof GroupNode
            || !\in_array($node->type, [GroupType::NonCapturing, GroupType::Atomic], true)
            || !self::isEmpty($node->child)
            || EscapeJoin::groupSeparatesAnEscape($node, $context)
            || self::isQuantified($context)
        ) {
            return [];
        }

        $written = GroupType::Atomic === $node->type ? '(?>)' : '(?:)';

        return [new RuleViolation(
            self::ID,
            \sprintf('Empty group "%s" matches nothing and changes nothing.', $written),
            $node->getStartPosition(),
            'Remove the group.',
        )];
    }

    private static function isEmpty(NodeInterface $node): bool
    {
        return ($node instanceof LiteralNode && '' === $node->value)
            || ($node instanceof SequenceNode && [] === $node->children);
    }

    /**
     * Whether the group being checked is the operand of a quantifier, read
     * from the traversal cursor.
     */
    private static function isQuantified(LintContext $context): bool
    {
        $parents = $context->parents();

        return end($parents) instanceof QuantifierNode;
    }
}

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

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects a lazy quantifier nothing follows: the match ends as soon as it
 * may, so the quantifier matches its minimum and "/a\d+?/" matches "a1" of
 * "a123".
 *
 * @internal
 */
final class LazyEndRule extends AbstractLintRule
{
    private const ID = 'regex.lint.quantifier.lazyEnd';

    private const TRANSPARENT_GROUPS = [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags];

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
        if (!$node instanceof QuantifierNode || QuantifierType::Possessive === $node->type) {
            return [];
        }

        $lazy = (QuantifierType::Lazy === $node->type) !== str_contains($context->activeFlags(), 'U');
        $bounds = QuantifierBounds::parse($node->quantifier);
        if (!$lazy || null === $bounds || $bounds->min === $bounds->max || !self::endsThePattern($node, $context->parents())) {
            return [];
        }

        $written = $node->quantifier.(QuantifierType::Lazy === $node->type ? '?' : '');

        return [new RuleViolation(
            self::ID,
            \sprintf('Lazy quantifier "%s" ends the pattern, so it always matches its minimum.', $written),
            $node->getStartPosition(),
            0 === $bounds->min
                ? 'Remove the quantified item, make the quantifier greedy, or anchor what must follow it.'
                : 'Write the minimum count, make the quantifier greedy, or anchor what must follow it.',
        )];
    }

    /**
     * Whether nothing can be matched after the node: each enclosing node
     * ends with it, up to the pattern itself.
     *
     * @param list<NodeInterface> $parents
     */
    private static function endsThePattern(NodeInterface $node, array $parents): bool
    {
        $child = $node;
        foreach (array_reverse($parents) as $parent) {
            $last = match (true) {
                $parent instanceof SequenceNode => self::onlyCommentsFollow($parent, $child) ? $child : null,
                $parent instanceof AlternationNode => $child,
                $parent instanceof GroupNode && \in_array($parent->type, self::TRANSPARENT_GROUPS, true) => $parent->child,
                default => null,
            };

            if ($last !== $child) {
                return false;
            }

            $child = $parent;
        }

        return true;
    }

    private static function onlyCommentsFollow(SequenceNode $sequence, NodeInterface $item): bool
    {
        $after = \array_slice($sequence->children, (int) array_search($item, $sequence->children, true) + 1);

        return [] === array_filter($after, static fn (NodeInterface $next): bool => !$next instanceof CommentNode);
    }
}

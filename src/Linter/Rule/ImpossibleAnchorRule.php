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

use PhpRegex\Linter\Rule\Support\NodePredicates;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\SequenceNode;

/**
 * Detects start anchors after consuming characters and end anchors before
 * consuming characters, which make the sequence impossible to match.
 *
 * The two rule IDs interleave per child index; keeping them in one rule
 * preserves the historical emission order.
 */
final class ImpossibleAnchorRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.anchor.impossible.start', 'regex.lint.anchor.impossible.end'];
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

        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i];

            if (NodePredicates::isStartAnchorNode($child)) {
                $skipForMultiline = $child instanceof AnchorNode
                    && '^' === $child->value
                    && $context->pattern->hasFlag('m');

                if (!$skipForMultiline) {
                    $anchorLabel = NodePredicates::anchorDisplay($child);
                    $prefix = array_values(array_slice($children, 0, $i));
                    if ([] !== $prefix && !NodePredicates::sequenceCanBeEmpty($prefix)) {
                        $issues[] = new RuleViolation(
                            'regex.lint.anchor.impossible.start',
                            \sprintf(
                                "Start anchor '%s' appears after consuming characters, making it impossible to match.",
                                $anchorLabel,
                            ),
                            $child->getStartPosition(),
                        );
                    }
                }
            }

            if (NodePredicates::isEndAnchorNode($child)) {
                $anchorLabel = NodePredicates::anchorDisplay($child);
                $tail = array_values(array_slice($children, $i + 1));
                if ([] !== $tail && !NodePredicates::sequenceCanBeEmpty($tail)) {
                    $issues[] = new RuleViolation(
                        'regex.lint.anchor.impossible.end',
                        \sprintf(
                            "End anchor '%s' appears before consuming characters, making it impossible to match.",
                            $anchorLabel,
                        ),
                        $child->getStartPosition(),
                    );
                }
            }
        }

        return $issues;
    }
}

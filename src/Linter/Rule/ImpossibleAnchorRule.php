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
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects start anchors after consuming characters and end anchors before
 * consuming characters, which make the sequence impossible to match.
 *
 * The two rule IDs interleave per child index; keeping them in one rule
 * preserves the historical emission order.
 *
 * @internal
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

        // A DEFINE body never executes on its own, so an impossibility
        // verdict about it says nothing about the pattern.
        foreach ($context->parents() as $parent) {
            if ($parent instanceof DefineNode) {
                return [];
            }
        }

        $issues = [];
        $children = array_values($node->children);
        $count = \count($children);

        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i];
            $flags = $this->effectiveFlagsAt($children, $i, $context);

            if (NodePredicates::isStartAnchorNode($child)) {
                $skipForMultiline = $child instanceof AnchorNode
                    && '^' === $child->value
                    && str_contains($flags, 'm');

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
                if ([] !== $tail
                    && !NodePredicates::sequenceCanBeEmpty($tail)
                    && !$this->tailCanContinueEndOfLine($child, $tail, $flags, $node, $context)
                ) {
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

    /**
     * The enclosing-scope flags folded with every bare (?flags) group that
     * precedes the child inside its own sequence.
     *
     * @param array<int, NodeInterface> $children
     */
    private function effectiveFlagsAt(array $children, int $index, LintContext $context): string
    {
        $flags = $context->activeFlags();

        for ($j = 0; $j < $index; $j++) {
            $sibling = $children[$j];
            if ($sibling instanceof GroupNode
                && null !== $sibling->flags
                && NodePredicates::isStandaloneInlineFlagsGroup($sibling)) {
                $flags = NodePredicates::applyInlineFlags($flags, $sibling->flags);
            }
        }

        return $flags;
    }

    /**
     * `$` and `\Z` also match just before the subject's final newline (and
     * a multiline `$` before any newline), so a tail that can continue one
     * of those newline matches is not impossible. `\z` only matches at the
     * absolute end and stays strict, as does `$` under the D modifier
     * (which multiline disables, as PCRE does). The exact-newline
     * continuations additionally require the sequence to end the pattern:
     * `$` before the final newline leaves nothing for content that follows
     * the enclosing group.
     *
     * @param array<int, NodeInterface> $nodes
     */
    private function tailCanContinueEndOfLine(NodeInterface $anchor, array $nodes, string $flags, SequenceNode $sequence, LintContext $context): bool
    {
        $multiline = str_contains($flags, 'm');
        $dotAll = str_contains($flags, 's');

        if ($anchor instanceof AssertionNode) {
            if ('z' === $anchor->value) {
                return false;
            }

            return NodePredicates::tailCanMatchNewline($nodes, $context->charSetAnalyzer, $dotAll)
                && $this->sequenceEndsPattern($sequence, $context);
        }

        if ($context->pattern->hasFlag('D') && !$multiline) {
            return false;
        }

        if ($multiline) {
            return NodePredicates::tailCanStartWithNewline($nodes, $context->charSetAnalyzer, $dotAll);
        }

        return NodePredicates::tailCanMatchNewline($nodes, $context->charSetAnalyzer, $dotAll)
            && $this->sequenceEndsPattern($sequence, $context);
    }

    /**
     * Whether only always-empty content follows this sequence inside its
     * enclosing scopes — the newline a `$`/`\Z` continuation consumes is
     * the subject's last byte, so anything consuming after the enclosing
     * group would make the match impossible after all.
     */
    private function sequenceEndsPattern(SequenceNode $node, LintContext $context): bool
    {
        $child = $node;

        foreach (array_reverse($context->parents()) as $parent) {
            if ($parent instanceof SequenceNode) {
                $index = array_search($child, $parent->children, true);

                if (false !== $index) {
                    $followers = array_values(array_slice($parent->children, (int) $index + 1));
                    if (!NodePredicates::alwaysMatchesEmpty($followers, true)) {
                        return false;
                    }
                }
            }

            $child = $parent;
        }

        return true;
    }
}

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

namespace PHPRegex\Optimizer;

use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * The flags of a pattern for Rewriter: the "s" and "m" nothing in the
 * pattern reads, which it removes, and whether an inline flag stands
 * anywhere, which keeps the rewrites that read the outer flags only out of
 * the pattern.
 *
 * It reads the tree it is handed and keeps nothing between two calls.
 *
 * @internal
 */
final class FlagRewrites
{
    /**
     * Removes useless flags from the flags string.
     */
    public function removeUselessFlags(string $flags, NodeInterface $pattern): string
    {
        // Remove 's' flag if there are no dots in the pattern
        if (str_contains($flags, 's') && !$this->patternContainsDots($pattern)) {
            $flags = str_replace('s', '', $flags);
        }

        // Remove 'm' flag if there are no ^ or $ anchors
        if (str_contains($flags, 'm') && !$this->patternContainsMultilineAnchors($pattern)) {
            $flags = str_replace('m', '', $flags);
        }

        return $flags;
    }

    /**
     * Whether an inline flag stands anywhere under the node. The charset
     * analyzer reads the outer flags only, so an inline flag can change
     * what a node matches without the disjointness check seeing it, from
     * where it stands to the end of its group: possessification then
     * stays out of the whole pattern.
     */
    public function containsInlineFlags(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && null !== $node->flags) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->containsInlineFlags($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a dot stands anywhere in the pattern. Every node is walked
     * through its children, so a dot inside a DEFINE body, a script run or
     * any other container still reads the flag.
     */
    private function patternContainsDots(NodeInterface $node): bool
    {
        if ($node instanceof DotNode) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->patternContainsDots($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a ^ or $ anchor, the two that depend on multiline mode,
     * stands anywhere in the pattern.
     */
    private function patternContainsMultilineAnchors(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode) {
            return '^' === $node->value || '$' === $node->value;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->patternContainsMultilineAnchors($child)) {
                return true;
            }
        }

        return false;
    }
}

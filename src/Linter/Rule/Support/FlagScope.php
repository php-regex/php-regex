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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Where the inline flags of a pattern hold. A scoped "(?s:...)" sets them
 * inside itself; a standalone "(?s)" sets them for what follows it up to
 * the end of the group holding it: the rest of its alternative, the
 * alternatives after its own, and the no branch of a conditional after its
 * yes branch. A group, a conditional, a DEFINE and a script run restore
 * the flags at their end.
 *
 * @internal
 */
final class FlagScope
{
    private function __construct() {}

    /**
     * The flags in effect inside a group whose start reads $flags: a scoped
     * "(?s:...)" changes them.
     */
    public static function flagsInside(GroupNode $group, string $flags): string
    {
        return GroupType::InlineFlags === $group->type && null !== $group->flags && !NodePredicates::isStandaloneInlineFlags($group)
            ? NodePredicates::applyInlineFlags($flags, $group->flags)
            : $flags;
    }

    /**
     * The flags in effect after a node whose start reads $flags: a
     * standalone "(?s)" changes them, as does one at the top level of an
     * alternative; any other node restores them at its end.
     */
    public static function flagsAfter(NodeInterface $node, string $flags): string
    {
        if (NodePredicates::isStandaloneInlineFlagsGroup($node)) {
            return NodePredicates::applyInlineFlags($flags, (string) $node->flags);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $flags = self::flagsAfter($child, $flags);
            }
        }

        return $flags;
    }

    /**
     * The flags in effect at each item of a sequence, or at each
     * alternative of an alternation, whose start reads $flags.
     *
     * @return array<string> the flags, keyed as the children are
     */
    public static function flagsAtEachChild(SequenceNode|AlternationNode $node, string $flags): array
    {
        $atEachChild = [];
        foreach ($node instanceof SequenceNode ? $node->children : $node->alternatives as $index => $child) {
            $atEachChild[$index] = $flags;
            $flags = self::flagsAfter($child, $flags);
        }

        return $atEachChild;
    }

    /**
     * The flags in effect at $target, searched for inside $node whose start
     * reads $flags; null when $target is not inside $node.
     */
    public static function flagsAt(NodeInterface $target, NodeInterface $node, string $flags): ?string
    {
        if ($node === $target) {
            return $flags;
        }

        if ($node instanceof GroupNode) {
            return self::flagsAt($target, $node->child, self::flagsInside($node, $flags));
        }

        foreach ($node->getChildren() as $child) {
            $found = self::flagsAt($target, $child, $flags);
            if (null !== $found) {
                return $found;
            }

            $flags = self::flagsAfter($child, $flags);
        }

        return null;
    }

    /**
     * Whether an inline flag group stands anywhere under the node, the node
     * itself included.
     */
    public static function containsInlineFlags(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && null !== $node->flags) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::containsInlineFlags($child)) {
                return true;
            }
        }

        return false;
    }
}

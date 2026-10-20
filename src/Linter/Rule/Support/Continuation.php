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

use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * What can be matched after a node of one pattern, read from the nodes
 * around it: $parents lists them from the root down, the node's own parent
 * last. Each sequence is read once for each question, from its end, and
 * the answer kept for every item of it.
 *
 * @internal
 */
final class Continuation
{
    /**
     * Groups after which nothing more is matched once their child is.
     */
    private const GROUPS_ENDING_WITH_THEIR_CHILD = [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags];

    /**
     * For each sequence asked about and each kind of question, whether
     * nothing but what the question allows follows each of its items,
     * keyed by the item's object id.
     *
     * @var array<string, array<int, bool>>
     */
    private array $followed = [];

    /**
     * The index of each item of each sequence asked about, keyed by the
     * sequence's object id, then the item's.
     *
     * @var array<int, array<int, int>>
     */
    private array $positions = [];

    public function __construct(private readonly GroupIndex $groups) {}

    /**
     * Whether nothing can be matched after the node wherever it runs: where
     * it is written, nothing follows it, and no subroutine call runs it
     * again at another point of the match. With $endAnchorsMayFollow, "$",
     * "\z" and "\Z" may follow it as well.
     *
     * @param list<NodeInterface> $parents
     */
    public function endsThePatternForEveryCall(NodeInterface $node, array $parents, bool $endAnchorsMayFollow = false): bool
    {
        return $this->nothingFollows($node, $parents, $endAnchorsMayFollow, false) && !$this->isReenteredBySubroutine($node, $parents);
    }

    /**
     * Whether only items that may match nothing, and hold nothing that can
     * fail, can follow the node wherever it runs: "b*" or "(?:b|)", never
     * an anchor, a lookaround, a verb or a reference. Whatever the node
     * matches, the rest of the pattern then matches the empty string after
     * it, at the first try.
     *
     * @param list<NodeInterface> $parents
     */
    public function onlyEmptyMatchesFollowForEveryCall(NodeInterface $node, array $parents): bool
    {
        return $this->nothingFollows($node, $parents, false, true) && !$this->isReenteredBySubroutine($node, $parents);
    }

    /**
     * Whether a subroutine call can run the node again at another point of
     * the match: the node is, or sits inside, a group some call targets, or
     * the pattern recurses into itself whole. What follows the node where it
     * is written then says nothing about what follows it when called.
     *
     * @param list<NodeInterface> $parents
     */
    public function isReenteredBySubroutine(NodeInterface $node, array $parents): bool
    {
        if ($this->groups->recurses) {
            return true;
        }

        if ([] === $this->groups->subroutineTargets) {
            return false;
        }

        foreach ([$node, ...$parents] as $candidate) {
            if ($candidate instanceof GroupNode && $this->groups->isSubroutineTarget($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the items written after the node in each sequence around it
     * pass the test, the innermost sequence first.
     *
     * @param list<NodeInterface>                 $parents
     * @param \Closure(list<NodeInterface>): bool $test
     */
    public function followersPass(NodeInterface $node, array $parents, \Closure $test): bool
    {
        $child = $node;
        foreach (array_reverse($parents) as $parent) {
            if ($parent instanceof SequenceNode) {
                $index = $this->positionsIn($parent)[spl_object_id($child)] ?? null;
                if (null !== $index && !$test(\array_slice($parent->children, $index + 1))) {
                    return false;
                }
            }

            $child = $parent;
        }

        return true;
    }

    /**
     * Whether nothing can be matched after the node: each enclosing node
     * ends with it, up to the pattern itself. Only comments may follow it;
     * an anchor, a lookaround or any other assertion after it can fail and
     * send the engine back into it. With $endAnchorsMayFollow, "$", "\z"
     * and "\Z" may follow it as well; with $emptyMatchesMayFollow, items
     * that match nothing first.
     *
     * @param list<NodeInterface> $parents
     */
    private function nothingFollows(NodeInterface $node, array $parents, bool $endAnchorsMayFollow, bool $emptyMatchesMayFollow): bool
    {
        $child = $node;
        foreach (array_reverse($parents) as $parent) {
            $last = match (true) {
                $parent instanceof SequenceNode => $this->onlyAllowedFollow($parent, $child, $endAnchorsMayFollow, $emptyMatchesMayFollow) ? $child : null,
                $parent instanceof AlternationNode => $child,
                $parent instanceof GroupNode && \in_array($parent->type, self::GROUPS_ENDING_WITH_THEIR_CHILD, true) => $parent->child,
                default => null,
            };

            if ($last !== $child) {
                return false;
            }

            $child = $parent;
        }

        return true;
    }

    private function onlyAllowedFollow(SequenceNode $sequence, NodeInterface $item, bool $endAnchorsMayFollow, bool $emptyMatchesMayFollow): bool
    {
        $key = spl_object_id($sequence).':'.($endAnchorsMayFollow ? 1 : 0).($emptyMatchesMayFollow ? 1 : 0);
        if (!isset($this->followed[$key])) {
            $followed = [];
            $nothingAfter = true;
            for ($index = \count($sequence->children) - 1; $index >= 0; $index--) {
                $next = $sequence->children[$index];
                $followed[spl_object_id($next)] = $nothingAfter;
                $nothingAfter = $nothingAfter && ($next instanceof CommentNode
                    || ($endAnchorsMayFollow && NodePredicates::isEndAnchorNode($next))
                    || ($emptyMatchesMayFollow && self::alwaysMatchesEmptyFirst($next)));
            }
            $this->followed[$key] = $followed;
        }

        return $this->followed[$key][spl_object_id($item)] ?? false;
    }

    /**
     * @return array<int, int>
     */
    private function positionsIn(SequenceNode $sequence): array
    {
        $id = spl_object_id($sequence);
        if (!isset($this->positions[$id])) {
            $this->positions[$id] = [];
            foreach ($sequence->children as $index => $child) {
                $this->positions[$id][spl_object_id($child)] = $index;
            }
        }

        return $this->positions[$id];
    }

    /**
     * Whether the node may match nothing and holds nothing that can fail
     * where it stands: no anchor, lookaround, verb, reference or call.
     */
    private static function alwaysMatchesEmptyFirst(NodeInterface $node): bool
    {
        return NodePredicates::canBeEmpty($node) && !self::holdsATest($node);
    }

    /**
     * Whether the node holds anything but structure, characters and
     * comments: an anchor, an assertion, a lookaround, a verb, a callout, a
     * conditional, a reference or a call can each fail where it stands.
     */
    private static function holdsATest(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode || $node instanceof CharClassNode || $node instanceof CharTypeNode
            || $node instanceof DotNode || $node instanceof CharLiteralNode || $node instanceof UnicodePropNode
            || $node instanceof CommentNode
        ) {
            return false;
        }

        if (!$node instanceof SequenceNode && !$node instanceof AlternationNode && !$node instanceof QuantifierNode
            && !($node instanceof GroupNode && NodePredicates::isTransparentGroup($node->type))
        ) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsATest($child)) {
                return true;
            }
        }

        return false;
    }
}

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

namespace PHPRegex\Transpiler\Target\JavaScript;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Where JavaScript keeps captures and repeats otherwise than PCRE, found
 * once a pattern is printed for it:
 *
 * - a reference to a group that has not matched matches the empty string
 *   in JavaScript, and fails in PCRE: "(a)|b\1" matches "b" in JavaScript
 *   alone, and a reference inside its own group always matches empty;
 * - each repetition of a group starts with the groups it holds unset in
 *   JavaScript, where PCRE keeps what an earlier repetition captured:
 *   "(?:(a)|b)+" on "ab" captures "a" in PCRE, nothing in JavaScript;
 * - JavaScript never takes a repetition that matches empty past the
 *   minimum, where PCRE takes one and stops: "(a?)+" on "a" captures the
 *   empty string in PCRE, "a" in JavaScript, and "(?:a|(?=b)|.)*" matches
 *   "" in "b" in PCRE, where JavaScript backtracks into the repetition for
 *   the dot. The two agree where the empty repetition sets no group and
 *   is tried after every longer one: PCRE then goes on from where
 *   JavaScript does, in the same state, once more.
 *
 * Each case is refused; a pattern that holds none prints as it reads.
 *
 * @internal
 */
final class CaptureSemantics
{
    /**
     * @var array<int, int> each capturing group's number, keyed by the node's object id
     */
    private array $numbers = [];

    /**
     * @var array<int, GroupNode> each capturing group by its number
     */
    private array $groups = [];

    /**
     * @var array<int, true> the groups whose emptiness is being read, so a reference inside its own group ends the reading
     */
    private array $reading = [];

    /**
     * @var array<string, int>
     */
    private array $names = [];

    private ?NodeInterface $divergent = null;

    /**
     * Whether /U or "(?U)" may swap greedy and lazy somewhere in the pattern.
     */
    private bool $ungreedy = false;

    private string $reason = '';

    /**
     * The first construct whose captures JavaScript reads otherwise, with
     * the reason, or null when there is none.
     *
     * @return array{string, NodeInterface}|null
     */
    public static function firstDivergence(RegexNode $ast): ?array
    {
        $check = new self();
        $check->ungreedy = str_contains($ast->flags, 'U') || 1 === LibraryPcre::match('/\\(\\?[\\^a-zA-Z]*U/', (string) $ast->source);
        $check->number($ast->pattern);
        $check->walk($ast->pattern, []);

        return null === $check->divergent ? null : [$check->reason, $check->divergent];
    }

    private function number(NodeInterface $node): void
    {
        if ($node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type)) {
            $this->numbers[spl_object_id($node)] = $number = \count($this->numbers) + 1;
            $this->groups[$number] = $node;
            if (null !== $node->name) {
                // Two groups share a name only under (?J), which is refused.
                $this->names[$node->name] = $number;
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->number($child);
        }
    }

    /**
     * The groups certain to have matched once the node has, given those
     * certain before it. A reference inside its own group finds it not yet
     * matched, as JavaScript does.
     *
     * @param array<int, bool> $set the groups certain to have matched before the node
     *
     * @return array<int, bool>
     */
    private function walk(NodeInterface $node, array $set): array
    {
        if (null !== $this->divergent) {
            return $set;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $set = $this->walk($child, $set);
            }

            return $set;
        }

        if ($node instanceof AlternationNode) {
            $common = null;
            foreach ($node->alternatives as $alternative) {
                $after = $this->walk($alternative, $set);
                $common = null === $common ? $after : array_intersect_key($common, $after);
            }

            return $common ?? $set;
        }

        if ($node instanceof GroupNode) {
            return $this->walkGroup($node, $set);
        }

        if ($node instanceof QuantifierNode) {
            return $this->walkQuantifier($node, $set);
        }

        if ($node instanceof BackrefNode) {
            $number = $this->referenced($node->ref);
            if (null !== $number && true !== ($set[$number] ?? false)) {
                $this->diverge('A backreference to a group that may not have matched cannot be carried into JavaScript: there it matches the empty string, where PCRE fails.', $node);
            }
        }

        return $set;
    }

    /**
     * @param array<int, bool> $set
     *
     * @return array<int, bool>
     */
    private function walkGroup(GroupNode $node, array $set): array
    {
        $after = $this->walk($node->child, $set);
        $number = $this->numbers[spl_object_id($node)] ?? null;
        if (null !== $number) {
            return [$number => true] + $after;
        }

        // A negative assertion holds when its groups have not matched.
        return GroupType::LookaheadNegative === $node->type || GroupType::LookbehindNegative === $node->type ? $set : $after;
    }

    /**
     * @param array<int, bool> $set
     *
     * @return array<int, bool>
     */
    private function walkQuantifier(QuantifierNode $node, array $set): array
    {
        $after = $this->walk($node->node, $set);
        $bounds = $node->bounds;
        if (null === $bounds) {
            return $set;
        }

        $held = $this->capturesIn($node->node);
        $optional = null === $bounds->max || $bounds->max > $bounds->min;
        if ([] !== $held && $optional && $this->setsGroupWhenEmpty($node->node)) {
            $this->diverge('A capturing group in a repetition that may match empty cannot be carried into JavaScript: PCRE takes an empty repetition and the groups it sets, JavaScript never does.', $node);
        }

        if ($optional && $this->matchesEmpty($node->node) && $this->prefersEmpty($node->node)) {
            $this->diverge('A repetition that may match empty before it tries a longer match cannot be carried into JavaScript: PCRE takes the empty repetition and stops, JavaScript backtracks into it for a longer one.', $node);
        }

        if ([] !== array_diff_key($held, $after) && (null === $bounds->max || $bounds->max > 1)) {
            $this->diverge('A capturing group a repetition may leave unset cannot be carried into JavaScript: there each repetition unsets it, where PCRE keeps what it last matched.', $node);
        }

        return $bounds->min > 0 ? $after : $set;
    }

    /**
     * Whether the node may try an empty match before a longer one where it
     * stands: a lazy repetition that may be left out, an alternative that
     * may match empty ahead of one that may not, or an item of a sequence
     * that does either while every other item may match empty. /U makes
     * every greedy repetition lazy. An assertion matches empty whatever it
     * holds, and is tried once.
     */
    private function prefersEmpty(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            $lazy = QuantifierType::Lazy === $node->type || ($this->ungreedy && QuantifierType::Greedy === $node->type);

            return ($lazy && self::mayBeLeftOut($node)) || $this->prefersEmpty($node->node);
        }

        if ($node instanceof AlternationNode) {
            $consumingAfter = false;
            foreach (array_reverse($node->alternatives) as $alternative) {
                if ($this->prefersEmpty($alternative) || ($consumingAfter && $this->matchesEmpty($alternative))) {
                    return true;
                }

                $consumingAfter = $consumingAfter || self::consumes($alternative);
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $index => $child) {
                if ($this->prefersEmpty($child) && $this->allMatchEmpty($node->children, $index)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof GroupNode && self::isLookaround($node)) {
            return false;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->prefersEmpty($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every item of the sequence but the one at $except may match empty.
     *
     * @param list<NodeInterface> $children
     */
    private function allMatchEmpty(array $children, int $except): bool
    {
        foreach ($children as $index => $child) {
            if ($index !== $except && !$this->matchesEmpty($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the node may match empty with a capturing group set: a group
     * that may match empty, or one in a lookahead or a lookbehind that
     * holds. A negative assertion sets none.
     */
    private function setsGroupWhenEmpty(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode) {
            if (GroupType::LookaheadNegative === $node->type || GroupType::LookbehindNegative === $node->type) {
                return false;
            }

            if (isset($this->numbers[spl_object_id($node)]) && $this->matchesEmpty($node->child)) {
                return true;
            }

            return self::isLookaround($node) ? [] !== $this->capturesIn($node->child) : $this->setsGroupWhenEmpty($node->child);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $index => $child) {
                if ($this->setsGroupWhenEmpty($child) && $this->allMatchEmpty($node->children, $index)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof QuantifierNode && 0 === $node->bounds?->max) {
            return false;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->setsGroupWhenEmpty($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the repetition may be taken no time at all: "?", "*", "{0,n}".
     */
    private static function mayBeLeftOut(QuantifierNode $node): bool
    {
        return 0 === $node->bounds?->min;
    }

    /**
     * @return array<int, true>
     */
    private function capturesIn(NodeInterface $node): array
    {
        $held = [];
        $number = $this->numbers[spl_object_id($node)] ?? null;
        if (null !== $number) {
            $held[$number] = true;
        }

        foreach ($node->getChildren() as $child) {
            $held += $this->capturesIn($child);
        }

        return $held;
    }

    /**
     * Whether the node may match the empty string; anything not read as a
     * character, an assertion among them, is taken to. A reference matches
     * what its group matched, and PCRE fails one to a group not set: it
     * may match empty where its group may. One inside its own group, which
     * JavaScript reads as empty, is refused on its own.
     */
    private function matchesEmpty(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof LiteralNode => '' === $node->value,
            $node instanceof SequenceNode => $this->allMatchEmpty($node->children, -1),
            $node instanceof AlternationNode => [] !== array_filter($node->alternatives, $this->matchesEmpty(...)),
            $node instanceof QuantifierNode => self::mayBeLeftOut($node) || $this->matchesEmpty($node->node),
            $node instanceof GroupNode => self::isLookaround($node) || $this->matchesEmpty($node->child),
            $node instanceof BackrefNode => $this->referenceMatchesEmpty($node),
            $node instanceof CharClassNode, $node instanceof CharTypeNode, $node instanceof DotNode, $node instanceof CharLiteralNode,
            $node instanceof UnicodePropNode, $node instanceof ControlCharNode => false,
            default => true,
        };
    }

    private function referenceMatchesEmpty(BackrefNode $node): bool
    {
        $number = $this->referenced($node->ref);
        $group = null === $number ? null : $this->groups[$number] ?? null;
        if (null === $number || null === $group || isset($this->reading[$number])) {
            return true;
        }

        $this->reading[$number] = true;

        try {
            return $this->matchesEmpty($group->child);
        } finally {
            unset($this->reading[$number]);
        }
    }

    /**
     * Whether the node may match a character: anything not read as an
     * assertion or the empty string is taken to.
     */
    private static function consumes(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof LiteralNode => '' !== $node->value,
            $node instanceof SequenceNode => [] !== array_filter($node->children, self::consumes(...)),
            $node instanceof AlternationNode => [] !== array_filter($node->alternatives, self::consumes(...)),
            $node instanceof QuantifierNode => 0 !== $node->bounds?->max && self::consumes($node->node),
            $node instanceof GroupNode => !self::isLookaround($node) && self::consumes($node->child),
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof CommentNode => false,
            default => true,
        };
    }

    private static function isLookaround(GroupNode $node): bool
    {
        return \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true);
    }

    /**
     * The number of the group a reference names, or null for a spelling
     * read elsewhere (a relative reference is refused by the printer).
     */
    private function referenced(string $ref): ?int
    {
        if (1 === LibraryPcre::match('/^\\\\g?\{?(\d+)\}?$/', $ref, $matches)) {
            return (int) $matches[1];
        }

        if (1 === LibraryPcre::match('/^\\\\k[<{\']([A-Za-z0-9_]+)[>}\']$/', $ref, $matches)) {
            return $this->names[$matches[1]] ?? null;
        }

        return null;
    }

    private function diverge(string $reason, NodeInterface $node): void
    {
        $this->divergent ??= $node;
        if ('' === $this->reason) {
            $this->reason = $reason;
        }
    }
}

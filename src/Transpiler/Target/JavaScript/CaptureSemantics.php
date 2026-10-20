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
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
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
 *   the dot.
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
        $check->walk($ast->pattern, [], []);

        return null === $check->divergent ? null : [$check->reason, $check->divergent];
    }

    private function number(NodeInterface $node): void
    {
        if ($node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type)) {
            $this->numbers[spl_object_id($node)] = $number = \count($this->numbers) + 1;
            if (null !== $node->name) {
                $this->names[$node->name] ??= $number;
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->number($child);
        }
    }

    /**
     * The groups certain to have matched once the node has, given those
     * certain before it.
     *
     * @param array<int, true> $set  the groups certain to have matched before the node
     * @param array<int, true> $open the groups the node stands in
     *
     * @return array<int, true>
     */
    private function walk(NodeInterface $node, array $set, array $open): array
    {
        if (null !== $this->divergent) {
            return $set;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $set = $this->walk($child, $set, $open);
            }

            return $set;
        }

        if ($node instanceof AlternationNode) {
            $common = null;
            foreach ($node->alternatives as $alternative) {
                $after = $this->walk($alternative, $set, $open);
                $common = null === $common ? $after : array_intersect_key($common, $after);
            }

            return $common ?? $set;
        }

        if ($node instanceof GroupNode) {
            return $this->walkGroup($node, $set, $open);
        }

        if ($node instanceof QuantifierNode) {
            return $this->walkQuantifier($node, $set, $open);
        }

        if ($node instanceof BackrefNode) {
            $number = $this->referenced($node->ref);
            if (null !== $number && (!isset($set[$number]) || isset($open[$number]))) {
                $this->diverge('A backreference to a group that may not have matched cannot be carried into JavaScript: there it matches the empty string, where PCRE fails.', $node);
            }
        }

        return $set;
    }

    /**
     * @param array<int, true> $set
     * @param array<int, true> $open
     *
     * @return array<int, true>
     */
    private function walkGroup(GroupNode $node, array $set, array $open): array
    {
        $number = $this->numbers[spl_object_id($node)] ?? null;
        if (null !== $number) {
            return $this->walk($node->child, $set, $open + [$number => true]) + [$number => true];
        }

        $after = $this->walk($node->child, $set, $open);

        // A negative assertion holds when its groups have not matched.
        return GroupType::LookaheadNegative === $node->type || GroupType::LookbehindNegative === $node->type ? $set : $after;
    }

    /**
     * @param array<int, true> $set
     * @param array<int, true> $open
     *
     * @return array<int, true>
     */
    private function walkQuantifier(QuantifierNode $node, array $set, array $open): array
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        $after = $this->walk($node->node, $set, $open);
        if (null === $bounds) {
            return $set;
        }

        $held = $this->capturesIn($node->node);
        $optional = null === $bounds->max || $bounds->max > $bounds->min;
        if ([] !== $held && $optional && self::matchesEmpty($node->node)) {
            $this->diverge('A capturing group in a repetition that may match empty cannot be carried into JavaScript: PCRE takes an empty repetition and the groups it sets, JavaScript never does.', $node);
        }

        if ($optional && self::matchesEmpty($node->node) && $this->prefersEmpty($node->node)) {
            $this->diverge('A repetition that may match empty before it tries a longer match cannot be carried into JavaScript: PCRE takes the empty repetition and stops, JavaScript backtracks into it for a longer one.', $node);
        }

        if ([] !== array_diff_key($held, $after) && (null === $bounds->max || $bounds->max > 1)) {
            $this->diverge('A capturing group a repetition may leave unset cannot be carried into JavaScript: there each repetition unsets it, where PCRE keeps what it last matched.', $node);
        }

        return $bounds->min > 0 ? $after : $set;
    }

    /**
     * Whether the node may prefer an empty match over a longer one where
     * it stands: a lazy repetition that may be left out, or an alternative
     * that may match empty ahead of another. /U makes every greedy
     * repetition lazy.
     */
    private function prefersEmpty(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            $lazy = QuantifierType::Lazy === $node->type || ($this->ungreedy && QuantifierType::Greedy === $node->type);

            return ($lazy && 0 === (QuantifierBounds::parse($node->quantifier)->min ?? 0)) || $this->prefersEmpty($node->node);
        }

        if ($node instanceof AlternationNode) {
            foreach (\array_slice($node->alternatives, 0, -1) as $alternative) {
                if (self::matchesEmpty($alternative)) {
                    return true;
                }
            }
        }

        foreach ($node->getChildren() as $child) {
            if ($this->prefersEmpty($child)) {
                return true;
            }
        }

        return false;
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
     * character, an assertion or a reference among them, is taken to.
     */
    private static function matchesEmpty(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof LiteralNode => '' === $node->value,
            $node instanceof SequenceNode => [] === array_filter($node->children, static fn (NodeInterface $child): bool => !self::matchesEmpty($child)),
            $node instanceof AlternationNode => [] !== array_filter($node->alternatives, self::matchesEmpty(...)),
            $node instanceof QuantifierNode => 0 === (QuantifierBounds::parse($node->quantifier)->min ?? 0) || self::matchesEmpty($node->node),
            $node instanceof GroupNode => \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true) || self::matchesEmpty($node->child),
            $node instanceof CharClassNode, $node instanceof CharTypeNode, $node instanceof DotNode, $node instanceof CharLiteralNode,
            $node instanceof UnicodePropNode, $node instanceof ControlCharNode => false,
            default => true,
        };
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

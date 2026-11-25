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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Automata\Solver\RewriteSites;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Where PCRE2 makes a repeat possessive that the pattern does not: a
 * character repeated before a group the engine may skip, which an atomic
 * group or a possessive repeat holds, is made possessive as if the group
 * could not be skipped. "/a+(?:b)?+a/" and "/a+(?>(?:b)?)a/" find nothing
 * in "aa", where "/a+(?:b)?a/" finds "aa", with and without the JIT, from
 * PCRE2 10.34 to 10.49; "(*NO_AUTO_POSSESS)" brings the match back. A repeat
 * the pattern writes as a group, "(?:a)+", is never made possessive, and
 * "b?+" is skipped as it should be: the brackets decide, which the normal
 * form leaves out.
 *
 * @internal
 */
final class AutoPossession
{
    /**
     * The first atomic group or possessive repeat of a group that may match
     * nothing and that a character repeated some number of times precedes,
     * or null. Every possessive repeat of a group from zero counts, "*+"
     * too, which PCRE2 compiles otherwise and reads as written today.
     *
     * @param GroupNode|null $unwrapped a group read as its body, as if it
     *                                  were taken out
     */
    public static function repeatBeforeSkippableGroup(NodeInterface $pattern, ?GroupNode $unwrapped = null): ?NodeInterface
    {
        $firstRepeatEnd = null;
        $skippable = [];
        foreach (self::nodes($pattern, $unwrapped) as $node) {
            if ($node instanceof QuantifierNode && !self::groupLike(self::unwrap($node->node, $unwrapped)) && self::repeatsSomeNumberOfTimes($node)) {
                $firstRepeatEnd = min($firstRepeatEnd ?? $node->getEndPosition(), $node->getEndPosition());
            }

            if (self::skippable($node, $unwrapped)) {
                $skippable[] = $node;
            }
        }

        foreach ($skippable as $node) {
            if (null !== $firstRepeatEnd && $firstRepeatEnd <= $node->getStartPosition()) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The repeats of one character PCRE2 may make possessive, by the offset
     * each starts at: those that end before an atomic group or a possessive
     * repeat of a group that may match nothing. Such a repeat runs as
     * written where its possessive spelling gives nothing back.
     *
     * @return array<int, true>
     */
    public static function repeatsBeforeSkippableGroups(NodeInterface $pattern): array
    {
        $lastSkippableStart = null;
        $repeats = [];
        foreach (self::nodes($pattern) as $node) {
            if ($node instanceof QuantifierNode && !self::groupLike($node->node) && self::repeatsSomeNumberOfTimes($node)) {
                $repeats[$node->getStartPosition()] = $node->getEndPosition();
            }

            if (self::skippable($node)) {
                $lastSkippableStart = max($lastSkippableStart ?? $node->getStartPosition(), $node->getStartPosition());
            }
        }

        if (null === $lastSkippableStart) {
            return [];
        }

        return array_fill_keys(array_keys(array_filter($repeats, static fn (int $end): bool => $end <= $lastSkippableStart)), true);
    }

    /**
     * What decides which repeats PCRE2 makes possessive and the normal form
     * does not say: whether each repeat is written as a group, and the
     * brackets of each atomic group or possessive repeat of a group that may
     * match nothing. Empty for a pattern without such a group, where no
     * repeat is made possessive past what it can give back, unless the
     * pattern is spliced into a larger one, which may hold the group: there
     * "(?:a)+" and "a+" stand apart before a "(?:b)?+" of the rest.
     *
     * @return list<string> whether each repeat is written as a group, one
     *                      digit each, then the brackets of each such group
     */
    public static function shape(NodeInterface $pattern, bool $spliced): array
    {
        return self::shapeOf($pattern, $spliced, null);
    }

    /**
     * Whether taking the group out, its body standing where it stood, may
     * change which repeats PCRE2 makes possessive: a character repeated
     * before an atomic group or a possessive repeat of a group that may
     * match nothing stands in the pattern with or without it, and the
     * brackets that decide it differ (see shapeWithout()). "(?:b)" in
     * "/a+(?:b)?+a/" and "(?:)" in "/a+(?>(?:)|b)a/" stay; "(?>)" in
     * "/a(?>)b/", with no repeat before it, can go.
     */
    public static function groupDecides(NodeInterface $pattern, GroupNode $group): bool
    {
        return (null !== self::repeatBeforeSkippableGroup($pattern) || null !== self::repeatBeforeSkippableGroup($pattern, $group))
            && self::shape($pattern, false) !== self::shapeWithout($pattern, $group);
    }

    /**
     * The shape (see shape()) of the pattern with the group taken out, its
     * body standing where it stood: where it differs from the shape of the
     * pattern, the group changes which repeats PCRE2 makes possessive.
     * "(?:b)" in "/a+(?:b)?+a/", or "(?:a)" in "/(?:a)+(?:b)?+a/", cannot go.
     *
     * @return list<string>
     */
    public static function shapeWithout(NodeInterface $pattern, GroupNode $group): array
    {
        return self::shapeOf($pattern, false, $group);
    }

    /**
     * @param GroupNode|null $unwrapped a group read as its body
     *
     * @return list<string>
     */
    private static function shapeOf(NodeInterface $pattern, bool $spliced, ?GroupNode $unwrapped): array
    {
        $repeats = '';
        $skippable = [];
        foreach (self::nodes($pattern, $unwrapped) as $node) {
            if ($node instanceof QuantifierNode && (QuantifierType::Possessive === $node->type || self::repeatsSomeNumberOfTimes($node))) {
                $repeats .= self::groupLike(self::unwrap($node->node, $unwrapped)) ? '1' : '0';
            }

            if (self::skippable($node, $unwrapped)) {
                $skippable[] = self::brackets($node, $unwrapped);
            }
        }

        return $spliced || [] !== $skippable ? [$repeats, ...$skippable] : [];
    }

    /**
     * The node, or the body of the group read as its body.
     */
    private static function unwrap(NodeInterface $node, ?GroupNode $unwrapped): NodeInterface
    {
        return $node === $unwrapped ? $unwrapped->child : $node;
    }

    /**
     * Each node of the tree, a node before its children, in the order the
     * pattern writes them; the group read as its body stands for nothing.
     *
     * @return list<NodeInterface>
     */
    private static function nodes(NodeInterface $pattern, ?GroupNode $unwrapped = null): array
    {
        $nodes = [];
        $pending = [$pattern];
        while (null !== $node = array_pop($pending)) {
            if ($node !== $unwrapped) {
                $nodes[] = $node;
            }
            array_push($pending, ...array_reverse($node->getChildren()));
        }

        return $nodes;
    }

    /**
     * An atomic group, or a possessive repeat of a group, that may match
     * nothing: the engine reaches its end through an iteration it skips or
     * a branch that is empty.
     */
    private static function skippable(NodeInterface $node, ?GroupNode $unwrapped = null): bool
    {
        if ($node instanceof QuantifierNode) {
            $operand = self::unwrap($node->node, $unwrapped);

            return QuantifierType::Possessive === $node->type && self::groupLike($operand)
                && (null === $node->bounds || 0 === $node->bounds->min || self::mayMatchNothing($operand, $unwrapped));
        }

        return $node instanceof GroupNode && GroupType::Atomic === $node->type && self::mayMatchNothing($node->child, $unwrapped);
    }

    /**
     * Whether the node may match the empty string; true for anything not
     * read here.
     */
    private static function mayMatchNothing(NodeInterface $node, ?GroupNode $unwrapped = null): bool
    {
        $node = self::unwrap($node, $unwrapped);
        $empty = static fn (NodeInterface $child): bool => self::mayMatchNothing($child, $unwrapped);

        return match (true) {
            $node instanceof LiteralNode => '' === $node->value,
            self::oneCharacter($node) => false,
            $node instanceof SequenceNode => [] === array_filter($node->children, static fn (NodeInterface $child): bool => !$empty($child)),
            $node instanceof AlternationNode => [] !== array_filter($node->alternatives, $empty),
            $node instanceof GroupNode => !\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags], true) || $empty($node->child),
            $node instanceof QuantifierNode => null === $node->bounds || 0 === $node->bounds->min || $empty($node->node),
            default => true,
        };
    }

    /**
     * Whether the repeat runs a number of times between two bounds, which a
     * possessive spelling would fix.
     */
    private static function repeatsSomeNumberOfTimes(QuantifierNode $node): bool
    {
        return null === $node->bounds || $node->bounds->min !== $node->bounds->max;
    }

    /**
     * Whether PCRE2 compiles the node between brackets: anything but one
     * character, or a reference to a group.
     */
    private static function groupLike(NodeInterface $node): bool
    {
        return !self::oneCharacter($node) && !$node instanceof BackrefNode;
    }

    private static function oneCharacter(NodeInterface $node): bool
    {
        return ($node instanceof LiteralNode && '' !== $node->value)
            || $node instanceof CharLiteralNode
            || $node instanceof ControlCharNode
            || $node instanceof CharClassNode
            || $node instanceof CharTypeNode
            || $node instanceof DotNode
            || $node instanceof UnicodePropNode
            || $node instanceof ExtendedCharClassNode;
    }

    /**
     * The brackets of a node, each character read alike: "(?>[0-9]*)" and
     * "(?>\d*)" have the same, "(?>(?:b)?)" and "(?>b?)" do not.
     */
    private static function brackets(NodeInterface $node, ?GroupNode $unwrapped = null): string
    {
        $node = self::unwrap($node, $unwrapped);
        $of = static fn (NodeInterface $child): string => self::brackets($child, $unwrapped);

        return match (true) {
            self::oneCharacter($node) => 'c',
            $node instanceof LiteralNode => 'e',
            $node instanceof QuantifierNode => \sprintf('q{%s,%s,%s}(%s)', $node->bounds?->min, $node->bounds?->max, $node->type->name, $of($node->node)),
            $node instanceof GroupNode => \sprintf('g{%s}(%s)', $node->type->name, $of($node->child)),
            $node instanceof SequenceNode => 's('.implode(',', array_map($of, $node->children)).')',
            $node instanceof AlternationNode => 'a('.implode('|', array_map($of, $node->alternatives)).')',
            default => serialize(RewriteSites::shape($node)),
        };
    }
}

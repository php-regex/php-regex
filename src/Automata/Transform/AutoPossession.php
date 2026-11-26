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
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Hir\CharSet;
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
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreTarget;

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
     * Whether each pattern asked about holds a repeat PCRE2 may make
     * possessive as if the byte 0xFF were no word character, each group a
     * repeat reads taken out, whatever the tables, kept as long as its tree
     * is: a rule asks at each group it would report, and reading it walks
     * the whole pattern.
     *
     * @var \WeakMap<NodeInterface, bool>|null
     */
    private static ?\WeakMap $besideLastByte = null;

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
            if ($node instanceof QuantifierNode && !self::groupLike(null !== $unwrapped && $node->node === $unwrapped ? $unwrapped->child : $node->node) && self::repeatsSomeNumberOfTimes($node)) {
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
                $lastSkippableStart = $node->getStartPosition();
            }
        }

        if (null === $lastSkippableStart) {
            return [];
        }

        return array_fill_keys(array_keys(array_filter($repeats, static fn (int $end): bool => $end <= $lastSkippableStart)), true);
    }

    /**
     * The repeat PCRE2 may make possessive as if the byte 0xFF were no word
     * character, or null. Without "u", PCRE2 makes a repeat of that byte
     * possessive before "\w", and a repeat of "\w" before that byte, lazy
     * ones too, whatever the tables say: under fr_FR.ISO8859-1, where "\w"
     * takes 0xFF, "/\xFF+\w/" finds nothing in "\xFF\xFF" and "/\w+\xFF/"
     * nothing in "a\xFF\xFF", where "/(?:\xFF)+\w/", "/\xFF+[\w]/" and
     * "/[\w]+\xFF/" find them, with and without the JIT, in PCRE2 10.49.
     * None is found where the target names a locale whose tables leave 0xFF
     * out of "\w"; one that names no locale but C may run under any.
     *
     * Read wider than the engine reads it: a repeat, some number of times
     * and not possessive, of a character or a class that names the byte, or
     * U+0178, which folds to it under UTF, with "\w" outside a class
     * anywhere after it, or of "\w" with such a character or class anywhere
     * after it. The repeat named is the nearest before what PCRE2 reads it
     * against.
     */
    public static function repeatMadePossessiveBesideLastByte(RegexNode $regex, PcreTarget $target): ?QuantifierNode
    {
        return self::mayReadLastByteAsNoWord($regex->flags, $target->locale) ? self::repeatBesideLastByte($regex->pattern) : null;
    }

    /**
     * Whether taking a group out of the pattern, its body standing where it
     * stood, may change which repeats PCRE2 makes possessive as if the byte
     * 0xFF were no word character: the pattern holds such a repeat (see
     * repeatMadePossessiveBesideLastByte()), each group a repeat reads taken
     * out, and the brackets of any group may decide it. The engine never
     * makes a repeat written as a group possessive, and stops a lazy repeat
     * at the end of a group: under fr_FR.ISO8859-1 "/(?:\xFF)+\w/" finds
     * "\xFF\xFF" where "/\xFF+\w/" finds nothing, and so does
     * "/\xFF+?(?:)\w/" where "/\xFF+?\w/" does not, with and without the
     * JIT, in PCRE2 10.49. Read wider than for one group: "(?:a)" in
     * "/(?:\xFF)+\w(?:a)/" is kept as well.
     *
     * @param string      $flags  the flags of the pattern
     * @param string|null $locale the LC_CTYPE locale it runs under, "C" or
     *                            null where any may be
     */
    public static function bracketsDecideBesideLastByte(NodeInterface $pattern, string $flags, ?string $locale): bool
    {
        self::$besideLastByte ??= new \WeakMap();

        return self::mayReadLastByteAsNoWord($flags, $locale ?? CharacterTables::BUILT_IN)
            && (self::$besideLastByte[$pattern] ??= null !== self::repeatBesideLastByte($pattern, true));
    }

    /**
     * Whether PCRE2 may read the pattern, spliced into a larger one, against
     * what stands around it as if the byte 0xFF were no word character: it
     * holds "\w" outside a class, or a node that may read that byte, which
     * a repeat around it may be made possessive before, or which, repeated,
     * may be made possessive before what follows. Read alone, "[\w]+" and
     * "\w+" run alike, yet under fr_FR.ISO8859-1
     * "/(?P<a>[\w]+)(?P<b>\xFF)/" finds "a\xFF\xFF" where
     * "/(?P<a>\w+)(?P<b>\xFF)/" finds nothing, with and without the JIT, in
     * PCRE2 10.49. None is found under "u", or where the target names a
     * locale whose tables leave 0xFF out of "\w".
     */
    public static function meetsLastByteWhereSpliced(RegexNode $regex, PcreTarget $target): bool
    {
        if (!self::mayReadLastByteAsNoWord($regex->flags, $target->locale)) {
            return false;
        }

        $pending = [$regex->pattern];
        while (null !== $node = array_pop($pending)) {
            if (self::word($node) || self::lastByte($node)) {
                return true;
            }

            // PCRE2 reads a class whole: "[\w]" is no "\w" to it.
            if (!self::isClass($node)) {
                array_push($pending, ...$node->getChildren());
            }
        }

        return false;
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
        return self::shapeOf($pattern, $spliced);
    }

    /**
     * Whether taking the group out, its body standing where it stood, may
     * change which repeats PCRE2 makes possessive: a character repeated
     * before an atomic group or a possessive repeat of a group that may
     * match nothing stands in the pattern with or without it, and the
     * brackets that decide it differ: the shape (see shape()) of the
     * pattern with the group taken out, its body standing where it stood,
     * is not the pattern's. "(?:b)" in "/a+(?:b)?+a/", "(?:a)" in
     * "/(?:a)+(?:b)?+a/" and "(?:)" in "/a+(?>(?:)|b)a/" stay; "(?>)" in
     * "/a(?>)b/", with no repeat before it, can go.
     */
    public static function groupDecides(NodeInterface $pattern, GroupNode $group): bool
    {
        return (null !== self::repeatBeforeSkippableGroup($pattern) || null !== self::repeatBeforeSkippableGroup($pattern, $group))
            && self::shapeOf($pattern) !== self::shapeOf($pattern, unwrapped: $group);
    }

    /**
     * groupDecides() read where the engine reads: PCRE2 judges a repeated
     * character against what stands after it, no further than the first
     * item that must take a character, and a group it never reaches on the
     * way decides nothing. The group stays where a repeat stands beside a
     * group the engine may skip (see beside()) and the brackets of the
     * group are read for that repeat: they are the repeat's own, as "(?:a)"
     * in "/(?:a)+(?:b)?+a/", or they stand where the engine reads from the
     * repeat, as "(?:b)" in "/a+(?:b)?+a/" and in "/a+(?:b)?(?>(?:c)?)a/".
     * It can go once a character that must be there parts it from the
     * repeat, or the repeat from the group the engine may skip: "(?:b)" in
     * "/a+x(?:b)?+a/", "(?:a)" in "/(?:a)+x(?:b)?+a/" and "(?:\\)" in
     * "/(?:\\)?define [a-z]++ (?:,x)*+ /" match alike with and without
     * their brackets, with and without the JIT, from PCRE2 10.39 to 10.49.
     *
     * A pattern that holds a call is read whole, as groupDecides() reads
     * it: PCRE2's JIT misses matches of a repeat made possessive inside a
     * group a call runs, "/(a+b)c(?1)d/" none in "abcabcabd" where
     * "/((?:a)+b)c(?1)d/" finds "abcabd".
     */
    public static function groupDecidesBesideARepeat(NodeInterface $pattern, GroupNode $group): bool
    {
        $ancestors = self::ancestors($pattern);
        $above = $ancestors[$group];
        $own = end($above);
        // The repeat taking the group out makes: a character where a group
        // was repeated.
        $made = $own instanceof QuantifierNode && !self::groupLike($group->child) ? $own : null;
        $repeats = [];
        $skippable = [];
        foreach (self::nodes($pattern) as $node) {
            if ($node instanceof SubroutineNode) {
                return self::groupDecides($pattern, $group);
            }

            if ($node instanceof QuantifierNode && ($node === $made || !self::groupLike($node->node)) && self::repeatsSomeNumberOfTimes($node)) {
                $repeats[] = $node;
            }

            if (self::skippable($node)) {
                $skippable[] = $node;
            }
        }

        foreach ($skippable as $skipped) {
            foreach ($repeats as $repeat) {
                if (self::beside($repeat, $skipped, $ancestors) && ($repeat === $made || self::beside($repeat, $group, $ancestors))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The first repeat PCRE2 may make possessive where the pattern lets it
     * give back, with the group it is read against, or null: a repeat of
     * one character, not written possessive, that stands beside (see
     * beside()) an atomic group or a possessive repeat of a group that may
     * match nothing. An engine that runs the pattern as written finds "aa"
     * with "/a+(?:b)?+a/", as PCRE2 does under "(*NO_AUTO_POSSESS)"; PCRE2
     * finds nothing. It leaves a repeat that runs lazy as written,
     * "/a+?(?:b)?+a/" finding "aa", from PCRE2 10.39 to 10.49: only the
     * repeats written as the caller says the greedy ones are count, "a+?"
     * under "U".
     *
     * @param list<QuantifierType> $greedy how a repeat that runs greedy may be written
     *
     * @return array{QuantifierNode, NodeInterface}|null the repeat, then the group
     */
    public static function repeatBesideSkippableGroup(NodeInterface $pattern, array $greedy): ?array
    {
        $ancestors = null;
        $repeats = [];
        $skippable = [];
        foreach (self::nodes($pattern) as $node) {
            if ($node instanceof QuantifierNode && !self::groupLike($node->node) && self::repeatsSomeNumberOfTimes($node) && \in_array($node->type, $greedy, true)) {
                $repeats[] = $node;
            }

            if (self::skippable($node)) {
                $skippable[] = $node;
            }
        }

        foreach ($repeats as $repeat) {
            foreach ($skippable as $skipped) {
                // Read once a repeat and a group are both there, as few
                // patterns hold the two.
                if (self::beside($repeat, $skipped, $ancestors ??= self::ancestors($pattern))) {
                    return [$repeat, $skipped];
                }
            }
        }

        return null;
    }

    /**
     * Without "u", where the tables of the locale may hold 0xFF in "\w":
     * those of one that names no locale but C may.
     */
    private static function mayReadLastByteAsNoWord(string $flags, string $locale): bool
    {
        return !str_contains($flags, 'u') && (CharacterTables::BUILT_IN === $locale || CharSet::fromRanges(CharacterTables::forLocale($locale)->bytesOf('w'))->contains(0xFF));
    }

    /**
     * The repeat repeatMadePossessiveBesideLastByte() names, whatever the
     * tables.
     *
     * @param bool $groupsRead whether a repeat of a group reads its body, as
     *                         if each such group were taken out
     */
    private static function repeatBesideLastByte(NodeInterface $pattern, bool $groupsRead = false): ?QuantifierNode
    {
        $beforeWord = null;
        $beforeLastByte = null;
        $pending = [$pattern];
        while (null !== $node = array_pop($pending)) {
            if (null !== $beforeWord && self::word($node)) {
                return $beforeWord;
            }

            if (null !== $beforeLastByte && self::lastByte($node)) {
                return $beforeLastByte;
            }

            if ($node instanceof QuantifierNode && QuantifierType::Possessive !== $node->type && self::repeatsSomeNumberOfTimes($node)) {
                $operand = $groupsRead && $node->node instanceof GroupNode ? $node->node->child : $node->node;
                if (self::lastByte($operand)) {
                    $beforeWord = $node;
                }

                if (self::word($operand)) {
                    $beforeLastByte = $node;
                }
            }

            // PCRE2 reads a class whole: "[\w]" is no "\w" to it.
            if (!self::isClass($node)) {
                array_push($pending, ...array_reverse($node->getChildren()));
            }
        }

        return null;
    }

    /**
     * @param GroupNode|null $unwrapped a group read as its body
     *
     * @return list<string>
     */
    private static function shapeOf(NodeInterface $pattern, bool $spliced = false, ?GroupNode $unwrapped = null): array
    {
        $repeats = '';
        $skippable = [];
        foreach (self::nodes($pattern, $unwrapped) as $node) {
            if ($node instanceof QuantifierNode && (QuantifierType::Possessive === $node->type || self::repeatsSomeNumberOfTimes($node))) {
                $repeats .= self::groupLike(null !== $unwrapped && $node->node === $unwrapped ? $unwrapped->child : $node->node) ? '1' : '0';
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
     * The nodes above each node of the tree, the pattern first.
     *
     * @return \WeakMap<NodeInterface, list<NodeInterface>>
     */
    private static function ancestors(NodeInterface $pattern): \WeakMap
    {
        /** @var \WeakMap<NodeInterface, list<NodeInterface>> $ancestors */
        $ancestors = new \WeakMap();
        $ancestors[$pattern] = [];
        $pending = [$pattern];
        while (null !== $node = array_pop($pending)) {
            $above = [...$ancestors[$node], $node];
            foreach ($node->getChildren() as $child) {
                $ancestors[$child] = $above;
                $pending[] = $child;
            }
        }

        return $ancestors;
    }

    /**
     * Whether PCRE2, judging the repeat against what stands after it, may
     * read as far as where the node starts: it reads on through each item
     * that may match nothing, into each group and out of it, and stops at
     * the first item that must take a character. "(?:b)?+" stands beside
     * "a+" in "/a+c?(?:b)?+a/", which finds nothing in "aa", and not in
     * "/a+x(?:b)?+a/". A group repeated more than once is compiled once
     * per round, so that a repeat stands before what opens the next round:
     * "/(?:(?>(?:b)?)a+){2}/" finds nothing in "aa", where
     * "/(?:(?>b?)a+){2}/" finds it, with and without the JIT, from PCRE2
     * 10.39 to 10.49.
     *
     * Read wider than the engine reads: whatever may match nothing is read
     * through, and every repeat of a group that may run twice is read
     * again, "(?:...)+" too, which PCRE2 compiles once.
     *
     * @param \WeakMap<NodeInterface, list<NodeInterface>> $ancestors
     */
    private static function beside(QuantifierNode $repeat, NodeInterface $node, \WeakMap $ancestors): bool
    {
        $lineage = [...$ancestors[$node], $node];
        $from = $repeat;
        foreach (array_reverse($ancestors[$repeat]) as $parent) {
            if ($parent instanceof SequenceNode) {
                $passed = false;
                foreach ($parent->children as $next) {
                    if ($passed && \in_array($next, $lineage, true)) {
                        return self::readFromItsStart($next, $node, $ancestors);
                    }

                    if ($passed && !self::mayMatchNothing($next)) {
                        return false;
                    }

                    $passed = $passed || $next === $from;
                }
            }

            if ($parent instanceof QuantifierNode && self::runsMoreThanOnce($parent) && \in_array($parent->node, $lineage, true)) {
                return self::readFromItsStart($parent->node, $node, $ancestors);
            }

            $from = $parent;
        }

        return false;
    }

    /**
     * Whether PCRE2, reading the part from its start, may read as far as
     * where the node in it starts: nothing before the node, in the part,
     * must take a character.
     *
     * @param \WeakMap<NodeInterface, list<NodeInterface>> $ancestors
     */
    private static function readFromItsStart(NodeInterface $part, NodeInterface $node, \WeakMap $ancestors): bool
    {
        $inner = $node;
        foreach (array_reverse($ancestors[$node]) as $parent) {
            if ($inner === $part) {
                break;
            }

            if ($parent instanceof SequenceNode) {
                foreach ($parent->children as $before) {
                    if ($before === $inner) {
                        break;
                    }

                    if (!self::mayMatchNothing($before)) {
                        return false;
                    }
                }
            }

            $inner = $parent;
        }

        return true;
    }

    /**
     * Whether the quantifier may run its operand twice or more: any but
     * "?" and a count of one, and one whose count is not read.
     */
    private static function runsMoreThanOnce(QuantifierNode $node): bool
    {
        return null === $node->bounds || 1 !== $node->bounds->max;
    }

    /**
     * An atomic group, or a possessive repeat of a group, that may match
     * nothing: the engine reaches its end through an iteration it skips or
     * a branch that is empty.
     */
    private static function skippable(NodeInterface $node, ?GroupNode $unwrapped = null): bool
    {
        if ($node instanceof QuantifierNode) {
            if (QuantifierType::Possessive !== $node->type) {
                return false;
            }

            $operand = self::unwrap($node->node, $unwrapped);

            return self::groupLike($operand)
                && (null === $node->bounds || 0 === $node->bounds->min || self::mayMatchNothing($operand, $unwrapped));
        }

        return $node instanceof GroupNode && GroupType::Atomic === $node->type && self::mayMatchNothing($node->child, $unwrapped);
    }

    /**
     * Whether the node may match the empty string; true for anything not
     * read here, a sequence whose parts each may too.
     */
    private static function mayMatchNothing(NodeInterface $node, ?GroupNode $unwrapped = null): bool
    {
        $node = self::unwrap($node, $unwrapped);

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!self::mayMatchNothing($child, $unwrapped)) {
                    return false;
                }
            }
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (self::mayMatchNothing($alternative, $unwrapped)) {
                    return true;
                }
            }

            return false;
        }

        return match (true) {
            $node instanceof LiteralNode => '' === $node->value,
            self::oneCharacter($node) => false,
            $node instanceof GroupNode => !\in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags], true) || self::mayMatchNothing($node->child, $unwrapped),
            $node instanceof QuantifierNode => null === $node->bounds || 0 === $node->bounds->min || self::mayMatchNothing($node->node, $unwrapped),
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

    /**
     * Whether the node is "\w" outside a class, which PCRE2 reads against a
     * repeated byte by its tables, but for 0xFF.
     */
    private static function word(NodeInterface $node): bool
    {
        return $node instanceof CharTypeNode && 'w' === $node->value;
    }

    /**
     * Whether the node may read the byte 0xFF as one character: the byte,
     * U+00FF or U+0178 written out or as an escape, or a class that names
     * one of them, which PCRE2 may compile as that character alone.
     */
    private static function lastByte(NodeInterface $node): bool
    {
        return [] !== array_filter(self::isClass($node) ? self::nodes($node) : [$node], static fn (NodeInterface $member): bool => ($member instanceof CharLiteralNode && (0xFF === $member->codePoint || 0x178 === $member->codePoint))
            || ($member instanceof LiteralNode && (str_contains($member->value, "\xFF") || str_contains($member->value, "\u{FF}") || str_contains($member->value, "\u{178}"))));
    }

    private static function isClass(NodeInterface $node): bool
    {
        return $node instanceof CharClassNode || $node instanceof ExtendedCharClassNode;
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

        if ($node instanceof SequenceNode || $node instanceof AlternationNode) {
            $parts = [];
            foreach ($node instanceof SequenceNode ? $node->children : $node->alternatives as $child) {
                $parts[] = self::brackets($child, $unwrapped);
            }

            return $node instanceof SequenceNode ? \sprintf('s(%s)', implode(',', $parts)) : \sprintf('a(%s)', implode('|', $parts));
        }

        return match (true) {
            self::oneCharacter($node) => 'c',
            $node instanceof QuantifierNode => \sprintf('q%s%s(%s)', serialize($node->bounds), $node->type->name, self::brackets($node->node, $unwrapped)),
            $node instanceof GroupNode => \sprintf('g%s(%s)', $node->type->name, self::brackets($node->child, $unwrapped)),
            default => serialize(RewriteSites::shape($node)),
        };
    }
}

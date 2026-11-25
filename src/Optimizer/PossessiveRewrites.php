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

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * The possessive repeats of a pattern for Rewriter: a greedy quantifier
 * made possessive where nothing after it can start with a character it
 * gives back, "a+b" written "a++b", and the group a rewrite dropped from a
 * repeat put back where PCRE2 would make the bare repeat possessive behind
 * its back.
 *
 * It shares the rewriter's RewriteState, and reads the items the rewriter
 * has already rewritten. It remembers the repeats whose group the rewriter
 * dropped, for as long as the rewriter lives, what follows the item the
 * rewriter is in, for as long as it is in it, and what follows each call of
 * the pattern visited whole and the character sets in force at each node,
 * read once a repetition needs them.
 *
 * @internal
 */
final class PossessiveRewrites
{
    /**
     * The groups that look around, which read nothing where they stand.
     */
    private const LOOKAROUNDS = [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring];

    /**
     * The limits a pattern may set on what PCRE2 spends on a match, beside
     * "(*LIMIT_MATCH=n)", by the name a verb gives them.
     */
    private const LIMITS = ['LIMIT_DEPTH', 'LIMIT_RECURSION', 'LIMIT_HEAP'];

    /**
     * Whether the pattern visited sets a limit on what PCRE2 spends on a
     * match, which a possessive group may spend more of.
     */
    private bool $limited = false;

    /**
     * The repeats whose group was dropped, "(?:a)+" written "a+".
     *
     * @var \WeakMap<QuantifierNode, true>
     */
    private \WeakMap $ungroupedRepeats;

    /**
     * What follows each item the rewriter is in, the innermost last: the
     * items of its sequence from the index given, a repeat that may run
     * again, whose body then follows its own end, a capturing group, which
     * a call may run elsewhere, a DEFINE, which runs nothing where it
     * stands, or null at the end of an atomic group or a lookaround but a
     * scan substring or a non-atomic lookaround, which nothing after it
     * backtracks into. What follows any other group is what follows the
     * group. Where a call stands in a script run, false stands for it: the
     * check of the run reads what the call matched.
     *
     * @var list<array{list<NodeInterface>, int}|QuantifierNode|GroupNode|DefineNode|false|null>
     */
    private array $after = [];

    /**
     * The pattern visited whole, whose calls are read, or null.
     */
    private ?RegexNode $pattern = null;

    /**
     * What follows each call of the pattern visited, as $after holds it
     * where the call stands, by the object id of the group the call runs,
     * of the pattern for "(?R)": null until a repetition needs it.
     *
     * @var array<int, list<list<array{list<NodeInterface>, int}|QuantifierNode|GroupNode|DefineNode|false|null>>>|null
     */
    private ?array $calls = null;

    /**
     * The character sets in force at each node of the pattern visited
     * whole, and at each item of the sequences possessify() read, under
     * the flags an option setting changed there: null until a repetition
     * needs them.
     *
     * @var \SplObjectStorage<NodeInterface, CharSetAnalyzer>|null
     */
    private ?\SplObjectStorage $inForce = null;

    public function __construct(private readonly RewriteState $state, private readonly RepeatRewrites $repeats)
    {
        $this->ungroupedRepeats = new \WeakMap();
    }

    /**
     * Whether the pattern keeps every repeat greedy: an option setting
     * names "U", under which a plain quantifier is lazy, or a lookaround is
     * not atomic, past whose end the engine reads what follows from where
     * the lookaround starts, not from where the repetition ends.
     */
    public static function keepsRepeatsGreedy(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && false !== strpbrk((string) $node->flags, 'U*')) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::keepsRepeatsGreedy($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads the pattern about to be rewritten, and forgets the calls and
     * the character sets of the one before.
     */
    public function start(RegexNode $node): void
    {
        $this->pattern = $node;
        $this->calls = null;
        $this->inForce = null;
        $this->limited = self::setsLimit($node->pattern);
    }

    /**
     * Records a repeat whose group the rewrite dropped, "(?:a)+" written
     * "a+".
     */
    public function ungrouped(QuantifierNode $node): void
    {
        $this->ungroupedRepeats[$node] = true;
    }

    /**
     * Records the item the rewriter enters in a sequence: the items from
     * $from on follow it.
     *
     * @param list<NodeInterface> $items
     */
    public function enter(array $items, int $from): void
    {
        $this->after[] = [$items, $from];
    }

    /**
     * Forgets the item the rewriter leaves in a sequence.
     */
    public function leave(): void
    {
        array_pop($this->after);
    }

    /**
     * Records the group the rewriter enters, when nothing after it
     * backtracks into it, or when a call may run it elsewhere.
     */
    public function enterGroup(GroupNode $node): void
    {
        array_push($this->after, ...self::frames($node));
    }

    /**
     * Forgets the group the rewriter leaves.
     */
    public function leaveGroup(GroupNode $node): void
    {
        $this->leaveNode($node);
    }

    /**
     * Records the repeat the rewriter enters, when its body may run again
     * after a run: "(?:a+b?){2}c" gives an "a" back to its second run on
     * "aac", and "(b+|ba)*c" a "b" to a run of "ba" on "bbac".
     */
    public function enterRepeat(QuantifierNode $node): void
    {
        array_push($this->after, ...self::frames($node));
    }

    /**
     * Forgets the repeat the rewriter leaves.
     */
    public function leaveRepeat(QuantifierNode $node): void
    {
        $this->leaveNode($node);
    }

    /**
     * Records the DEFINE the rewriter enters, whose groups run where a call
     * runs them only.
     */
    public function enterDefine(DefineNode $node): void
    {
        array_push($this->after, ...self::frames($node));
    }

    /**
     * Forgets the DEFINE the rewriter leaves.
     */
    public function leaveDefine(DefineNode $node): void
    {
        $this->leaveNode($node);
    }

    /**
     * The items of a sequence with each greedy repeat made possessive where
     * what follows it can never start with a character it gives back: the
     * items after it, then, at the end of the sequence or while they may
     * all match nothing, what follows the sequence.
     *
     * @param list<NodeInterface> $children the items of the sequence as rewritten
     * @param SequenceNode        $sequence the sequence as the pattern writes it
     *
     * @return list<NodeInterface>
     */
    public function possessify(array $children, SequenceNode $sequence): array
    {
        // An option setting changes the flags of the items after it: each
        // item as rewritten reads them where it stands.
        $this->inForce()->addAll($this->analyzerAt($sequence)->inForce(new SequenceNode($children, $sequence->startPosition, $sequence->endPosition)));

        foreach ($children as $i => $current) {
            if ($current instanceof QuantifierNode && $this->givesNothingBack($current, \array_slice($children, $i + 1), $this->analyzerAt($current))) {
                $children[$i] = $this->possessive($current);
            }
        }

        return $children;
    }

    /**
     * The repeat as rewritten, made possessive where what follows it can
     * never start with a character it gives back, when no sequence holds
     * it: what follows the group, the alternation, the conditional, the
     * DEFINE or the pattern it stands alone in follows it. The sequence
     * that holds a repeat reads it with the items after it, in
     * possessify().
     *
     * @param QuantifierNode $node      the repeat as the pattern writes it
     * @param QuantifierNode $rewritten the repeat as rewritten
     */
    public function possessifyAlone(QuantifierNode $node, QuantifierNode $rewritten): QuantifierNode
    {
        $frame = end($this->after);
        if (\is_array($frame) && $frame[0][$frame[1] - 1] === $node) {
            return $rewritten;
        }

        return $this->givesNothingBack($rewritten, [], $this->analyzerAt($node)) ? $this->possessive($rewritten) : $rewritten;
    }

    /**
     * PCRE2 makes a character repeated right before a group repeated
     * possessively from zero, or an atomic group that can match empty,
     * possessive, as if the group could not be skipped: "\.a+(?:-b)?+a"
     * misses ".aaaa", which "\.(?:a)+(?:-b)?+a" matches. A repeat whose
     * group the rewrite dropped gets it back there.
     *
     * @param list<NodeInterface> $children
     *
     * @return list<NodeInterface>
     */
    public function regroupBeforeOptionalPossessiveGroups(array $children): array
    {
        foreach ($children as $i => $child) {
            $next = $children[$i + 1] ?? null;
            if ($child instanceof QuantifierNode && isset($this->ungroupedRepeats[$child]) && $this->skippableAtomicGroup($next)) {
                $children[$i] = new QuantifierNode(
                    new GroupNode($child->node, GroupType::NonCapturing, null, null, $child->node->getStartPosition(), $child->node->getEndPosition()),
                    $child->quantifier,
                    $child->type,
                    $child->startPosition,
                    $child->endPosition,
                );
            }
        }

        return $children;
    }

    /**
     * Whether the pattern sets a limit on what PCRE2 spends on a match:
     * "(*LIMIT_MATCH=n)", "(*LIMIT_DEPTH=n)", its old name
     * "(*LIMIT_RECURSION=n)", or "(*LIMIT_HEAP=n)".
     */
    private static function setsLimit(NodeInterface $node): bool
    {
        if ($node instanceof LimitMatchNode || ($node instanceof PcreVerbNode && \in_array($node->name, self::LIMITS, true))) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::setsLimit($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node is a group the engine may skip without
     * backtracking into it: repeated possessively from zero, or atomic
     * around what can match empty.
     */
    private function skippableAtomicGroup(?NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            return QuantifierType::Possessive === $node->type && $node->node instanceof GroupNode && $this->quantifierAllowsZero($node);
        }

        return $node instanceof GroupNode && GroupType::Atomic === $node->type && $this->canMatchEmpty($node->child);
    }

    /**
     * Whether the node may take a character above ASCII, which the
     * character sets, stopping at 0x7F, do not hold: a byte or a code point
     * above it, a dot, a negated class, a type but \\d, \\w and \\s without
     * Unicode properties, a property, a POSIX class, and anything not read
     * here. A locale gives \\w and \\s bytes above ASCII, "\xE9" and "\xA0",
     * but never one to both: the two stay disjoint there as they are below.
     */
    private function mayTakeAboveAscii(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof LiteralNode => '' !== trim($node->value, "\x00..\x7F"),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => $node->codePoint > 0x7F,
            $node instanceof CharTypeNode => $this->state->unicodeProperties || !\in_array($node->value, ['d', 'w', 's'], true),
            $node instanceof DotNode, $node instanceof UnicodePropNode, $node instanceof PosixClassNode => true,
            $node instanceof CharClassNode && $node->isNegated => true,
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof CommentNode, $node instanceof KeepNode => false,
            [] === $node->getChildren() => true,
            default => $this->anyMayTakeAboveAscii($node->getChildren()),
        };
    }

    /**
     * @param array<NodeInterface> $nodes
     */
    private function anyMayTakeAboveAscii(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($this->mayTakeAboveAscii($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The characters what follows a repetition may start with, the items
     * after it in its sequence first, then, while all of them may match
     * nothing, what follows the groups around it: "(a+b?)a" gives an "a"
     * back to the "a" after the group on "aa". The walk stops at the end of
     * the pattern, of an atomic group or of a lookaround, which nothing
     * after it backtracks into; the body of a repeat that may run again
     * follows the end of its body as well. A group runs wherever a call
     * runs it, and gives back to what follows the call: "(?&n)a" after
     * "(?<n>a+b?)" in a DEFINE matches "aa". Null when a lookaround may come
     * first, which reads nothing yet looks at the characters the repetition
     * would give back, "a+(?!b)" matching "a" in "aab" where "a++(?!b)"
     * matches nothing, or a character above ASCII, which the sets, stopping
     * at 0x7F, do not hold.
     *
     * @param list<NodeInterface> $suffix
     */
    private function charsAfter(array $suffix): ?ByteCharSet
    {
        $chars = ByteCharSet::empty();
        $read = [];

        return $this->addCharsAfter($suffix, $this->after, $chars, $read) ? $chars : null;
    }

    /**
     * Adds the characters what follows the items may start with to the
     * set: the items, then, while all of them may match nothing, what
     * follows the items the frames stand for, the innermost last, as
     * PossessiveRewrites::$after holds them. Past the end of a group comes
     * what follows each call that runs it, then what follows the group
     * where it stands, unless a DEFINE holds it there; past the end of the
     * pattern comes what follows each call of the whole pattern. Whether
     * the set holds them all, false when a lookaround or a character above
     * ASCII may come first, or when a call stands in a script run.
     *
     * @param array<NodeInterface>                                                                 $items
     * @param list<array{list<NodeInterface>, int}|QuantifierNode|GroupNode|DefineNode|false|null> $frames
     * @param array<int, true>                                                                     $read   the groups and the pattern whose calls the walk read, by object id
     *
     * @param-out ByteCharSet $chars
     */
    private function addCharsAfter(array $items, array $frames, ByteCharSet &$chars, array &$read): bool
    {
        $readAll = $this->addFirstChars($items, $chars);

        for ($level = \count($frames) - 1; false === $readAll && $level >= 0; $level--) {
            $frame = $frames[$level];
            // Nothing past the end of an atomic group or a lookaround
            // backtracks into it, and nothing past the end of a DEFINE runs.
            if (null === $frame || $frame instanceof DefineNode) {
                return true;
            }

            if (false === $frame) {
                return false;
            }

            // Another run of the body, or what follows the repeat after
            // its last run.
            if ($frame instanceof QuantifierNode) {
                $readAll = null === $this->addFirstChars([$frame->node], $chars) ? null : false;

                continue;
            }

            if ($frame instanceof GroupNode) {
                // What follows the group the walk has met is read: every
                // way out of it leads to the same frames.
                if (isset($read[spl_object_id($frame)])) {
                    return true;
                }

                if (!$this->addCharsAfterCalls($frame, $chars, $read)) {
                    return false;
                }

                if (!$this->runsInPlace($frames, $level)) {
                    return true;
                }

                continue;
            }

            $readAll = $this->addFirstChars(\array_slice($frame[0], $frame[1]), $chars);
        }

        if (null === $readAll) {
            return false;
        }

        return $readAll || null === $this->pattern || isset($read[spl_object_id($this->pattern)]) || $this->addCharsAfterCalls($this->pattern, $chars, $read);
    }

    /**
     * Adds what follows each call of the group, or of the whole pattern,
     * to the set: whether the set holds it all.
     *
     * @param array<int, true> $read the groups and the pattern whose calls the walk read, by object id
     *
     * @param-out ByteCharSet $chars
     */
    private function addCharsAfterCalls(GroupNode|RegexNode $called, ByteCharSet &$chars, array &$read): bool
    {
        $read[spl_object_id($called)] = true;
        foreach ($this->calls()[spl_object_id($called)] ?? [] as $frames) {
            if (!$this->addCharsAfter([], $frames, $chars, $read)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the group the frame at the level stands for runs where it
     * stands: not in a DEFINE, unless inside a group a call runs there.
     *
     * @param list<array{list<NodeInterface>, int}|QuantifierNode|GroupNode|DefineNode|false|null> $frames
     */
    private function runsInPlace(array $frames, int $level): bool
    {
        while (--$level >= 0) {
            $frame = $frames[$level];
            if ($frame instanceof DefineNode) {
                return false;
            }

            if ($frame instanceof GroupNode && isset($this->calls()[spl_object_id($frame)])) {
                return true;
            }
        }

        return true;
    }

    /**
     * What follows each call of the pattern visited whole, by the object id
     * of the group the call runs, of the pattern for "(?R)", "(?0)" and
     * "\g<0>"; a recursion test, "(?(R1)", calls nothing.
     *
     * @return array<int, list<list<array{list<NodeInterface>, int}|QuantifierNode|GroupNode|DefineNode|false|null>>>
     */
    private function calls(): array
    {
        if (null !== $this->calls) {
            return $this->calls;
        }

        $this->calls = [];
        if (null === $this->pattern) {
            return [];
        }

        $found = [];
        $frames = [];
        self::findCalls($this->pattern->pattern, $frames, $found);
        // Most patterns call nothing: the groups are numbered only for one
        // that does.
        if ([] === $found) {
            return [];
        }

        $groups = GroupIndex::of($this->pattern->pattern);
        foreach ($found as [$call, $at]) {
            // "(?&R)" calls a group named "R", "(?R)" the whole pattern.
            $called = '0' === $call->reference || ('R' === $call->reference && '' === $call->syntax) ? [$this->pattern] : $groups->groupsCalledBy($call);
            foreach ($called as $node) {
                $this->calls[spl_object_id($node)][] = $at;
            }
        }

        return $this->calls;
    }

    /**
     * Collects each call under the node with the frames that stand where
     * it stands, as the rewriter records them.
     *
     * @param list<array{list<NodeInterface>, int}|QuantifierNode|GroupNode|DefineNode|false|null>                              $frames
     * @param list<array{SubroutineNode, list<array{list<NodeInterface>, int}|QuantifierNode|GroupNode|DefineNode|false|null>}> $found
     */
    private static function findCalls(NodeInterface $node, array &$frames, array &$found): void
    {
        if ($node instanceof SubroutineNode) {
            $found[] = [$node, $frames];

            return;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $i => $child) {
                $frames[] = [$node->children, $i + 1];
                self::findCalls($child, $frames, $found);
                array_pop($frames);
            }

            return;
        }

        $opened = self::frames($node);
        array_push($frames, ...$opened);
        // A recursion test reads where the match stands.
        foreach ($node instanceof ConditionalNode && $node->condition instanceof SubroutineNode ? [$node->yes, $node->no] : $node->getChildren() as $child) {
            self::findCalls($child, $frames, $found);
        }

        if ([] !== $opened) {
            array_pop($frames);
        }
    }

    /**
     * The frame the node opens for what stands inside it, as
     * PossessiveRewrites::$after holds it, alone in the list, or none.
     *
     * @return list<QuantifierNode|GroupNode|DefineNode|false|null>
     */
    private static function frames(NodeInterface $node): array
    {
        return match (true) {
            $node instanceof GroupNode && self::closes($node) => [null],
            $node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type) => [$node],
            $node instanceof QuantifierNode && self::mayRunAgain($node) => [$node],
            $node instanceof DefineNode => [$node],
            $node instanceof ScriptRunNode => [false],
            default => [],
        };
    }

    /**
     * Forgets the frame the node opened, if any.
     */
    private function leaveNode(NodeInterface $node): void
    {
        if ([] !== self::frames($node)) {
            array_pop($this->after);
        }
    }

    /**
     * Adds the characters the items may start with to the set, up to the
     * first that cannot match empty: whether one cannot, or null when a
     * lookaround or a character above ASCII may come first.
     *
     * @param array<NodeInterface> $items
     *
     * @param-out ByteCharSet $chars
     */
    private function addFirstChars(array $items, ByteCharSet &$chars): ?bool
    {
        foreach ($items as $item) {
            if (self::holdsLookaround($item) || $this->mayTakeAboveAscii($item)) {
                return null;
            }

            $chars = $chars->union($this->firstChars($item));
            if (!$this->canMatchEmpty($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The characters the item may start with. "$" and "\Z" read nothing,
     * and hold only before a newline where a character follows: the bytes
     * any newline convention starts with stand for them, "\n" under the
     * default one, the line ends the repetition may have given back. "\z"
     * holds where no character follows, and a comment reads nothing.
     */
    private function firstChars(NodeInterface $node): ByteCharSet
    {
        return match (true) {
            $node instanceof AnchorNode && '$' === $node->value, $node instanceof AssertionNode && 'Z' === $node->value => ByteCharSet::fromChar("\0")->union(ByteCharSet::fromRange(0x0A, 0x0D)),
            $node instanceof AssertionNode && 'z' === $node->value, $node instanceof CommentNode => ByteCharSet::empty(),
            default => $this->analyzerAt($node)->firstChars($node),
        };
    }

    /**
     * The character sets in force where the node stands: under the flags
     * an option setting changed there, "(?i)" or "(?i:...)", and for a
     * group a call runs, those it is written under. A node the pattern
     * visited whole does not hold reads the flags the pattern is written
     * with.
     */
    private function analyzerAt(NodeInterface $node): CharSetAnalyzer
    {
        return $this->inForce()[$node] ?? $this->state->charSets();
    }

    /**
     * @return \SplObjectStorage<NodeInterface, CharSetAnalyzer>
     */
    private function inForce(): \SplObjectStorage
    {
        return $this->inForce ??= null === $this->pattern ? new \SplObjectStorage() : $this->state->charSets()->inForce($this->pattern->pattern);
    }

    /**
     * Whether nothing after the group backtracks into it: an atomic group
     * or a lookaround, but a scan substring, which what follows backtracks
     * into: "^(aac)(*scs:(1)(a+b?))(?=\2ac)" matches "aac" in "aacaac"
     * with group 2 given back to "a". Nor does a non-atomic lookaround,
     * "(*napla:" or "(?*", whose flags hold the "*": "^(*napla:(a+b?))\1\1"
     * matches "aa" with group 1 given back to "a".
     */
    private static function closes(GroupNode $node): bool
    {
        return GroupType::Atomic === $node->type || (GroupType::ScanSubstring !== $node->type && '*' !== $node->flags && \in_array($node->type, self::LOOKAROUNDS, true));
    }

    /**
     * Whether the body of the repeat may run again after a run, up to a
     * count of two or more: "+", "*", "{2}" or "{0,2}", not "?".
     */
    private static function mayRunAgain(QuantifierNode $node): bool
    {
        return ($node->bounds->max ?? \PHP_INT_MAX) >= 2;
    }

    private static function holdsLookaround(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && \in_array($node->type, self::LOOKAROUNDS, true)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsLookaround($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the repeat is greedy and what follows it, the items given,
     * then what follows them, can never start with a character it gives
     * back, read under the character sets in force where it stands.
     *
     * @param list<NodeInterface> $suffix
     */
    private function givesNothingBack(QuantifierNode $repeat, array $suffix, CharSetAnalyzer $analyzer): bool
    {
        // The sets stop at 0x7F: above it nothing is known disjoint.
        if (QuantifierType::Greedy !== $repeat->type || !$this->isPossessifyCandidate($repeat)
            || $this->repeats->isCaptureSensitive($repeat->node) || $this->canMatchEmpty($repeat->node) || $this->mayTakeAboveAscii($repeat->node)) {
            return false;
        }

        // A repeated group gives back whole runs, which need not start with
        // a character it ends with: "((?:b|ba)*)c" ends a run of "ba" with
        // the "b" it gives back on "bac". One that ends its sequence stays
        // greedy. So does one under a limit the pattern sets: PCRE2's
        // interpreter spends a match call more on "c(?:ab)++e" than on
        // "c(?:ab)+e" over "cababe", and a frame more on "c(?:ab){2,}+e",
        // which a repeated character never does.
        if ($repeat->node instanceof GroupNode && ([] === $suffix || $this->limited)) {
            return false;
        }

        try {
            // A repeated character gives back the character it repeats, a
            // repeated group the runs it matched, read from where each
            // starts.
            $givenBack = $repeat->node instanceof GroupNode ? $this->runStarts($repeat->node) : $analyzer->lastChars($repeat->node);
            $followingChars = null === $givenBack ? null : $this->charsAfter($suffix);
            if (null !== $givenBack && null !== $followingChars && !$givenBack->intersects($followingChars)) {
                return true;
            }
        } catch (\Throwable) { // Not reached by any pattern read: the walks throw nothing of their own.
            // If analysis fails, don't optimize
        }

        return false;
    }

    /**
     * The characters a run of the repeated group starts with, where every
     * run of its body matches one way: the repeat then gives back whole
     * runs, from the character each starts with. Null where a run may match
     * another way, and end inside its body or past where the greedy one
     * ended: "(?:b|ba)*c" takes "ba" as one run on "bac" once "b" alone
     * left the "a" to the "c", and "(?:-[a-z]+)*$" ends a run anywhere in
     * its letters.
     */
    private function runStarts(GroupNode $group): ?ByteCharSet
    {
        $items = [];
        if (!$this->matchesOneWay($group, $items)) {
            return null;
        }

        $chars = ByteCharSet::empty();

        return true === $this->addFirstChars($items, $chars) ? $chars : null;
    }

    /**
     * Whether every run of the node matches one way, adding to the list
     * the items it reads in order: characters, literals and comments, in a
     * sequence or a group that neither captures, looks around nor is
     * atomic, and DEFINEs, which read nothing.
     *
     * @param list<NodeInterface> $items
     */
    private function matchesOneWay(NodeInterface $node, array &$items): bool
    {
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!$this->matchesOneWay($child, $items)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof GroupNode) {
            return (GroupType::NonCapturing === $node->type || GroupType::InlineFlags === $node->type) && $this->matchesOneWay($node->child, $items);
        }

        if ($node instanceof LiteralNode || $node instanceof CommentNode || $this->repeats->isSingleCharacter($node)) {
            $items[] = $node;

            return true;
        }

        return $node instanceof DefineNode;
    }

    /**
     * The repeat made possessive.
     */
    private function possessive(QuantifierNode $node): QuantifierNode
    {
        $this->state->changed(Rewriter::POSSESSIVE);

        return new QuantifierNode($node->node, $node->quantifier, QuantifierType::Possessive, $node->startPosition, $node->endPosition);
    }

    private function isPossessifyCandidate(QuantifierNode $node): bool
    {
        if (!\in_array($node->quantifier, ['+', '*'], true)) {
            return $node->bounds?->isUnbounded() ?? false;
        }

        return true;
    }

    private function canMatchEmpty(NodeInterface $node): bool
    {
        $nullable = $this->nullableStatus($node);

        return $nullable ?? true;
    }

    /**
     * @return bool|null true if nullable, false if consumes, null if unknown
     */
    private function nullableStatus(NodeInterface $node): ?bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof CharTypeNode
            || $node instanceof DotNode
            || $node instanceof CharClassNode
            || $node instanceof RangeNode
            || $node instanceof UnicodePropNode
            || $node instanceof CharLiteralNode
            || $node instanceof PosixClassNode
        ) {
            return false;
        }

        if ($node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof KeepNode
            || $node instanceof CommentNode
            || $node instanceof CalloutNode
            || $node instanceof LimitMatchNode
            || $node instanceof PcreVerbNode
        ) {
            return true;
        }

        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return null;
        }

        if ($node instanceof GroupNode) {
            if (\in_array($node->type, [
                GroupType::LookaheadPositive,
                GroupType::LookaheadNegative,
                GroupType::LookbehindPositive,
                GroupType::LookbehindNegative,
                GroupType::ScanSubstring,
            ], true)) {
                return true;
            }

            return $this->nullableStatus($node->child);
        }

        if ($node instanceof QuantifierNode) {
            if ($this->quantifierAllowsZero($node)) {
                return true;
            }

            return $this->nullableStatus($node->node);
        }

        if ($node instanceof SequenceNode) {
            $unknown = false;
            foreach ($node->children as $child) {
                $childNullable = $this->nullableStatus($child);
                if (false === $childNullable) {
                    return false;
                }
                if (null === $childNullable) {
                    $unknown = true;
                }
            }

            return $unknown ? null : true;
        }

        if ($node instanceof AlternationNode) {
            $unknown = false;
            foreach ($node->alternatives as $alt) {
                $altNullable = $this->nullableStatus($alt);
                if (true === $altNullable) {
                    return true;
                }
                if (null === $altNullable) {
                    $unknown = true;
                }
            }

            return $unknown ? null : false;
        }

        if ($node instanceof ConditionalNode) {
            $yes = $this->nullableStatus($node->yes);
            $no = $this->nullableStatus($node->no);

            if (true === $yes || true === $no) {
                return true;
            }
            if (false === $yes && false === $no) {
                return false;
            }

            return null;
        }

        // A DEFINE block reads nothing: its groups run only when called.
        if ($node instanceof DefineNode) {
            return true;
        }

        return null;
    }

    private function quantifierAllowsZero(QuantifierNode $node): bool
    {
        return 0 === $node->bounds?->min;
    }
}

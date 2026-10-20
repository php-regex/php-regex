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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * The length range of a lookbehind branch, the way PCRE measures it: a call
 * or a reference is as long as the group it names, a lookaround is
 * zero-width however often it is repeated, and a call that stands inside
 * the group it calls, or that calls a group the measure reached through
 * calls, has no bound. "(*ACCEPT)" and "(*FAIL)" end the branch they stand
 * in. Lengths count bytes, or characters in UTF mode.
 *
 * Each item is added to the length of its branch before the repeat after
 * it is read, and a branch past 65535 is refused there, on every release:
 * "a{65535}b?" and "a{65535}b{0}" are too long. A group's branches are
 * summed from the start of the group. Before PCRE2 10.34 the sum is a 32-bit
 * integer that wraps around instead of passing the limit: a branch that
 * ends below zero has no fixed length, and the measure goes on past one
 * that wrapped.
 *
 * Before PCRE2 10.43 the measure ends at the first item of no fixed length:
 * a repeat of variable count, whatever it repeats, or a group whose
 * branches differ in length. The lookbehind is then refused whatever
 * follows.
 *
 * Validation\LookbehindChecks, which raises PCRE's errors as it measures,
 * hears about each lookaround, group body and reference met on the way.
 *
 * A group, or a lookbehind, measured once is not measured again while none
 * of the groups its measure called is being measured where it is met: one
 * group calling the next twice, k levels down, is measured k times, not
 * 2^k. In a pattern with a branch reset PCRE measures again, and counts, so
 * nothing is kept there.
 *
 * @internal
 *
 * @phpstan-import-type LengthRange from LengthRangeCalculator
 */
final class LookbehindLength
{
    /**
     * The longest branch PCRE2 measures (LOOKBEHIND_MAX).
     */
    private const MAX_BRANCH_LENGTH = 65535;

    private const LOOKAROUNDS = [
        GroupType::LookaheadPositive,
        GroupType::LookaheadNegative,
        GroupType::LookbehindPositive,
        GroupType::LookbehindNegative,
    ];

    private const LOOKAHEADS = [
        GroupType::LookaheadPositive,
        GroupType::LookaheadNegative,
    ];

    /**
     * The length of each group measured, by node, with the groups its
     * measure called.
     *
     * @var array<int, array{length: LengthRange, calls: array<int, true>, unfixed: bool, tooLong: int|null}>
     */
    private array $measuredGroups = [];

    /**
     * The lookbehinds measured and found sound, by node, with the groups
     * their measure called: one inside another is measured with it, and not
     * again when the walk reaches it, unless it calls a group around it.
     *
     * @var array<int, array<int, true>>
     */
    private array $measuredLookbehinds = [];

    /**
     * The groups the measure in progress called, by node: whether one of
     * them is being measured is all that decides what the measure finds.
     *
     * @var array<int, true>
     */
    private array $called = [];

    /**
     * How many times a measure called a group being measured already. A
     * measure during which this moved is not kept: what it found depends on
     * the groups being measured around it.
     */
    private int $recursions = 0;

    /**
     * Whether the measure in progress met an item of no fixed length where
     * the PCRE2 judged (up to 10.42) ends the measure there.
     */
    private bool $unfixed = false;

    /**
     * The length the branch reached when it passed MAX_BRANCH_LENGTH, which
     * ends the measure there; null while it has not.
     */
    private ?int $tooLong = null;

    /**
     * The longest branch measured to its end, of a lookbehind or of a group
     * one holds or calls.
     */
    private int $longestBranch = 0;

    /**
     * @param bool                                                               $unicode        whether lengths count characters
     * @param bool                                                               $variableLength whether the PCRE2 judged (10.43+) measures a group repeated zero times as empty
     * @param (\Closure(GroupNode, array<int, true>): void)|null                 $onLookaround   told of a lookaround met inside a branch
     * @param (\Closure(NodeInterface): void)|null                               $onGroupBody    told of each group body measured
     * @param (\Closure(SubroutineNode|BackrefNode, list<GroupNode>): void)|null $onReference    told of each call or reference, with the groups it names
     * @param bool                                                               $inLookaheads   whether the PCRE2 judged (10.34+) measures the lookbehinds inside a lookahead it meets
     * @param bool                                                               $skipsDefine    whether the PCRE2 judged (10.35+) skips a "(?(DEFINE)" group
     * @param bool                                                               $readsNesting   whether the PCRE2 judged reads an extended class that nests a class or a parenthesis whole (all but 10.45 to 10.47)
     * @param bool                                                               $wrapsLengths   whether the PCRE2 judged (before 10.34) adds lengths up in a 32-bit integer that wraps around
     */
    public function __construct(
        private readonly GroupIndex $groups,
        private readonly bool $unicode,
        private readonly bool $variableLength,
        private readonly ?\Closure $onLookaround = null,
        private readonly ?\Closure $onGroupBody = null,
        private readonly ?\Closure $onReference = null,
        private readonly bool $inLookaheads = true,
        private readonly bool $skipsDefine = true,
        private readonly bool $readsNesting = true,
        private readonly bool $wrapsLengths = false,
    ) {}

    /**
     * Runs $measure, the measure of the lookbehind, unless it was measured
     * already and calls none of the groups being measured here.
     *
     * @param array<int, true> $measuring the groups being measured, by node
     * @param bool             $outermost whether the lookbehind sits in no group being measured
     * @param \Closure(): void $measure
     */
    public function once(GroupNode $lookbehind, array $measuring, bool $outermost, \Closure $measure): void
    {
        if ($outermost) {
            $this->called = [];
        }

        $id = spl_object_id($lookbehind);
        $measured = $this->measuredLookbehinds[$id] ?? null;
        if (null !== $measured && !self::callsAnyOf($measured, $measuring)) {
            $this->called += $measured;

            return;
        }

        $calledAround = $this->called;
        $this->called = [];
        $recursions = $this->recursions;

        $measure();

        if (!$this->groups->hasBranchReset() && $recursions === $this->recursions) {
            $this->measuredLookbehinds[$id] = $this->called;
        }
        $this->called += $calledAround;
    }

    /**
     * The longest branch measured to its end so far, of a lookbehind or of a
     * group one holds or calls: what PCRE2 reports as the longest lookbehind
     * before 10.34.
     */
    public function longestBranch(): int
    {
        return $this->longestBranch;
    }

    /**
     * Forget every measure kept: the nodes measured may be gone, and their
     * ids taken by others.
     */
    public function forget(): void
    {
        $this->measuredGroups = [];
        $this->measuredLookbehinds = [];
    }

    /**
     * The length range of one branch of a lookbehind, whether the measure
     * ended on an item of no fixed length, which the PCRE2 judged (up to
     * 10.42) refuses whatever the range, and the length the branch reached
     * past 65535 where the measure ended on it, null where it did not.
     *
     * @param array<int, true> $expanding the groups being measured, by node
     *
     * @return array{0: LengthRange, 1: bool, 2: int|null}
     *
     * @phpstan-impure
     */
    public function branch(NodeInterface $branch, array $expanding = []): array
    {
        [$unfixed, $tooLong] = [$this->unfixed, $this->tooLong];
        [$this->unfixed, $this->tooLong] = [false, null];

        try {
            $length = $this->branchOf($branch, $expanding);

            return [$length, $this->unfixed, $this->tooLong];
        } finally {
            [$this->unfixed, $this->tooLong] = [$unfixed, $tooLong];
        }
    }

    /**
     * @param array<int, true> $expanding the groups being measured, by node
     * @param int              $before    the length of the branch before the node, where a sequence measures it as an item
     *
     * @return LengthRange
     *
     * @phpstan-impure
     */
    public function of(NodeInterface $node, array $expanding = [], int $before = 0): array
    {
        $length = $this->measure($node, $expanding, $before);

        // Up to PCRE2 10.42 an item of more than one length ends the measure.
        if (!$this->variableLength && $length[0] !== $length[1]) {
            $this->unfixed = true;
        }

        return $length;
    }

    /**
     * The length of a branch, of a group or a lookbehind: one past the limit
     * is refused, and before PCRE2 10.34 one that wrapped below zero has no
     * fixed length.
     *
     * @param array<int, true> $expanding the groups being measured, by node
     *
     * @return LengthRange
     */
    private function branchOf(NodeInterface $branch, array $expanding): array
    {
        $length = $this->of($branch, $expanding);
        if ($this->unfixed || null === $length[1]) {
            return $length;
        }

        if ($length[1] >= 0 && $length[1] <= self::MAX_BRANCH_LENGTH) {
            if ($length[1] > $this->longestBranch) {
                $this->longestBranch = $length[1];
            }

            return $length;
        }

        if ($length[1] < 0) {
            $this->unfixed = true;
        } else {
            $this->passes($length[1]);
        }

        return $length;
    }

    /**
     * $length repeated $count times, as PCRE counts it.
     */
    private function times(int $length, int $count): int
    {
        return $this->wrapsLengths ? self::wrap($length * $count) : $length * $count;
    }

    /**
     * A length as a 32-bit integer holds it, wrapped around.
     */
    private static function wrap(int $value): int
    {
        $value &= 0xFFFFFFFF;

        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }

    /**
     * Whether the branch, at that length, passed the limit: the measure ends
     * there, as PCRE refuses the lookbehind.
     */
    private function passes(?int $length): bool
    {
        if (null !== $length && $length > self::MAX_BRANCH_LENGTH) {
            $this->tooLong ??= $length;
        }

        return null !== $this->tooLong;
    }

    /**
     * @param array<int, true> $expanding the groups being measured, by node
     * @param int              $before    the length of the branch before the node: only an item a sequence measures follows what came before it, what the item holds starts branches of its own
     *
     * @return LengthRange
     */
    private function measure(NodeInterface $node, array $expanding, int $before): array
    {
        if ($node instanceof SequenceNode) {
            // A sequence inside another, as the bytes of a character without
            // "u", goes on with the branch that holds it.
            [$min, $max] = [0, 0];
            foreach ($node->children as $child) {
                // "(*ACCEPT)" and "(*FAIL)" end the branch: PCRE measures
                // nothing after them.
                if (self::endsBranch($child)) {
                    break;
                }

                $reached = $this->wrapsLengths ? self::wrap($before + $max) : $before + $max;
                [$childMin, $childMax] = $this->of($child, $expanding, $reached);
                if ($this->wrapsLengths) {
                    $min = self::wrap($min + $childMin);
                    $max = null === $childMax ? null : self::wrap($max + $childMax);
                } else {
                    $min += $childMin;
                    $max = null === $childMax ? null : $max + $childMax;
                }

                // PCRE stops measuring at the first item it cannot bound, and
                // at the first that takes the branch past the limit.
                if (null === $max || $this->unfixed || null !== $this->tooLong) {
                    break;
                }

                $reached = $this->wrapsLengths ? self::wrap($before + $max) : $before + $max;
                if ($reached > self::MAX_BRANCH_LENGTH && $this->passes($reached)) {
                    break;
                }
            }

            return [$min, $max];
        }

        if ($node instanceof AlternationNode || $node instanceof ConditionalNode) {
            // An assertion as the condition is measured with the group: a
            // lookbehind there is checked like one in a branch.
            if ($node instanceof ConditionalNode) {
                $this->conditionAssertions($node->condition, $expanding);
            }

            // A condition with no "|" is a group of one branch: PCRE measures
            // "(?(1)b)" as "b".
            $alternatives = match (true) {
                $node instanceof AlternationNode => $node->alternatives,
                self::hasNoBranch($node) => [$node->yes, $node->no],
                default => [$node->yes],
            };
            [$min, $max] = [\PHP_INT_MAX, 0];
            foreach ($alternatives as $alternative) {
                // PCRE measures, and counts, each branch of a condition.
                if ($node instanceof ConditionalNode) {
                    $this->groupBody($alternative);
                }

                [$altMin, $altMax] = $this->branchOf($alternative, $expanding);
                $min = min($min, $altMin);
                $max = null === $altMax ? null : max($max, $altMax);

                // Up to PCRE2 10.42 a branch of another length than those
                // before it ends the measure.
                if (null === $max || $this->unfixed || null !== $this->tooLong || (!$this->variableLength && $min !== $max)) {
                    break;
                }
            }

            return [$min, $max];
        }

        if ($node instanceof GroupNode) {
            if (\in_array($node->type, self::LOOKAROUNDS, true)) {
                // Before PCRE2 10.34 a lookahead is skipped whole.
                if (null !== $this->onLookaround && ($this->inLookaheads || !\in_array($node->type, self::LOOKAHEADS, true))) {
                    ($this->onLookaround)($node, $expanding);
                }

                return [0, 0];
            }

            $this->groupBody($node->child);

            return $this->branchOf($node->child, $expanding);
        }

        if ($node instanceof ScriptRunNode && null !== $node->content) {
            $this->groupBody($node->content);

            return $this->branchOf($node->content, $expanding);
        }

        if ($node instanceof QuantifierNode) {
            [$childMin, $childMax] = $this->of($node->node, $expanding);
            [$qMin, $qMax] = self::bounds($node->quantifier);

            // The item is added to its branch before the repeat is read: a
            // repeat of variable count, or of none, comes too late.
            if (!$this->unfixed && null !== $childMax) {
                $reached = $this->wrapsLengths ? self::wrap($before + $childMax) : $before + $childMax;
                if ($reached > self::MAX_BRANCH_LENGTH) {
                    $this->tooLong ??= $reached;

                    return [$childMin, $childMax];
                }
            }

            // A repeated lookahead, "(?=.)*" or "(*pla:.)+", adds nothing.
            // Only a lookahead read directly under the quantifier does: a
            // repeated lookbehind, a lookahead inside another group, or a
            // repeated "(*ACCEPT)" has no bound, as PCRE measures them.
            if ($node->node instanceof GroupNode && \in_array($node->node->type, [
                GroupType::LookaheadPositive,
                GroupType::LookaheadNegative,
            ], true)) {
                return [0, 0];
            }

            // Up to PCRE2 10.42 a repeat of variable count has no fixed
            // length, whatever it repeats: "(?:)?" or "(?<=a){1,2}" too. Up
            // to 10.34 the end of a DEFINE group that opens with a group
            // closes what holds it, which is not followed: what repeats it
            // is left alone.
            if (!$this->variableLength && $qMin !== $qMax && !$this->closesItsHolder($node->node)) {
                $this->unfixed = true;
            }

            return [$this->times($childMin, $qMin), null === $childMax || -1 === $qMax ? null : $this->times($childMax, $qMax)];
        }

        if ($node instanceof DefineNode) {
            return $this->skipsDefine ? [0, 0] : $this->defineWithItsFirstItemSkipped($node, $expanding);
        }

        // PCRE2 10.45 to 10.47 skip an extended class to its first "]" or
        // ")": one that nests a class or a parenthesis leaves the rest of it
        // to be measured, and the lookbehind has no bound.
        if ($node instanceof ExtendedCharClassNode && !$this->readsNesting && self::nestsAClass($node->text)) {
            return [0, null];
        }

        // "\X" is a grapheme cluster of any length, here or in a group a call
        // or a reference reaches.
        if ($node instanceof CharTypeNode && 'X' === $node->value) {
            return [0, null];
        }

        if ($node instanceof SubroutineNode || $node instanceof BackrefNode) {
            return $this->referencedGroupLength($node, $expanding);
        }

        return $node->accept(new LengthRangeCalculator($this->unicode));
    }

    /**
     * @param array<int, true> $expanding
     *
     * @return LengthRange
     */
    private function referencedGroupLength(SubroutineNode|BackrefNode $node, array $expanding): array
    {
        $groups = $node instanceof SubroutineNode ? $this->groups->groupsCalledBy($node) : $this->groups->groupsReferencedBy($node);
        if (null !== $this->onReference) {
            ($this->onReference)($node, $groups);
        }

        // No group, a whole-pattern recursion, or a reference to a name that
        // several groups share: PCRE finds no bound.
        if (1 !== \count($groups)) {
            return [0, null];
        }

        $group = $groups[0];
        $id = spl_object_id($group);
        // A call inside the group it calls, or to a group the calls reached
        // already: PCRE finds no bound.
        $inside = $node->getStartPosition() >= $group->getStartPosition() && $node->getStartPosition() < $group->getEndPosition();
        if ($inside || isset($expanding[$id])) {
            $this->recursions++;

            return [0, null];
        }

        // With a branch reset anywhere in the pattern, PCRE measures no back
        // reference in a lookbehind: "(a)(?|b|c)(?<=\1)" is not limited.
        if ($node instanceof BackrefNode && $this->groups->hasBranchReset()) {
            return [0, null];
        }

        $this->called[$id] = true;

        // A group measured already, from this call or another, is as long
        // here unless it calls a group being measured here.
        $measured = $this->measuredGroups[$id] ?? null;
        if (null !== $measured && !self::callsAnyOf($measured['calls'], $expanding)) {
            $this->called += $measured['calls'];
            $this->unfixed = $this->unfixed || $measured['unfixed'];
            $this->tooLong ??= $measured['tooLong'];

            return $measured['length'];
        }

        $this->groupBody($group->child);

        $calledAround = $this->called;
        $this->called = [];
        $recursions = $this->recursions;
        $length = $this->branchOf($group->child, $expanding + [$id => true]);

        if (!$this->groups->hasBranchReset() && $recursions === $this->recursions) {
            $this->measuredGroups[$id] = ['length' => $length, 'calls' => $this->called, 'unfixed' => $this->unfixed, 'tooLong' => $this->tooLong];
        }
        $this->called += $calledAround;

        return $length;
    }

    /**
     * Before PCRE2 10.35, PCRE measures a "(?(DEFINE)" group in a lookbehind
     * as a group, but skips the first item of its body, or the first
     * character of a run of them. Where that item is a class, the rest of the
     * class has no length; where the body is empty, the measure runs past
     * its end; where a quantifier follows the item, it repeats nothing, and
     * only a count leaves a length. Where the item opens a group, a DEFINE
     * group among them, the body of that group is measured in its place.
     *
     * There PCRE then reads every later ")" one level up, so that the end of
     * the DEFINE group closes what holds it, and ends the lookbehind early
     * where the DEFINE group stands in it directly: what follows it there
     * goes unmeasured, as in "(?<=(?(DEFINE)(a))b+)", which 10.34 compiles.
     * That reading is not followed: what follows is measured, and such a
     * pattern refused.
     *
     * @param array<int, true> $expanding
     *
     * @return LengthRange
     */
    private function defineWithItsFirstItemSkipped(DefineNode $node, array $expanding): array
    {
        $branches = $node->content instanceof AlternationNode ? $node->content->alternatives : [$node->content];
        $items = $branches[0] instanceof SequenceNode ? $branches[0]->children : [$branches[0]];
        // The bytes of a character without "u" are items of their own: only
        // the first is skipped.
        while (($items[0] ?? null) instanceof SequenceNode) {
            $items = [...$items[0]->children, ...\array_slice($items, 1)];
        }
        $first = $items[0] ?? null;
        $rest = new SequenceNode(\array_slice($items, 1), $node->startPosition, $node->endPosition);

        $count = null;
        if ($first instanceof QuantifierNode) {
            [$qMin, $qMax] = self::bounds($first->quantifier);
            $count = $qMin === $qMax ? $qMin : null;
        }
        $item = $first instanceof QuantifierNode ? $first->node : $first;

        // What is left of the first item once it is skipped.
        $head = [0, 0];
        if ($item instanceof GroupNode || $item instanceof DefineNode) {
            [$min, $max] = $this->of($item instanceof GroupNode ? $item->child : $item->content, $expanding);
            $head = $first instanceof QuantifierNode
                ? (null === $count || null === $max ? [0, null] : [$min * $count, $max * $count])
                : [$min, $max];
        } elseif (null === $item || $item instanceof CharClassNode || ($item instanceof LiteralNode && '' === $item->value)
            || ($first instanceof QuantifierNode && null === $count)) {
            return [0, null];
        } elseif ($item instanceof LiteralNode && !$first instanceof QuantifierNode) {
            // A run of characters, past its first one.
            $length = $this->unicode ? mb_strlen($item->value, 'UTF-8') : \strlen($item->value);
            $head = [$length - 1, $length - 1];
        }

        if (null === $head[1]) {
            return [0, null];
        }

        // Past a group, the later branches of the DEFINE group are read as
        // branches of the lookbehind: each must be bounded, whatever its length.
        if ($item instanceof GroupNode || $item instanceof DefineNode) {
            foreach (\array_slice($branches, 1) as $branch) {
                if (null === $this->of($branch, $expanding)[1]) {
                    return [0, null];
                }
            }

            [$restMin, $restMax] = $this->of($rest, $expanding);

            return [$head[0] + $restMin, null === $restMax ? null : $head[1] + $restMax];
        }

        [$min, $max] = [\PHP_INT_MAX, 0];
        foreach ([$rest, ...\array_slice($branches, 1)] as $index => $branch) {
            [$branchMin, $branchMax] = $this->of($branch, $expanding);
            if (0 === $index) {
                [$branchMin, $branchMax] = [$branchMin + $head[0], null === $branchMax ? null : $branchMax + $head[1]];
            }
            $min = min($min, $branchMin);
            $max = null === $branchMax || null === $max ? null : max($max, $branchMax);
        }

        return [$min, $max];
    }

    /**
     * Tell of the lookaround a condition asserts, after a callout too:
     * "(?(?<=a)…)", "(?(?C1)(?<=a)…)".
     *
     * @param array<int, true> $expanding
     */
    private function conditionAssertions(NodeInterface $condition, array $expanding): void
    {
        foreach ($condition instanceof SequenceNode ? $condition->children : [$condition] as $item) {
            if ($item instanceof GroupNode && \in_array($item->type, self::LOOKAROUNDS, true) && null !== $this->onLookaround
                && ($this->inLookaheads || !\in_array($item->type, self::LOOKAHEADS, true))) {
                ($this->onLookaround)($item, $expanding);
            }
        }
    }

    /**
     * Whether a measure that called the groups $called, none of them being
     * measured then, would call one of $measuring: a measure that calls
     * none holds wherever it is taken.
     *
     * @param array<int, true> $called
     * @param array<int, true> $measuring
     */
    private static function callsAnyOf(array $called, array $measuring): bool
    {
        foreach (array_keys($measuring) as $group) {
            if (isset($called[$group])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an extended class, "(?[" to "])" as written, opens a class or
     * a parenthesis inside it: not a POSIX class, nor an escaped or quoted
     * bracket.
     */
    private static function nestsAClass(string $text): bool
    {
        $body = (string) LibraryPcre::replace(['/\\\\Q.*?(?:\\\\E|$)/s', '/\\\\./s', '/\[:\^?[a-z]++:\]/'], '', substr($text, 3, -2));

        return false !== strpbrk($body, '[(');
    }

    /**
     * Whether PCRE (up to 10.34) reads the end of the node as the end of
     * what holds it: a DEFINE group whose first item opens a group, a
     * condition or a script run.
     */
    private function closesItsHolder(NodeInterface $node): bool
    {
        if (!$node instanceof DefineNode || $this->skipsDefine) {
            return false;
        }

        $first = $node->content instanceof AlternationNode ? $node->content->alternatives[0] : $node->content;
        $first = $first instanceof SequenceNode ? ($first->children[0] ?? null) : $first;
        $first = $first instanceof QuantifierNode ? $first->node : $first;

        // "(?i)" sets options and opens nothing.
        if ($first instanceof GroupNode) {
            return GroupType::InlineFlags !== $first->type || !$first->child instanceof LiteralNode || '' !== $first->child->value;
        }

        return $first instanceof DefineNode || $first instanceof ConditionalNode || $first instanceof ScriptRunNode;
    }

    /**
     * Whether a condition has a second branch, empty or not: the tree holds
     * an empty one where no "|" was written, where the first branch ends,
     * and one after the "|" past its end.
     */
    private static function hasNoBranch(ConditionalNode $node): bool
    {
        return !$node->no instanceof LiteralNode || '' !== $node->no->value || $node->no->getStartPosition() !== $node->yes->getEndPosition();
    }

    /**
     * Whether the item ends the branch it stands in, as PCRE measures it:
     * "(*ACCEPT)" or "(*FAIL)", with a name or not. A repeated "(*ACCEPT)"
     * is a group that holds it, from PCRE2 10.34, and refused before.
     */
    private static function endsBranch(NodeInterface $node): bool
    {
        return $node instanceof PcreVerbNode && \in_array(explode(':', $node->verb, 2)[0], ['ACCEPT', 'FAIL', 'F'], true);
    }

    private function groupBody(NodeInterface $body): void
    {
        if (null !== $this->onGroupBody) {
            ($this->onGroupBody)($body);
        }
    }

    /**
     * The repeat counts, -1 for no upper bound. QuantifierBounds reads the
     * spaces inside the braces PCRE2 10.43 allows.
     *
     * @return array{0: int, 1: int}
     */
    private static function bounds(string $quantifier): array
    {
        $bounds = QuantifierBounds::parse($quantifier);

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max ?? -1];
    }
}

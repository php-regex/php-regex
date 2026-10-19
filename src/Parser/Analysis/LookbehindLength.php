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
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * The length range of a lookbehind branch, the way PCRE measures it: a call
 * or a reference is as long as the group it names, a lookaround is
 * zero-width however often it is repeated, and a call that stands inside
 * the group it calls, or that calls a group the measure reached through
 * calls, has no bound. Lengths count bytes, or characters in UTF
 * mode.
 *
 * The validator, which raises PCRE's errors as it measures, hears about
 * each lookaround, group body and reference met on the way.
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
     * @var array<int, array{length: LengthRange, calls: array<int, true>}>
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
     * Whether the measure of the lookbehind ended early: before PCRE2 10.35,
     * at the end of a "(?(DEFINE)" group opened by a group.
     */
    private bool $ended = false;

    /**
     * @param bool                                                               $unicode        whether lengths count characters
     * @param bool                                                               $variableLength whether the PCRE2 judged (10.43+) measures a group repeated zero times as empty
     * @param (\Closure(GroupNode, array<int, true>): void)|null                 $onLookaround   told of a lookaround met inside a branch
     * @param (\Closure(NodeInterface): void)|null                               $onGroupBody    told of each group body measured
     * @param (\Closure(SubroutineNode|BackrefNode, list<GroupNode>): void)|null $onReference    told of each call or reference, with the groups it names
     * @param bool                                                               $inLookaheads   whether the PCRE2 judged (10.34+) measures the lookbehinds inside a lookahead it meets
     * @param bool                                                               $skipsDefine    whether the PCRE2 judged (10.35+) skips a "(?(DEFINE)" group
     * @param bool                                                               $readsNesting   whether the PCRE2 judged reads an extended class that nests a class or a parenthesis whole (all but 10.45 to 10.47)
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
            $this->ended = false;
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
     * Whether the measure of the lookbehind ended before its end, as PCRE2
     * before 10.35 ends it at a "(?(DEFINE)" group opened by a group: what
     * follows is not measured.
     */
    public function ended(): bool
    {
        return $this->ended;
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
     * @param array<int, true> $expanding the groups being measured, by node
     *
     * @return LengthRange
     *
     * @phpstan-impure
     */
    public function of(NodeInterface $node, array $expanding = []): array
    {
        if ($node instanceof SequenceNode) {
            [$min, $max] = [0, 0];
            foreach ($node->children as $child) {
                [$childMin, $childMax] = $this->of($child, $expanding);
                $min += $childMin;
                $max = null === $childMax ? null : $max + $childMax;

                // PCRE stops measuring at the first item it cannot bound.
                if (null === $max || $this->ended) {
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

            $alternatives = $node instanceof AlternationNode ? $node->alternatives : [$node->yes, $node->no];
            [$min, $max] = [\PHP_INT_MAX, 0];
            foreach ($alternatives as $alternative) {
                [$altMin, $altMax] = $this->of($alternative, $expanding);
                $min = min($min, $altMin);
                $max = null === $altMax ? null : max($max, $altMax);

                if (null === $max || $this->ended) {
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

            return $this->of($node->child, $expanding);
        }

        if ($node instanceof QuantifierNode) {
            [$childMin, $childMax] = $this->of($node->node, $expanding);
            [$qMin, $qMax] = self::bounds($node->quantifier);

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

            // Before PCRE2 10.43, a group of variable length stays variable
            // even repeated zero times.
            if (0 === $qMax && $childMin !== $childMax && !$this->variableLength) {
                return [0, $childMax];
            }

            return [$childMin * $qMin, null === $childMax || -1 === $qMax ? null : $childMax * $qMax];
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

            return $measured['length'];
        }

        $this->groupBody($group->child);

        $calledAround = $this->called;
        $this->called = [];
        $recursions = $this->recursions;
        $length = $this->of($group->child, $expanding + [$id => true]);

        if (!$this->groups->hasBranchReset() && $recursions === $this->recursions) {
            $this->measuredGroups[$id] = ['length' => $length, 'calls' => $this->called];
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
     * group among them, the body of that group is measured as the DEFINE group, what follows it in the
     * branch as items of the lookbehind, and the end of the DEFINE group
     * ends the lookbehind: nothing after it is measured.
     *
     * @param array<int, true> $expanding
     *
     * @return LengthRange
     */
    private function defineWithItsFirstItemSkipped(DefineNode $node, array $expanding): array
    {
        $branches = $node->content instanceof AlternationNode ? $node->content->alternatives : [$node->content];
        $items = $branches[0] instanceof SequenceNode ? $branches[0]->children : [$branches[0]];
        $first = $items[0] ?? null;
        $rest = new SequenceNode(\array_slice($items, 1), $node->startPosition, $node->endPosition);

        $count = null;
        if ($first instanceof QuantifierNode) {
            [$qMin, $qMax] = self::bounds($first->quantifier);
            $count = $qMin === $qMax ? $qMin : null;
        }
        $item = $first instanceof QuantifierNode ? $first->node : $first;

        if ($item instanceof GroupNode || $item instanceof DefineNode) {
            [$min, $max] = $this->of($item instanceof GroupNode ? $item->child : $item->content, $expanding);
            [$min, $max] = $first instanceof QuantifierNode
                ? (null === $count || null === $max ? [0, null] : [$min * $count, $max * $count])
                : [$min, $max];
            if (null !== $max) {
                [$restMin, $restMax] = $this->of($rest, $expanding);
                [$min, $max] = [$min + $restMin, null === $restMax ? null : $max + $restMax];
            }
            $this->ended = true;

            return [$min, $max];
        }

        if (null === $item || $item instanceof CharClassNode || ($item instanceof LiteralNode && '' === $item->value)
            || ($first instanceof QuantifierNode && null === $count)) {
            return [0, null];
        }

        // What is left of a run of characters after its first one.
        $head = [0, 0];
        if ($item instanceof LiteralNode && !$first instanceof QuantifierNode) {
            $length = $this->unicode ? mb_strlen($item->value, 'UTF-8') : \strlen($item->value);
            $head = [$length - 1, $length - 1];
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

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

namespace PHPRegex\Parser\Internal;

use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * The length range of a lookbehind branch, the way PCRE measures it: a call
 * or a reference is as long as the group it names, a lookaround is
 * zero-width however often it is repeated, and a call back into a group
 * being measured has no bound. Lengths count bytes, or characters in UTF
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
 */
final class LookbehindLength
{
    private const LOOKAROUNDS = [
        GroupType::LookaheadPositive,
        GroupType::LookaheadNegative,
        GroupType::LookbehindPositive,
        GroupType::LookbehindNegative,
    ];

    /**
     * The length of each group measured, by node, with the groups its
     * measure called.
     *
     * @var array<int, array{length: array{0: int, 1: int|null}, calls: array<int, true>}>
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
     * @param bool                                                               $unicode        whether lengths count characters
     * @param bool                                                               $variableLength whether the PCRE2 judged (10.43+) measures a group repeated zero times as empty
     * @param (\Closure(GroupNode, array<int, true>): void)|null                 $onLookaround   told of a lookaround met inside a branch
     * @param (\Closure(NodeInterface): void)|null                               $onGroupBody    told of each group body measured
     * @param (\Closure(SubroutineNode|BackrefNode, list<GroupNode>): void)|null $onReference    told of each call or reference, with the groups it names
     */
    public function __construct(
        private readonly GroupIndex $groups,
        private readonly bool $unicode,
        private readonly bool $variableLength,
        private readonly ?\Closure $onLookaround = null,
        private readonly ?\Closure $onGroupBody = null,
        private readonly ?\Closure $onReference = null,
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
     * @return array{0: int, 1: int|null}
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
                if (null === $max) {
                    break;
                }
            }

            return [$min, $max];
        }

        if ($node instanceof AlternationNode || $node instanceof ConditionalNode) {
            $alternatives = $node instanceof AlternationNode ? $node->alternatives : [$node->yes, $node->no];
            [$min, $max] = [\PHP_INT_MAX, 0];
            foreach ($alternatives as $alternative) {
                [$altMin, $altMax] = $this->of($alternative, $expanding);
                $min = min($min, $altMin);
                $max = null === $altMax ? null : max($max, $altMax);

                if (null === $max) {
                    break;
                }
            }

            return [$min, $max];
        }

        if ($node instanceof GroupNode) {
            if (\in_array($node->type, self::LOOKAROUNDS, true)) {
                if (null !== $this->onLookaround) {
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
            return [0, 0];
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
     * @return array{0: int, 1: int|null}
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
        if (isset($expanding[$id])) {
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

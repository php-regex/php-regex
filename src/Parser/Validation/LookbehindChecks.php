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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Analysis\LookbehindLength;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PcreVerb;

/**
 * The measure of the lookbehinds of a pattern, with the errors PCRE raises
 * as it measures them: a branch of no bound, one past PCRE's own limit or
 * past max_lookbehind_length, a variable length before PCRE2 10.43, too many
 * branches measured, and the references and calls the measure meets.
 *
 * PCRE measures the lookbehinds in a pass of its own, once the whole
 * pattern is read, following the calls and references of each into the
 * groups they name; LookbehindLength computes the lengths, and this class
 * raises what PCRE raises on the way. The validator decides when a
 * lookbehind is measured and when what this class raises is reported.
 *
 * One instance measures the lookbehinds of one pattern: the branches it
 * measured count against PCRE's budget for all of them.
 *
 * @internal
 *
 * @phpstan-import-type LengthRange from LengthRangeCalculator
 */
final class LookbehindChecks
{
    /**
     * PCRE's own ceiling on a fixed-length lookbehind, the same on every
     * PCRE2 release; max_lookbehind_length only bounds variable ones.
     */
    private const MAX_FIXED_LENGTH = 65535;

    /**
     * How many branches PCRE measures across all the lookbehinds of a
     * pattern before it gives up: each branch of a lookbehind, and each
     * branch of a group it reaches. Without a branch reset it measures a
     * capturing group once; with one, each time it meets it.
     */
    private const MAX_BRANCH_MEASURES = 2001;

    /**
     * Measures a lookbehind branch, telling this class of what it meets.
     */
    private readonly LookbehindLength $length;

    /**
     * How many branches have been measured so far.
     */
    private int $branchMeasures = 0;

    /**
     * The lookbehind being measured, where PCRE reports the measure it gave
     * up.
     */
    private ?GroupNode $measured = null;

    /**
     * Where the text the lookbehind measured counts its positions from
     * starts in the whole pattern.
     */
    private int $offset = 0;

    /**
     * @param GroupIndex                                 $groups         the groups of the pattern,
     *                                                                   which calls and references name
     * @param PcreTarget                                 $target         the PHP and PCRE2 judged
     * @param \Closure(BackrefNode|SubroutineNode): void $checkReference judges a reference or a call
     *                                                                   the measure meets, as the
     *                                                                   validator does
     * @param string|null                                $pattern        the whole pattern, which the
     *                                                                   errors quote
     * @param int                                        $maxLength      max_lookbehind_length: the
     *                                                                   longest variable-length
     *                                                                   lookbehind allowed
     * @param bool                                       $utf            whether lengths count
     *                                                                   characters rather than bytes
     */
    public function __construct(
        private readonly GroupIndex $groups,
        private readonly PcreTarget $target,
        private readonly \Closure $checkReference,
        private readonly ?string $pattern,
        private readonly int $maxLength,
        bool $utf,
    ) {
        $this->length = new LookbehindLength(
            $groups,
            $utf,
            $target->supports(PcreFeature::VariableLengthLookbehind),
            $this->measureNested(...),
            $this->countBranches(...),
            $this->checkMeasuredReference(...),
            $target->supports(PcreFeature::LookbehindInLookaheadMeasured),
            $target->supports(PcreFeature::DefineSkippedInLookbehind),
            !$target->supports(PcreFeature::ExtendedCharClass) || $target->supports(PcreFeature::ExtendedClassBesideLookbehindCompiles),
            !$target->supports(PcreFeature::LookbehindLengthOverflowRefused),
        );
    }

    /**
     * Measure a lookbehind and the lookbehinds its measure meets, raising
     * the first error PCRE raises there.
     *
     * @param int $offset where the text the lookbehind counts its positions
     *                    from starts in the whole pattern
     */
    public function measure(GroupNode $node, int $offset): void
    {
        $this->offset = $offset;
        $this->measureLookbehind($node);
    }

    /**
     * Forget the lookbehinds measured: off a walk of the whole pattern, the
     * nodes measured on an earlier visit may be gone and their ids taken by
     * others.
     */
    public function forget(): void
    {
        $this->length->forget();
    }

    /**
     * Where PCRE reports a lookbehind it cannot take: its "(", or the last
     * letter of the name of "(*plb:...)" and its kin.
     */
    public function errorPosition(GroupNode $node): int
    {
        $start = $node->startPosition;
        $name = null === $this->pattern ? null : PcreVerb::nameBeforeColonAt($this->pattern, $start, PcreVerb::WORD_NAME);
        if (null !== $name) {
            return $start + \strlen($name) - 1;
        }

        return $start;
    }

    /**
     * @param array<int, true>|null $expanding the groups being measured, by
     *                                         node, when the lookbehind sits
     *                                         in one being measured
     */
    private function measureLookbehind(GroupNode $node, ?array $expanding = null): void
    {
        // Only the groups the measure reached through calls are being
        // measured: those around the lookbehind are not.
        $measuring = $expanding ?? [];

        // A lookbehind measured inside another is not measured again, unless
        // it calls a group being measured here and was not there.
        $this->length->once(
            $node,
            $measuring,
            null === $expanding,
            fn () => $this->measureBranches($node, $measuring),
        );
    }

    /**
     * Measure each branch of a lookbehind, raising the errors PCRE raises.
     *
     * @param array<int, true> $measuring the groups being measured, by node
     */
    private function measureBranches(GroupNode $node, array $measuring): void
    {
        // PCRE measures each top-level branch on its own: "(?<=a{300}|b)" is
        // two fixed lengths, "(?<=(?:a{300}|b))" one variable length.
        $branches = $node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child];
        $around = $this->measured;
        $this->measured = $node;
        $lengths = [];

        try {
            foreach ($branches as $branch) {
                $this->countMeasures(1);
                [$length, $unfixed, $tooLong] = $this->length->branch($branch, $measuring);
                $lengths[] = $length;

                // PCRE adds each item to the branch before the repeat after
                // it, and refuses the branch as soon as it passes the limit.
                if (null !== $tooLong) {
                    $this->raise(
                        \sprintf('Lookbehind is too long: PCRE adds each item to the length of its branch before the repeat after it, and refuses a branch past %d characters (%d reached).', self::MAX_FIXED_LENGTH, $tooLong),
                        $this->errorPosition($node),
                        ErrorCode::LookbehindTooLong,
                        'Shorten the lookbehind.',
                    );
                }

                // PCRE stops at the first branch it cannot bound, met in
                // order: a nested lookbehind before it was judged first.
                if (null === $length[1]) {
                    // "\X" matches a whole grapheme cluster, of no bounded length.
                    if ($this->containsGraphemeCluster($branch)) {
                        $this->raise(
                            'Lookbehind is unbounded: \X matches a grapheme cluster of any length.',
                            $this->errorPosition($node),
                            ErrorCode::LookbehindUnbounded,
                            'Match the characters the cluster may hold instead of \X.',
                        );
                    }

                    $this->validateBranchLength($node, $length, true);
                }

                // Up to PCRE2 10.42 the measure ends at the first item of no
                // fixed length, and the lookbehind is refused there.
                if ($unfixed) {
                    $this->refuseVariableLength($node);
                }
            }
        } finally {
            $this->measured = $around;
        }

        // One branch of variable length makes the whole lookbehind variable,
        // and then every branch answers to the variable-length limit.
        $variable = false;
        foreach ($lengths as [$min, $max]) {
            $variable = $variable || $min !== $max;
        }

        foreach ($lengths as $length) {
            $this->validateBranchLength($node, $length, $variable);
        }
    }

    /**
     * @param LengthRange $lengthRange
     */
    private function validateBranchLength(GroupNode $node, array $lengthRange, bool $variable): void
    {
        $max = $lengthRange[1];

        if (null === $max) {
            $culprit = $this->findUnboundedNode($node->child);
            $detail = $culprit instanceof QuantifierNode ? $culprit->quantifier : null;
            $hint = null !== $detail
                ? \sprintf('Use a bounded quantifier instead of "%s".', $detail)
                : 'Ensure the lookbehind has a bounded maximum length.';

            // PCRE reports the lookbehind itself, not what makes it unbounded.
            $this->raise(
                'Lookbehind is unbounded. PCRE requires a bounded maximum length.',
                $this->errorPosition($node),
                ErrorCode::LookbehindUnbounded,
                $hint,
            );
        }

        // A fixed length is only capped by PCRE itself, as the branch is
        // measured; a variable one by max_lookbehind_length, which stands for
        // PCRE2's max_varlookbehind.
        if ($variable && $max > $this->maxLength) {
            $this->raise(
                \sprintf('Lookbehind exceeds the maximum length of %d (max=%d).', $this->maxLength, $max),
                $this->errorPosition($node),
                ErrorCode::LookbehindTooLong,
                'Reduce the lookbehind length, or raise max_lookbehind_length.',
            );
        }
    }

    private function refuseVariableLength(GroupNode $node): never
    {
        $this->raise(
            'Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4.',
            $this->errorPosition($node),
            ErrorCode::LookbehindVariableLengthNotSupported,
            'Give each branch of the lookbehind a fixed length, or target PHP 8.4+.',
        );
    }

    /**
     * PCRE measures a lookbehind it meets inside the one it is measuring, and
     * those a lookahead there holds, before it goes on: the innermost one
     * that has no bound is the one reported.
     *
     * @param array<int, true> $expanding
     */
    private function measureNested(NodeInterface $node, array $expanding): void
    {
        if ($node instanceof GroupNode && \in_array($node->type, [
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
        ], true)) {
            $this->measureLookbehind($node, $expanding);

            return;
        }

        $children = match (true) {
            $node instanceof GroupNode => [$node->child],
            $node instanceof SequenceNode => $node->children,
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof QuantifierNode => [$node->node],
            $node instanceof ConditionalNode => [$node->condition, $node->yes, $node->no],
            $node instanceof DefineNode => [$node->content],
            $node instanceof ScriptRunNode => null === $node->content ? [] : [$node->content],
            default => [],
        };

        foreach ($children as $child) {
            $this->measureNested($child, $expanding);
        }
    }

    /**
     * PCRE checks that the group a call or a reference names exists as it
     * measures the lookbehind, counting relative references from where they
     * stand. A numbered back reference in a pattern with a branch reset it
     * does not measure at all, unless it failed already while being read:
     * one back past the first group, or to group zero.
     *
     * @param list<GroupNode> $groups the groups it names
     */
    private function checkMeasuredReference(SubroutineNode|BackrefNode $node, array $groups): void
    {
        $unmeasured = $node instanceof BackrefNode
            && $this->groups->hasBranchReset()
            && !str_starts_with($node->ref, '\\k')
            && 1 !== LibraryPcre::match('/^\\\\g[{<\']?\s*+(?:-|[+-]?0++(?!\d))/', $node->ref);
        if ([] !== $groups || $unmeasured) {
            return;
        }

        ($this->checkReference)($node);
    }

    /**
     * Count the branches of a group PCRE measures for a lookbehind.
     */
    private function countBranches(NodeInterface $groupBody): void
    {
        $this->countMeasures($groupBody instanceof AlternationNode ? \count($groupBody->alternatives) : 1);
    }

    /**
     * PCRE counts each branch it measures and gives up past its budget at
     * once, at the lookbehind it is measuring: the measure stops there,
     * however much is left of it.
     */
    private function countMeasures(int $branches): void
    {
        $this->branchMeasures += $branches;
        if ($this->branchMeasures > self::MAX_BRANCH_MEASURES && null !== $this->measured) {
            $this->raise(
                'Lookbehind is too complicated: PCRE gives up past 2,001 branches measured for the lookbehinds of a pattern.',
                $this->errorPosition($this->measured),
                ErrorCode::LookbehindTooComplex,
                'Call fewer groups from the lookbehinds, use fewer lookbehinds, or drop the branch reset, which makes PCRE measure a group again each time.',
            );
        }
    }

    /**
     * The item that leaves a lookbehind of no bound, named in the hint: a
     * reference or a call, or an open repeat.
     */
    private function findUnboundedNode(NodeInterface $node): ?NodeInterface
    {
        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return $node;
        }

        if ($node instanceof QuantifierNode) {
            [, $max] = QuantifierCounts::of($node->quantifier);
            if (-1 === $max) {
                return $node;
            }

            return $this->findUnboundedNode($node->node);
        }

        if ($node instanceof GroupNode) {
            return $this->findUnboundedNode($node->child);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                $culprit = $this->findUnboundedNode($alt);
                if (null !== $culprit) {
                    return $culprit;
                }
            }
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $culprit = $this->findUnboundedNode($child);
                if (null !== $culprit) {
                    return $culprit;
                }
            }
        }

        if ($node instanceof ConditionalNode) {
            return $this->findUnboundedNode($node->condition)
                ?? $this->findUnboundedNode($node->yes)
                ?? $this->findUnboundedNode($node->no);
        }

        if ($node instanceof DefineNode) {
            return $this->findUnboundedNode($node->content);
        }

        if ($node instanceof CharClassNode) {
            return $this->findUnboundedNode($node->expression);
        }

        if ($node instanceof RangeNode) {
            return $this->findUnboundedNode($node->start) ?? $this->findUnboundedNode($node->end);
        }

        return null;
    }

    private function containsGraphemeCluster(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof CharTypeNode => 'X' === $node->value,
            $node instanceof SequenceNode => $this->anyContainsGraphemeCluster($node->children),
            $node instanceof AlternationNode => $this->anyContainsGraphemeCluster($node->alternatives),
            // A lookaround adds no length to the lookbehind around it, and a
            // nested lookbehind is checked on its own.
            $node instanceof GroupNode => !\in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true) && $this->containsGraphemeCluster($node->child),
            $node instanceof QuantifierNode => $this->containsGraphemeCluster($node->node),
            $node instanceof ConditionalNode => $this->anyContainsGraphemeCluster([$node->condition, $node->yes, $node->no]),
            default => false,
        };
    }

    /**
     * @param array<NodeInterface> $nodes
     */
    private function anyContainsGraphemeCluster(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($this->containsGraphemeCluster($node)) {
                return true;
            }
        }

        return false;
    }

    private function raise(string $message, int $position, ErrorCode $code, ?string $hint = null): never
    {
        throw new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern, null, $hint);
    }
}

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

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * @internal
 *
 * The conditional groups whose condition reads the same on every path to
 * it, for CaptureShapeAnalyzer: "(?(1)" holds where every path sets group
 * 1 before it, and fails where none does, as before the group closes or
 * after an (*ACCEPT) that always ends the match first; "(?(R)" fails where
 * nothing recurses. A back reference that matched proves its group set,
 * and one to a group no path sets never matches.
 *
 * The tree it gives holds each such conditional as its two arms in a row
 * inside a non-capturing group, the arm the engine never runs under {0}:
 * the groups of both keep their numbers, and that arm reads nothing and
 * sets no group. It folds nothing in a pattern that calls a group or
 * itself, where a conditional may run with other groups set.
 *
 * A state holds the numbers of the groups that may be set ("may") and of
 * those surely set ("sure") where the walk stands; null where no path
 * gets there.
 */
final class ConditionalFolding
{
    /**
     * Whether each conditional that reads the same on every path holds,
     * by object id.
     *
     * @var array<int, bool>
     */
    private array $holds = [];

    /**
     * The states an (*ACCEPT) ends each enclosing assertion with, the
     * innermost last.
     *
     * @var list<list<array{may: array<int, true>, sure: array<int, true>}>>
     */
    private array $accepted = [];

    /**
     * The numbers of the capturing groups open where the walk stands, the
     * innermost last: an (*ACCEPT) closes those its assertion opened.
     *
     * @var list<int>
     */
    private array $open = [];

    /**
     * How many groups were open where each enclosing assertion starts, the
     * innermost last.
     *
     * @var list<int>
     */
    private array $openAtAssertion = [];

    private function __construct(private readonly GroupIndex $groups) {}

    public static function fold(NodeInterface $pattern, GroupIndex $groups): NodeInterface
    {
        if ([] !== $groups->subroutineTargets() || $groups->recursesWhole()) {
            return $pattern;
        }

        $folding = new self($groups);
        $folding->flow($pattern, ['may' => [], 'sure' => []]);

        return [] === $folding->holds ? $pattern : $folding->rebuild($pattern);
    }

    /**
     * The groups that may be set, and those surely set, once the node
     * matched, from those before it; null where no path gets past it.
     *
     * @param array{may: array<int, true>, sure: array<int, true>}|null $state
     *
     * @return array{may: array<int, true>, sure: array<int, true>}|null
     */
    private function flow(NodeInterface $node, ?array $state): ?array
    {
        if (null === $state) {
            return null;
        }

        if ($node instanceof PcreVerbNode && str_starts_with($node->verb, 'ACCEPT')) {
            $this->cut($state);

            return null;
        }

        return match (true) {
            $node instanceof GroupNode => $this->flowGroup($node, $state),
            $node instanceof SequenceNode => array_reduce($node->children, fn (?array $carried, NodeInterface $child): ?array => $this->flow($child, $carried), $state),
            $node instanceof AlternationNode => self::join(array_map(fn (NodeInterface $branch): ?array => $this->flow($branch, $state), $node->alternatives)),
            $node instanceof QuantifierNode => $this->flowRepeat($node, $state),
            $node instanceof ConditionalNode => $this->flowConditional($node, $state),
            $node instanceof BackrefNode => $this->flowReference($node, $state),
            $node instanceof ScriptRunNode && null !== $node->content => $this->flow($node->content, $state),
            // what reads a character or nothing sets no group, and (DEFINE)
            // runs nothing by itself
            default => $state,
        };
    }

    /**
     * @param array{may: array<int, true>, sure: array<int, true>} $state
     *
     * @return array{may: array<int, true>, sure: array<int, true>}|null
     */
    private function flowGroup(GroupNode $node, array $state): ?array
    {
        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $number = $this->groups->numberOf($node);
            if (null !== $number) {
                $this->open[] = $number;
            }
            $out = $this->flow($node->child, $state);
            if (null !== $number) {
                array_pop($this->open);
            }

            return self::set($out, $number);
        }

        if (GroupType::LookaheadNegative === $node->type || GroupType::LookbehindNegative === $node->type) {
            // PCRE discards what a negative assertion captured; the
            // conditionals inside still read what stands before them
            $this->assertion($node->child, $state);

            return $state;
        }

        if (GroupType::LookaheadPositive === $node->type || GroupType::LookbehindPositive === $node->type || GroupType::ScanSubstring === $node->type) {
            return $this->assertion($node->child, $state);
        }

        return $this->flow($node->child, $state);
    }

    /**
     * A repeat that never runs sets nothing; one that runs again starts
     * each iteration with what the earlier ones may have set.
     *
     * @param array{may: array<int, true>, sure: array<int, true>} $state
     *
     * @return array{may: array<int, true>, sure: array<int, true>}|null
     */
    private function flowRepeat(QuantifierNode $node, array $state): ?array
    {
        $min = $node->bounds->min ?? 1;
        $max = null === $node->bounds ? 1 : $node->bounds->max;
        if (0 === $max) {
            return $state;
        }

        $entry = $state;
        if (1 !== $max) {
            $entry['may'] += $this->capturesIn($node->node);
        }

        $out = $this->flow($node->node, $entry);
        if (0 === $min) {
            return self::join([$entry, $out]);
        }

        return $out;
    }

    /**
     * @param array{may: array<int, true>, sure: array<int, true>} $state
     *
     * @return array{may: array<int, true>, sure: array<int, true>}|null
     */
    private function flowConditional(ConditionalNode $node, array $state): ?array
    {
        $condition = $node->condition;
        $holds = null;
        $entry = $state;

        if ($condition instanceof BackrefNode) {
            $numbers = $this->referenced($condition);
            // a duplicate name holds where any group bearing it is set
            if ([] !== array_intersect_key(array_flip($numbers), $state['sure'])) {
                $holds = true;
            } elseif ([] !== $numbers && [] === array_intersect_key(array_flip($numbers), $state['may'])) {
                $holds = false;
            }
        } elseif ($condition instanceof SubroutineNode) {
            // a recursion test, where nothing recurses
            $holds = false;
        } else {
            // an assertion keeps what it captured on either side, as the
            // negative one that fails does; a callout, a version, nothing
            $assertion = $condition instanceof SequenceNode && [] !== $condition->children ? $condition->children[array_key_last($condition->children)] : $condition;
            if ($assertion instanceof GroupNode) {
                $entry = self::join([$state, $this->assertion($assertion->child, $state)]) ?? $state;
            }
        }

        if (null !== $holds) {
            $this->holds[spl_object_id($node)] = $holds;

            return $this->flow($holds ? $node->yes : $node->no, $state);
        }

        return self::join([$this->flow($node->yes, $entry), $this->flow($node->no, $entry)]);
    }

    /**
     * A back reference matches only once its group is set.
     *
     * @param array{may: array<int, true>, sure: array<int, true>} $state
     *
     * @return array{may: array<int, true>, sure: array<int, true>}|null
     */
    private function flowReference(BackrefNode $node, array $state): ?array
    {
        $numbers = $this->referenced($node);
        if ([] === $numbers) {
            return $state;
        }

        if ([] === array_intersect_key(array_flip($numbers), $state['may'])) {
            return null;
        }

        if (1 === \count($numbers)) {
            $state['sure'][$numbers[0]] = true;
        }

        return $state;
    }

    /**
     * An (*ACCEPT) ends the innermost assertion with the state it stands
     * in, or the match: no path goes on past it. PCRE closes every group
     * open around it, and the assertion keeps them:
     * preg_match('/(?=(a(*ACCEPT)))(?(1)(a)|b)/', 'a') writes ["a","a","a"].
     *
     * @param array{may: array<int, true>, sure: array<int, true>} $state
     */
    private function cut(array $state): void
    {
        if ([] === $this->accepted) {
            return;
        }

        $assertion = array_key_last($this->accepted);
        foreach (\array_slice($this->open, $this->openAtAssertion[$assertion]) as $number) {
            $state = self::set($state, $number) ?? $state;
        }
        $this->accepted[$assertion][] = $state;
    }

    /**
     * What an assertion that holds leaves set: its body run to its end, or
     * to an (*ACCEPT) inside.
     *
     * @param array{may: array<int, true>, sure: array<int, true>} $state
     *
     * @return array{may: array<int, true>, sure: array<int, true>}|null
     */
    private function assertion(NodeInterface $body, array $state): ?array
    {
        $this->accepted[] = [];
        $this->openAtAssertion[] = \count($this->open);
        $out = $this->flow($body, $state);
        array_pop($this->openAtAssertion);
        $accepted = array_pop($this->accepted);

        return self::join([$out, ...$accepted]);
    }

    /**
     * The numbers of the groups a reference or a condition names.
     *
     * @return list<int>
     */
    private function referenced(BackrefNode $node): array
    {
        $numbers = [];
        foreach ($this->groups->groupsReferencedBy($node) as $group) {
            $number = $this->groups->numberOf($group);
            if (null !== $number) {
                $numbers[$number] = $number;
            }
        }

        return array_values($numbers);
    }

    /**
     * The numbers of the capturing groups the node holds, itself included.
     *
     * @return array<int, true>
     */
    private function capturesIn(NodeInterface $node): array
    {
        $numbers = [];
        if ($node instanceof GroupNode && null !== ($number = $this->groups->numberOf($node))) {
            $numbers[$number] = true;
        }

        foreach ($node->getChildren() as $child) {
            $numbers += $this->capturesIn($child);
        }

        return $numbers;
    }

    /**
     * @param array{may: array<int, true>, sure: array<int, true>}|null $state
     *
     * @return array{may: array<int, true>, sure: array<int, true>}|null
     */
    private static function set(?array $state, ?int $number): ?array
    {
        if (null === $state || null === $number) {
            return $state;
        }

        $state['may'][$number] = true;
        $state['sure'][$number] = true;

        return $state;
    }

    /**
     * The state after any of the paths: a group may be set where some path
     * may set it, and is surely set where every path sets it.
     *
     * @param list<array{may: array<int, true>, sure: array<int, true>}|null> $states
     *
     * @return array{may: array<int, true>, sure: array<int, true>}|null
     */
    private static function join(array $states): ?array
    {
        $joined = null;
        foreach ($states as $state) {
            if (null === $state) {
                continue;
            }

            $joined = null === $joined ? $state : ['may' => $joined['may'] + $state['may'], 'sure' => array_intersect_key($joined['sure'], $state['sure'])];
        }

        return $joined;
    }

    /**
     * The node with each conditional that reads the same as its two arms
     * in a row, the one the engine never runs under {0}.
     */
    private function rebuild(NodeInterface $node): NodeInterface
    {
        if ($node instanceof ConditionalNode && isset($this->holds[$id = spl_object_id($node)])) {
            $yes = $this->rebuild($node->yes);
            $no = $this->rebuild($node->no);
            $arms = $this->holds[$id] ? [$yes, self::never($no)] : [self::never($yes), $no];

            return new GroupNode(new SequenceNode($arms, $node->startPosition, $node->endPosition), GroupType::NonCapturing, null, null, $node->startPosition, $node->endPosition);
        }

        return match (true) {
            $node instanceof SequenceNode => self::changed($node->children, $children = array_map($this->rebuild(...), $node->children)) ? new SequenceNode($children, $node->startPosition, $node->endPosition) : $node,
            $node instanceof AlternationNode => self::changed($node->alternatives, $alternatives = array_map($this->rebuild(...), $node->alternatives)) ? new AlternationNode($alternatives, $node->startPosition, $node->endPosition) : $node,
            $node instanceof GroupNode => $node->child !== ($child = $this->rebuild($node->child)) ? new GroupNode($child, $node->type, $node->name, $node->flags, $node->startPosition, $node->endPosition, $node->usePythonSyntax, $node->scannedGroups) : $node,
            $node instanceof QuantifierNode => $node->node !== ($repeated = $this->rebuild($node->node)) ? new QuantifierNode($repeated, $node->quantifier, $node->type, $node->startPosition, $node->endPosition) : $node,
            $node instanceof ConditionalNode => self::changed([$node->yes, $node->no], [$yes = $this->rebuild($node->yes), $no = $this->rebuild($node->no)]) ? new ConditionalNode($node->condition, $yes, $no, $node->startPosition, $node->endPosition) : $node,
            default => $node,
        };
    }

    private static function never(NodeInterface $arm): QuantifierNode
    {
        return new QuantifierNode($arm, '{0}', QuantifierType::Greedy, $arm->getStartPosition(), $arm->getEndPosition());
    }

    /**
     * @param list<NodeInterface> $before
     * @param list<NodeInterface> $after
     */
    private static function changed(array $before, array $after): bool
    {
        foreach ($before as $index => $node) {
            if ($node !== $after[$index]) {
                return true;
            }
        }

        return false;
    }
}

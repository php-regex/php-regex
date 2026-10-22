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
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * Reads, from the tree alone, what a successful preg_match() writes into
 * $matches: which groups every match sets, which some matches leave unset,
 * which none sets, and the strings each can hold.
 *
 * Every answer is sound: when the pattern alone cannot tell, a group may be
 * unset, its values are unknown and its facts are false.
 *
 * One alternation holding capturing groups, reached from the root through
 * sequences and groups that neither capture nor repeat, splits the answer
 * into one case per branch, plus one where no branch is taken when that
 * group is optional. Each case reads the pattern in its own option flow.
 *
 *     $shape = (new CaptureShapeAnalyzer())->analyze($parser->parse('/(a)(b)?/'));
 *     $shape->matchShape(); // "array{0: 'a'|'ab', 1: 'a', 2?: 'b'}"
 */
final class CaptureShapeAnalyzer
{
    /**
     * Rises in any release that changes an answer: a fact, or either string
     * CaptureShape writes, the matchShape() one or the matchAllShape() one.
     */
    public const ANALYSIS_VERSION = '2';

    private const MAX_CASES = 16;

    /**
     * Groups that neither capture nor assert: the split alternation may sit
     * inside them.
     */
    private static function PATH_GROUPS(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [GroupType::NonCapturing(), GroupType::Atomic(), GroupType::InlineFlags()];
        }

        return $cache;
    }

    private const MARK_VERBS = ['MARK', 'PRUNE', 'THEN', 'ACCEPT', 'COMMIT', 'F', 'FAIL'];

    private static function TRANSPARENT_GROUPS(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [GroupType::NonCapturing(), GroupType::Atomic(), GroupType::InlineFlags(), GroupType::LookaheadPositive(), GroupType::LookbehindPositive()];
        }

        return $cache;
    }

    private static function NEGATIVE_LOOKAROUNDS(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [GroupType::LookaheadNegative(), GroupType::LookbehindNegative()];
        }

        return $cache;
    }

    /**
     * @var int<1, max>
     */
    private $next = 1;

    /**
     * @var array<int<1, max>, list<array{name: ?string, never: bool, min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool}>>
     */
    private $occurrences = [];

    /**
     * @var list<string>
     */
    private $marks = [];

    /**
     * @var bool
     */
    private $accepts = false;

    /**
     * @var bool
     */
    private $keeps = false;

    /**
     * @var bool
     */
    private $caseless = false;

    /**
     * \d and [[:digit:]] match non-ASCII digits: /u, or (*UCP).
     * @var bool
     */
    private $ucp = false;

    /**
     * A subroutine call or a recursion may run a branch the case left out.
     * @var bool
     */
    private $hasCalls = false;

    /**
     * The case under analysis: the alternation it splits, the branch it
     * takes (null for none: the optional group is skipped), the optional
     * quantifier around it, and the nodes between the root and it.
     *
     * @var array{alternation: AlternationNode, branch: NodeInterface|null, optional: QuantifierNode|null, path: array<int, true>}|null
     */
    private $selection;

    /**
     * Depth of the branches the case leaves out: a verb or \K there never runs.
     * @var int
     */
    private $discardedDepth = 0;

    /**
     * What each node reads, measured once per analysis: a capturing group's
     * body never holds the split alternation, so it reads the same in every
     * case.
     * @var \PHPRegex\Parser\Analysis\CaptureFacts
     */
    private $facts;

    public function __construct()
    {
        $this->facts = new CaptureFacts();
    }

    public function analyze(RegexNode $ast): CaptureShape
    {
        $this->caseless = strpos($ast->flags, 'i') !== false;
        $this->ucp = strpos($ast->flags, 'u') !== false;
        $this->hasCalls = false;
        $this->scan($ast->pattern);
        $this->facts = new CaptureFacts($ast->isUnicode(), $this->caseless, $this->ucp);
        $this->selection = null;

        $merged = $this->shape($ast->pattern);

        $split = $this->split($ast->pattern);
        if (null === $split) {
            return $merged;
        }

        $cases = [];
        foreach (array_merge($split['alternation']->alternatives, null === $split['optional'] ? [] : [null]) as $branch) {
            $this->selection = ['branch' => $branch] + $split;
            $cases[] = $this->shape($ast->pattern);
        }
        $this->selection = null;

        return new CaptureShape($merged->whole, $merged->groups, $merged->marks, $cases);
    }

    /**
     * The shape of every match, or of the matches of the selected case.
     */
    private function shape(NodeInterface $pattern): CaptureShape
    {
        $this->reset();

        $guaranteed = $this->walk($pattern, false);

        ksort($this->occurrences);
        $groups = [];
        foreach ($this->occurrences as $number => $occurrences) {
            $groups[$number] = $this->group($number, $occurrences, isset($guaranteed[$number]));
        }

        // \K and (*ACCEPT) cut the whole match short: only its presence is known.
        if ($this->keeps || $this->accepts) {
            return new CaptureShape(new CaptureGroupShape(0, null, Participation::Always(), 0, null, null), $groups, $this->marks);
        }

        $whole = $this->facts->of($this->rebuild($pattern) ?? self::nothing($pattern));

        return new CaptureShape(new CaptureGroupShape(0, null, Participation::Always(), $whole['min'], $whole['max'], $whole['values'], $whole['nonFalsy'], $whole['digitsOnly']), $groups, $this->marks);
    }

    private function reset(): void
    {
        $this->next = 1;
        $this->occurrences = [];
        $this->marks = [];
        $this->accepts = false;
        $this->keeps = false;
        $this->discardedDepth = 0;
    }

    /**
     * Reads what the whole pattern turns on: caseless matching, UCP, calls.
     */
    private function scan(NodeInterface $node): void
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags() === $node->type && strpos(explode('-', $node->flags ?? '')[0], 'i') !== false) {
            $this->caseless = true;
        }

        if ($node instanceof PcreVerbNode && 'UCP' === $node->verb) {
            $this->ucp = true;
        }

        if ($node instanceof SubroutineNode) {
            $this->hasCalls = true;
        }

        foreach ($node->getChildren() as $child) {
            $this->scan($child);
        }
    }

    /**
     * The one alternation holding capturing groups that the root reaches
     * through sequences and groups that neither capture nor repeat, a `?` on
     * one of them making it optional; null when there is none, more than
     * one, a branch reset in the way, or more cases than MAX_CASES.
     *
     * @return array{alternation: AlternationNode, optional: QuantifierNode|null, path: array<int, true>}|null
     */
    private function split(NodeInterface $pattern): ?array
    {
        $reached = self::reach($pattern, null, []);
        if ($reached['blocked'] || 1 !== \count($reached['found'])) {
            return null;
        }

        $split = $reached['found'][0];
        if (\count($split['alternation']->alternatives) + (null === $split['optional'] ? 0 : 1) > self::MAX_CASES) {
            return null;
        }

        return $split;
    }

    /**
     * The alternations holding capturing groups that the node reaches
     * through sequences and groups that neither capture nor repeat, the
     * search stopping past the second; blocked when a branch reset holding
     * capturing groups is on the way, which prevents the split.
     *
     * @param array<int, true> $path
     *
     * @return array{blocked: bool, found: list<array{alternation: AlternationNode, optional: QuantifierNode|null, path: array<int, true>}>}
     */
    private static function reach(NodeInterface $node, ?QuantifierNode $optional, array $path): array
    {
        $path[spl_object_id($node)] = true;

        if ($node instanceof AlternationNode) {
            return ['blocked' => false, 'found' => self::captures($node) ? [['alternation' => $node, 'optional' => $optional, 'path' => $path]] : []];
        }

        if ($node instanceof GroupNode && GroupType::BranchReset() === $node->type) {
            return ['blocked' => self::captures($node), 'found' => []];
        }

        if ($node instanceof GroupNode && \in_array($node->type, self::PATH_GROUPS(), true)) {
            return self::reach($node->child, $optional, $path);
        }

        if (null === $optional && $node instanceof QuantifierNode && $node->node instanceof GroupNode && \in_array($node->node->type, self::PATH_GROUPS(), true)) {
            $bounds = QuantifierBounds::parse($node->quantifier);
            if (null !== $bounds && 0 === $bounds->min && 1 === $bounds->max) {
                return self::reach($node->node, $node, $path);
            }
        }

        $found = [];
        foreach ($node instanceof SequenceNode ? $node->children : [] as $child) {
            $reached = self::reach($child, $optional, $path);
            if ($reached['blocked']) {
                return $reached;
            }

            $found = array_merge($found, $reached['found']);
            if (\count($found) > 1) {
                break;
            }
        }

        return ['blocked' => false, 'found' => $found];
    }

    private static function captures(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && (GroupType::Capturing() === $node->type || GroupType::Named() === $node->type)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::captures($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The node as the selected case reads it: the split alternation replaced
     * by the branch the case takes, its optional quantifier by its group, or
     * dropped (null) when the case takes no branch. Only the nodes between
     * the root and the alternation are rebuilt.
     */
    private function rebuild(NodeInterface $node): ?NodeInterface
    {
        $selection = $this->selection;
        if (null === $selection || !isset($selection['path'][spl_object_id($node)])) {
            return $node;
        }

        if ($node === $selection['optional']) {
            return null === $selection['branch'] ? null : $this->rebuild($selection['optional']->node);
        }

        if ($node === $selection['alternation']) {
            return $selection['branch'];
        }

        switch (true) {
            case $node instanceof SequenceNode:
                return new SequenceNode(array_filter(array_map(\Closure::fromCallable([$this, 'rebuild']), $node->children), static function (?NodeInterface $child): bool {
                    return null !== $child;
                }), $node->startPosition, $node->endPosition);
            case $node instanceof GroupNode:
                return new GroupNode($this->rebuild($node->child) ?? self::nothing($node->child), $node->type, $node->name, $node->flags, $node->startPosition, $node->endPosition, $node->usePythonSyntax, $node->scannedGroups);
            default:
                return $node;
        }
    }

    /**
     * An empty sequence where the node stood: what a case reads in place of
     * the optional group it skips.
     */
    private static function nothing(NodeInterface $node): SequenceNode
    {
        return new SequenceNode([], $node->getStartPosition(), $node->getStartPosition());
    }

    /**
     * @param int<1, max>                                                                                                                   $number
     * @param list<array{name: ?string, never: bool, min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool}> $occurrences
     */
    private function group(int $number, array $occurrences, bool $guaranteed): CaptureGroupShape
    {
        // A branch reset number takes the name any of its branches gives:
        // PCRE refuses two different names for one number.
        $name = null;
        foreach ($occurrences as $occurrence) {
            $name = $name ?? $occurrence['name'];
        }

        $set = array_values(array_filter($occurrences, static function (array $occurrence): bool {
            return !$occurrence['never'];
        }));

        switch (true) {
            case [] === $set:
                $participation = Participation::Never();
                break;
            case $guaranteed && !$this->accepts:
                $participation = Participation::Always();
                break;
            default:
                $participation = Participation::MayBeUnset();
                break;
        }

        $values = [];
        $min = null;
        $max = 0;
        $nonFalsy = [] !== $set;
        $digitsOnly = [] !== $set;
        foreach ([] === $set ? $occurrences : $set as $occurrence) {
            $values = null === $values || null === $occurrence['values'] ? null : array_merge(is_array($values) ? $values : iterator_to_array($values), is_array($occurrence['values']) ? $occurrence['values'] : iterator_to_array($occurrence['values']));
            $min = null === $min ? $occurrence['min'] : min($min, $occurrence['min']);
            $max = null === $max || null === $occurrence['max'] ? null : max($max, $occurrence['max']);
            $nonFalsy = $nonFalsy && $occurrence['nonFalsy'];
            $digitsOnly = $digitsOnly && $occurrence['digitsOnly'];
        }

        if (null !== $values) {
            $values = array_values(array_unique($values));
            $values = \count($values) > CaptureFacts::MAX_VALUES ? null : $values;
        }

        if ($this->accepts) {
            // A group (*ACCEPT) leaves open holds what it read so far.
            return new CaptureGroupShape($number, $name, $participation, 0, null, null);
        }

        return new CaptureGroupShape($number, $name, $participation, $min ?? 0, $max, [] === $set ? null : $values, $nonFalsy, $digitsOnly);
    }

    /**
     * Walks the tree in the order PCRE numbers groups, and returns the
     * numbers of the groups set whenever the node matches.
     *
     * @return array<int, true>
     */
    private function walk(NodeInterface $node, bool $never): array
    {
        $selection = $this->selection;
        if (null !== $selection && ($node === $selection['alternation'] || $node === $selection['optional'])) {
            return $this->walkSelected($node, $selection, $never);
        }

        // A verb or \K in a branch the case leaves out never runs, unless a call runs it.
        $runs = 0 === $this->discardedDepth || $this->hasCalls;

        if ($runs && $node instanceof PcreVerbNode) {
            $this->readVerb($node);
        }

        if ($runs && $node instanceof KeepNode) {
            $this->keeps = true;
        }

        if ($node instanceof DefineNode) {
            $this->walk($node->content, true);
        }

        switch (true) {
            case $node instanceof GroupNode:
                return $this->walkGroup($node, $never);
            case $node instanceof AlternationNode:
                return self::intersect(array_map(function (NodeInterface $branch) use ($never): array {
                    return $this->walk($branch, $never);
                }, $node->alternatives));
            case $node instanceof SequenceNode:
                return array_replace([], ...array_map(function (NodeInterface $child) use ($never): array {
                    return $this->walk($child, $never);
                }, $node->children));
            case $node instanceof QuantifierNode:
                return $this->walkQuantifier($node, $never);
            case $node instanceof ConditionalNode:
                return $this->walkConditional($node, $never);
            case $node instanceof ScriptRunNode && null !== $node->content:
                return $this->walk($node->content, $never);
            default:
                return [];
        }
    }

    /**
     * Walks the split alternation, or the optional quantifier around it, in
     * the selected case: the branch it takes as usual, the others numbered
     * but never set.
     *
     * @param array{alternation: AlternationNode, branch: NodeInterface|null, optional: QuantifierNode|null, path: array<int, true>} $selection
     *
     * @return array<int, true>
     */
    private function walkSelected(NodeInterface $node, array $selection, bool $never): array
    {
        $taken = $node === $selection['optional'] && null !== $selection['branch'] ? $selection['optional']->node : $selection['branch'];
        $guaranteed = [];

        // In order: the branches before the one taken number their groups first.
        foreach ($node instanceof AlternationNode ? $node->alternatives : $node->getChildren() as $branch) {
            if ($branch === $taken) {
                $guaranteed = $this->walk($branch, $never);

                continue;
            }

            $this->discardedDepth++;
            $this->walk($branch, true);
            $this->discardedDepth--;
        }

        return $guaranteed;
    }

    /**
     * @return array<int, true>
     */
    private function walkGroup(GroupNode $node, bool $never): array
    {
        if (GroupType::BranchReset() === $node->type) {
            // Each branch numbers its groups from the same base.
            $base = $this->next;
            $end = $base;
            $sets = [];
            foreach ($node->child instanceof AlternationNode ? $node->child->alternatives : [$node->child] as $branch) {
                $this->next = $base;
                $sets[] = $this->walk($branch, $never);
                $end = max($end, $this->next);
            }
            $this->next = $end;

            return self::intersect($sets);
        }

        if (GroupType::Capturing() === $node->type || GroupType::Named() === $node->type) {
            $number = $this->next++;
            if ($never) {
                // A group no match sets holds no value: its lengths are all it says.
                [$min, $max] = $this->facts->lengths($node->child);
                $read = ['min' => $min, 'max' => $max, 'values' => null, 'nonFalsy' => false, 'digitsOnly' => false];
            } else {
                $read = $this->facts->of($node->child);
            }
            $this->occurrences[$number][] = ['name' => $node->name, 'never' => $never] + $read;

            return [$number => true] + $this->walk($node->child, $never);
        }

        if (\in_array($node->type, self::NEGATIVE_LOOKAROUNDS(), true)) {
            // PCRE discards what a negative assertion captured.
            $this->walk($node->child, true);

            return [];
        }

        $guaranteed = $this->walk($node->child, $never);

        return \in_array($node->type, self::TRANSPARENT_GROUPS(), true) ? $guaranteed : [];
    }

    /**
     * @return array<int, true>
     */
    private function walkQuantifier(QuantifierNode $node, bool $never): array
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null !== $bounds && 0 === $bounds->max) {
            $this->walk($node->node, true);

            return [];
        }

        $guaranteed = $this->walk($node->node, $never);

        return null !== $bounds && $bounds->min > 0 ? $guaranteed : [];
    }

    /**
     * @return array<int, true>
     */
    private function walkConditional(ConditionalNode $node, bool $never): array
    {
        // What the condition captures is set only when it holds.
        $this->walk($node->condition, $never);

        return self::intersect([$this->walk($node->yes, $never), $this->walk($node->no, $never)]);
    }

    private function readVerb(PcreVerbNode $node): void
    {
        [$verb, $name] = array_pad(explode(':', $node->verb, 2), 2, null);
        $verb = '' === $verb ? 'MARK' : $verb;

        if ('ACCEPT' === $verb) {
            $this->accepts = true;
        }

        if (null !== $name && '' !== $name && \in_array($verb, self::MARK_VERBS, true) && !\in_array($name, $this->marks, true)) {
            $this->marks[] = $name;
        }
    }

    /**
     * @param array<array<int, true>> $sets
     *
     * @return array<int, true>
     */
    private static function intersect(array $sets): array
    {
        return [] === $sets ? [] : array_intersect_key(...$sets);
    }
}

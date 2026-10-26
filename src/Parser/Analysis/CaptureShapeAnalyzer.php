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
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
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
 * group is optional. Without such an alternation, one conditional group
 * holding capturing groups splits it the same way, a case per branch; and
 * without either, one optional group holding two capturing groups or more
 * splits it into the case where the group runs and the case where it is
 * skipped. Each case reads the pattern in its own option flow.
 *
 *     $shape = (new CaptureShapeAnalyzer())->analyze($parser->parse('/(a)(b)?/'));
 *     $shape->matchShape(); // "array{0: 'a'|'ab', 1: 'a', 2?: 'b'}"
 *
 * @phpstan-import-type Facts from CaptureFacts
 */
final class CaptureShapeAnalyzer
{
    /**
     * Rises in any release that changes an answer: a fact, or either string
     * CaptureShape writes, the matchShape() one or the matchAllShape() one.
     */
    public const ANALYSIS_VERSION = '12';

    private const MAX_CASES = 16;

    /**
     * Past this many arms, a group keeps only the join of their facts.
     */
    private const MAX_ARMS = 8;

    /**
     * Groups that neither capture nor assert: the split node may sit
     * inside them.
     */
    private const PATH_GROUPS = [GroupType::NonCapturing, GroupType::Atomic, GroupType::InlineFlags];

    private const MARK_VERBS = ['MARK', 'PRUNE', 'THEN', 'ACCEPT', 'COMMIT', 'F', 'FAIL'];

    private const TRANSPARENT_GROUPS = [GroupType::NonCapturing, GroupType::Atomic, GroupType::InlineFlags, GroupType::LookaheadPositive, GroupType::LookbehindPositive];

    private const NEGATIVE_LOOKAROUNDS = [GroupType::LookaheadNegative, GroupType::LookbehindNegative];

    private const POSITIVE_LOOKAROUNDS = [GroupType::LookaheadPositive, GroupType::LookbehindPositive];

    /**
     * What a pattern splits on, in the order the analyzer tries them: the
     * first kind reached exactly once, within MAX_CASES, is the split.
     */
    private const SPLIT_KINDS = [AlternationNode::class, ConditionalNode::class, QuantifierNode::class];

    /**
     * @var int<1, max>
     */
    private int $next = 1;

    /**
     * @var array<int<1, max>, list<array{name: ?string, never: bool, after_accept: bool, min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool, arms: list<Facts>}>>
     */
    private array $occurrences = [];

    /**
     * @var list<string>
     */
    private array $marks = [];

    private bool $accepts = false;

    private bool $keeps = false;

    /**
     * \d and [[:digit:]] match non-ASCII digits: /u, or (*UCP).
     */
    private bool $ucp = false;

    /**
     * A subroutine call or a recursion may run a branch the case left out.
     */
    private bool $hasCalls = false;

    /**
     * The case under analysis: the node it splits (an alternation, a
     * conditional group, or an optional group alone), the branch it takes
     * (null for none: the optional group is skipped), the optional
     * quantifier around it, and the nodes between the root and it.
     *
     * @var array{choice: AlternationNode|ConditionalNode|QuantifierNode, branch: NodeInterface|null, optional: QuantifierNode|null, path: array<int, true>}|null
     */
    private ?array $selection = null;

    /**
     * Depth of the branches the case leaves out: a verb or \K there never runs.
     */
    private int $discardedDepth = 0;

    /**
     * What each node reads, measured once per analysis: a capturing group's
     * body never holds the split node, so it reads the same in every
     * case.
     */
    private CaptureFacts $facts;

    private GroupIndex $groups;

    public function __construct()
    {
        $this->facts = new CaptureFacts();
        $this->groups = GroupIndex::empty();
    }

    public function analyze(RegexNode $ast): CaptureShape
    {
        $this->ucp = str_contains($ast->flags, 'u');
        $this->hasCalls = false;
        $this->scan($ast->pattern);
        $this->groups = GroupIndex::of($ast->pattern);
        $this->facts = new CaptureFacts($ast->isUnicode(), $this->ucp, $this->groups);
        $this->collectCaseless($ast->pattern, str_contains($ast->flags, 'i'));
        $this->selection = null;

        $merged = $this->shape($ast->pattern);

        $split = $this->split($ast->pattern);
        if (null === $split) {
            return $merged;
        }

        $cases = [];
        foreach ([...self::branches($split['choice']), ...(null === $split['optional'] ? [] : [null])] as $branch) {
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
        $this->collectAtStart($pattern);
        $this->collectAtEnd($pattern);

        $guaranteed = $this->walk($pattern, false);

        ksort($this->occurrences);
        $groups = [];
        foreach ($this->occurrences as $number => $occurrences) {
            $groups[$number] = $this->group($number, $occurrences, isset($guaranteed[$number]));
        }

        // \K and (*ACCEPT) cut the whole match short: only its presence is known.
        if ($this->keeps || $this->accepts) {
            return new CaptureShape(new CaptureGroupShape(0, null, Participation::Always, 0, null, null), $groups, $this->marks);
        }

        $wholePattern = $this->rebuild($pattern) ?? self::nothing($pattern);
        $this->collectAtStart($wholePattern);
        $this->collectAtEnd($wholePattern);
        $whole = $this->facts->of($wholePattern);

        // a zero-width first branch wins every single match and not the
        // scan: the whole a scan reads joins the later branches' values
        $scanWhole = null;
        if ([''] === $whole['values']) {
            $scan = $this->facts->scanValues($wholePattern);
            if ($scan !== ['']) {
                $scanWhole = new CaptureGroupShape(0, null, Participation::Always, $whole['min'], $whole['max'], $scan, $whole['nonFalsy'], $whole['digitsOnly']);
            }
        }

        // read as a group's are, with no zero-width first branch winning:
        // the arms hold for every match of a scan too
        $arms = self::arms(0, null, Participation::Always, $whole['values'], $whole['digitsOnly'], $this->facts->arms($wholePattern, true));

        return new CaptureShape(new CaptureGroupShape(0, null, Participation::Always, $whole['min'], $whole['max'], $whole['values'], $whole['nonFalsy'], $whole['digitsOnly'], $arms), $groups, $this->marks, scanWhole: $scanWhole);
    }

    /**
     * Marks the nodes the scan reaches before any part of the pattern may
     * have read a character: everything below a node, and past a sibling
     * only while every earlier sibling reads nothing.
     */
    private function collectAtStart(NodeInterface $node): void
    {
        $this->facts->markAtStart($node);

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $this->collectAtStart($child);
                if (0 !== ($this->facts->lengths($child)[1] ?? 1)) {
                    return;
                }
            }

            return;
        }

        foreach ($node->getChildren() as $child) {
            $this->collectAtStart($child);
        }
    }

    /**
     * Marks the nodes nothing in the pattern follows: the last child of a
     * sequence, every branch of an alternation, the child of a group. A
     * repeated node may be followed by its own next iteration.
     */
    private function collectAtEnd(NodeInterface $node): void
    {
        $this->facts->markAtEnd($node);

        if ($node instanceof SequenceNode) {
            if ([] !== $node->children) {
                $this->collectAtEnd($node->children[array_key_last($node->children)]);
            }

            return;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                $this->collectAtEnd($alternative);
            }

            return;
        }

        if ($node instanceof GroupNode) {
            $this->collectAtEnd($node->child);
        }
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
     * Reads what the whole pattern turns on: UCP, calls.
     */
    private function scan(NodeInterface $node): void
    {
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
     * Marks the characters and classes the engine reads caselessly, and
     * returns whether it reads caselessly after the node. As in PCRE, a bare
     * "(?i)" holds to the end of its group, the following branches
     * included; a scoped "(?i:...)" holds inside; any group, like a
     * conditional, gives back at its end what held at its start.
     */
    private function collectCaseless(NodeInterface $node, bool $caseless): bool
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && null !== $node->flags) {
            $inside = self::caselessAfter($caseless, $node->flags);
            // a bare "(?i)" spans its flags and three bytes, a scoped "(?i:)" one more
            if ($node->getEndPosition() - $node->getStartPosition() === \strlen($node->flags) + 3) {
                return $inside;
            }

            $this->collectCaseless($node->child, $inside);

            return $caseless;
        }

        if ($node instanceof LiteralNode || $node instanceof CharLiteralNode || $node instanceof CharClassNode) {
            if ($caseless) {
                $this->facts->markCaseless($node);
            }

            return $caseless;
        }

        $carried = $caseless;
        foreach ($node->getChildren() as $child) {
            $carried = $this->collectCaseless($child, $carried);
        }

        return $node instanceof SequenceNode || $node instanceof AlternationNode ? $carried : $caseless;
    }

    /**
     * Whether caseless matching holds after inline flags such as "i", "-i",
     * "^" or "i-i", when it held before them.
     */
    private static function caselessAfter(bool $caseless, string $flags): bool
    {
        if (str_starts_with($flags, '^')) {
            $caseless = false;
            $flags = substr($flags, 1);
        }

        [$on, $off] = explode('-', $flags, 2) + [1 => ''];

        return !str_contains($off, 'i') && ($caseless || str_contains($on, 'i'));
    }

    /**
     * What the root splits on: the one alternation holding capturing groups
     * that it reaches through sequences and groups that neither capture nor
     * repeat, a `?` on one of them making it optional; else the one
     * conditional group holding capturing groups reached the same way; else
     * the one optional group holding two capturing groups or more. Null when
     * no kind is reached exactly once within MAX_CASES, or when a branch
     * reset is in the way.
     *
     * @return array{choice: AlternationNode|ConditionalNode|QuantifierNode, optional: QuantifierNode|null, path: array<int, true>}|null
     */
    private function split(NodeInterface $pattern): ?array
    {
        $reached = self::reach($pattern, null, []);
        if ($reached['blocked']) {
            return null;
        }

        foreach (self::SPLIT_KINDS as $kind) {
            $found = [];
            foreach ($reached['found'] as $split) {
                if ($split['choice'] instanceof $kind) {
                    $found[] = $split;
                }
            }

            if (1 === \count($found) && \count(self::branches($found[0]['choice'])) + (null === $found[0]['optional'] ? 0 : 1) <= self::MAX_CASES) {
                return $found[0];
            }
        }

        return null;
    }

    /**
     * The nodes holding capturing groups that the node reaches through
     * sequences and groups that neither capture nor repeat: alternations,
     * conditional groups, and optional groups holding two capturing groups
     * or more, those holding neither an alternation nor a conditional group
     * reached the same way; blocked when a branch reset holding capturing
     * groups is on the way, which prevents the split.
     *
     * @param array<int, true> $path
     *
     * @return array{blocked: bool, found: list<array{choice: AlternationNode|ConditionalNode|QuantifierNode, optional: QuantifierNode|null, path: array<int, true>}>}
     */
    private static function reach(NodeInterface $node, ?QuantifierNode $optional, array $path): array
    {
        $path[spl_object_id($node)] = true;

        if ($node instanceof AlternationNode || $node instanceof ConditionalNode) {
            return ['blocked' => false, 'found' => self::captures($node) ? [['choice' => $node, 'optional' => $optional, 'path' => $path]] : []];
        }

        if ($node instanceof GroupNode && GroupType::BranchReset === $node->type) {
            return ['blocked' => self::captures($node), 'found' => []];
        }

        if ($node instanceof GroupNode && \in_array($node->type, self::PATH_GROUPS, true)) {
            return self::reach($node->child, $optional, $path);
        }

        if (null === $optional && $node instanceof QuantifierNode && $node->node instanceof GroupNode && self::isOptional($node)) {
            if (\in_array($node->node->type, self::PATH_GROUPS, true)) {
                $reached = self::reach($node->node, $node, $path);
                if ($reached['blocked'] || [] !== $reached['found']) {
                    return $reached;
                }
            }

            // Run or skipped, the group sets its groups together: split when it holds more than one.
            $splits = \in_array($node->node->type, [...self::PATH_GROUPS, GroupType::Capturing, GroupType::Named], true) && self::countCaptures($node->node) > 1;

            return ['blocked' => false, 'found' => $splits ? [['choice' => $node, 'optional' => $node, 'path' => $path]] : []];
        }

        $found = [];
        foreach ($node instanceof SequenceNode ? $node->children : [] as $child) {
            $reached = self::reach($child, $optional, $path);
            if ($reached['blocked']) {
                return $reached;
            }

            $found = [...$found, ...$reached['found']];
        }

        return ['blocked' => false, 'found' => $found];
    }

    /**
     * The branches a split node gives a case each: the alternatives of an
     * alternation, the two branches of a conditional group, the group of
     * an optional quantifier (the case where it runs).
     *
     * @return list<NodeInterface>
     */
    private static function branches(AlternationNode|ConditionalNode|QuantifierNode $node): array
    {
        return match (true) {
            $node instanceof AlternationNode => $node->alternatives,
            $node instanceof ConditionalNode => [$node->yes, $node->no],
            default => [$node->node],
        };
    }

    private static function isOptional(QuantifierNode $node): bool
    {
        $bounds = QuantifierBounds::parse($node->quantifier);

        return null !== $bounds && 0 === $bounds->min && 1 === $bounds->max;
    }

    private static function countCaptures(NodeInterface $node): int
    {
        $count = $node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type) ? 1 : 0;
        foreach ($node->getChildren() as $child) {
            $count += self::countCaptures($child);
        }

        return $count;
    }

    private static function captures(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type)) {
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
     * The node as the selected case reads it: the split node replaced by the
     * branch the case takes (a conditional group by its branch alone, its
     * condition left out, which only widens what the case reads), its
     * optional quantifier by its group, or dropped (null) when the case
     * takes no branch. Only the nodes between the root and the split node
     * are rebuilt.
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

        if ($node === $selection['choice']) {
            return $selection['branch'];
        }

        // Any other node on the path is a sequence or a group that neither captures nor repeats.
        return match (true) {
            $node instanceof SequenceNode => new SequenceNode(array_filter(array_map($this->rebuild(...), $node->children), static fn (?NodeInterface $child): bool => null !== $child), $node->startPosition, $node->endPosition),
            $node instanceof GroupNode => new GroupNode($this->rebuild($node->child) ?? self::nothing($node->child), $node->type, $node->name, $node->flags, $node->startPosition, $node->endPosition, $node->usePythonSyntax, $node->scannedGroups),
            default => $node,
        };
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
     * @param int<1, max>                                                                                                                                                          $number
     * @param list<array{name: ?string, never: bool, after_accept: bool, min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool, arms: list<Facts>}> $occurrences
     */
    private function group(int $number, array $occurrences, bool $guaranteed): CaptureGroupShape
    {
        // A branch reset number takes the name any of its branches gives:
        // PCRE refuses two different names for one number.
        $name = null;
        foreach ($occurrences as $occurrence) {
            $name ??= $occurrence['name'];
        }

        $set = array_values(array_filter($occurrences, static fn (array $occurrence): bool => false === $occurrence['never']));

        $beforeAnyAccept = [] !== $set && !\in_array(true, array_map(
            static fn (array $occurrence): bool => $occurrence['after_accept'],
            $set,
        ), true);

        $participation = match (true) {
            [] === $set => Participation::Never,
            $guaranteed && $beforeAnyAccept => Participation::Always,
            default => Participation::MayBeUnset,
        };

        $values = [];
        $min = null;
        $max = 0;
        $nonFalsy = [] !== $set;
        $digitsOnly = [] !== $set;
        foreach ([] === $set ? $occurrences : $set as $occurrence) {
            $values = null === $values || null === $occurrence['values'] ? null : [...$values, ...$occurrence['values']];
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

        $arms = [];
        foreach ($set as $occurrence) {
            $arms = [...$arms, ...$occurrence['arms']];
        }

        return new CaptureGroupShape($number, $name, $participation, $min ?? 0, $max, [] === $set ? null : $values, $nonFalsy, $digitsOnly, self::arms($number, $name, $participation, $values, $digitsOnly, $arms));
    }

    /**
     * The shape of each arm, when their join loses what they say: it holds
     * no value set and no digit fact, while an arm holds one of them.
     * Arms that read the same count once; past MAX_ARMS, the join stands.
     *
     * @param list<string>|null $values     the values of the join
     * @param bool              $digitsOnly the digit fact of the join
     * @param list<Facts>       $arms
     *
     * @return list<CaptureGroupShape>
     */
    private static function arms(int $number, ?string $name, Participation $participation, ?array $values, bool $digitsOnly, array $arms): array
    {
        if (null !== $values || $digitsOnly) {
            return [];
        }

        $shapes = [];
        $telling = false;
        foreach ($arms as $arm) {
            $telling = $telling || null !== $arm['values'] || $arm['digitsOnly'];
            $shapes[serialize([$arm['min'], $arm['max'], $arm['values'], $arm['nonFalsy'], $arm['digitsOnly']])] = new CaptureGroupShape($number, $name, $participation, $arm['min'], $arm['max'], $arm['values'], $arm['nonFalsy'], $arm['digitsOnly']);
        }

        return $telling && \count($shapes) > 1 && \count($shapes) <= self::MAX_ARMS ? array_values($shapes) : [];
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
        if (null !== $selection && ($node === $selection['choice'] || $node === $selection['optional'])) {
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

        return match (true) {
            $node instanceof GroupNode => $this->walkGroup($node, $never),
            $node instanceof AlternationNode => self::intersect(array_map(fn (NodeInterface $branch): array => $this->walk($branch, $never), $node->alternatives)),
            $node instanceof SequenceNode => array_replace([], ...array_map(fn (NodeInterface $child): array => $this->walk($child, $never), $node->children)),
            $node instanceof QuantifierNode => $this->walkQuantifier($node, $never),
            $node instanceof ConditionalNode => $this->walkConditional($node, $never),
            $node instanceof ScriptRunNode && null !== $node->content => $this->walk($node->content, $never),
            $node instanceof SubroutineNode => $this->walkReturned($node, $never),
            default => [],
        };
    }

    /**
     * Walks the split node, or the optional quantifier around it, in the
     * selected case: the branch it takes as usual, the others numbered but
     * never set. The condition of a conditional group runs in every case,
     * and holds in the case of its yes branch.
     *
     * @param array{choice: AlternationNode|ConditionalNode|QuantifierNode, branch: NodeInterface|null, optional: QuantifierNode|null, path: array<int, true>} $selection
     *
     * @return array<int, true>
     */
    private function walkSelected(AlternationNode|ConditionalNode|QuantifierNode $node, array $selection, bool $never): array
    {
        $taken = $node === $selection['optional'] && null !== $selection['branch'] ? $selection['optional']->node : $selection['branch'];
        $guaranteed = $node instanceof ConditionalNode ? $this->walkCondition($node, $never, $node->yes === $taken) : [];

        // In order: the branches before the one taken number their groups first.
        foreach (self::branches($node) as $branch) {
            if ($branch === $taken) {
                $guaranteed += $this->walk($branch, $never);

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
        if (GroupType::BranchReset === $node->type) {
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

        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $number = $this->next++;
            if ($never) {
                // A group no match sets holds no value: its lengths are all it says.
                [$min, $max] = $this->facts->lengths($node->child);
                $read = ['min' => $min, 'max' => $max, 'values' => null, 'nonFalsy' => false, 'digitsOnly' => false, 'arms' => []];
            } else {
                $read = $this->facts->of($node->child, true);
                // each way the group is set: each branch of what it reads, or the group as a whole
                $read += ['arms' => $this->facts->arms($node->child, true) ?: [$read]];
            }
            $this->occurrences[$number][] = ['name' => $node->name, 'never' => $never, 'after_accept' => $this->accepts] + $read;

            return [$number => true] + $this->walk($node->child, $never);
        }

        if (\in_array($node->type, self::NEGATIVE_LOOKAROUNDS, true)) {
            // PCRE discards what a negative assertion captured.
            $this->walk($node->child, true);

            return [];
        }

        $guaranteed = $this->walk($node->child, $never);

        return \in_array($node->type, self::TRANSPARENT_GROUPS, true) ? $guaranteed : [];
    }

    /**
     * A call that returns groups, "(?1(2))" in PCRE2 10.47, sets them as the
     * group it runs does: in /(?(DEFINE)((Sat)urday))(?1(2)),\2/ group 2
     * holds 'Sat'. No match is sure to set them.
     *
     * @return array<int, true>
     */
    private function walkReturned(SubroutineNode $node, bool $never): array
    {
        foreach ($this->groups->groupsReturnedBy($node) as ['number' => $number, 'group' => $group]) {
            $read = $this->facts->of($group->child, true);
            $read += ['arms' => $this->facts->arms($group->child, true) ?: [$read]];
            $this->occurrences[$number][] = ['name' => $group->name, 'never' => $never, 'after_accept' => $this->accepts] + $read;
        }

        return [];
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
        $this->walkCondition($node, $never, null);

        return self::intersect([$this->walk($node->yes, $never), $this->walk($node->no, $never)]);
    }

    /**
     * Walks the condition of a conditional group, read as holding, failing,
     * or either (null), and returns the groups it sets whenever it reads so.
     * An assertion keeps what it captured when its body matched: a positive
     * one where it holds, a negative one where it fails, as in
     * preg_match('/(?(?!(a))x|a)/', 'a') -> ["a","a"].
     *
     * @return array<int, true>
     */
    private function walkCondition(ConditionalNode $node, bool $never, ?bool $holds): array
    {
        $assertion = self::assertionOf($node->condition);
        if (null === $assertion) {
            $this->walk($node->condition, $never);

            return [];
        }

        if (null === $holds) {
            $this->walk($assertion->child, $never);

            return [];
        }

        if ($holds !== \in_array($assertion->type, self::POSITIVE_LOOKAROUNDS, true)) {
            // Its body did not match: PCRE discards what it captured.
            $this->walk($assertion->child, true);

            return [];
        }

        return $this->walk($assertion->child, $never);
    }

    /**
     * The assertion a condition tests, after the callouts the parser lets
     * come before it, as in (?(?C1)(?=a)...); null for a condition that is
     * no assertion, such as a group number.
     */
    private static function assertionOf(NodeInterface $condition): ?GroupNode
    {
        $assertion = $condition instanceof SequenceNode && [] !== $condition->children ? $condition->children[array_key_last($condition->children)] : $condition;

        return $assertion instanceof GroupNode && \in_array($assertion->type, [...self::POSITIVE_LOOKAROUNDS, ...self::NEGATIVE_LOOKAROUNDS], true) ? $assertion : null;
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

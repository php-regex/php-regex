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
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
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
 * sequences and groups that do not repeat, splits the answer
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
    public const ANALYSIS_VERSION = '13';

    private const MAX_CASES = 16;

    /**
     * Past this many arms, a group keeps only the join of their facts.
     */
    private const MAX_ARMS = 8;

    /**
     * Groups that neither capture nor assert: the split node may sit
     * inside them.
     */
    /**
     * Groups a split reaches through: they run whenever their parent does,
     * and a capturing one is set in every case.
     */
    private const PATH_GROUPS = [GroupType::NonCapturing, GroupType::Atomic, GroupType::InlineFlags, GroupType::Capturing, GroupType::Named];

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
     * Groups an anchor pins the subject through: they read what their
     * content reads, where it reads it.
     */
    private const ANCHOR_GROUPS = [GroupType::Capturing, GroupType::Named, GroupType::NonCapturing, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags];

    /**
     * The verbs that set the newline convention; the last one holds.
     */
    private const NEWLINE_VERBS = ['CR', 'LF', 'CRLF', 'ANYCRLF', 'ANY', 'NUL'];

    /**
     * @var int<1, max>
     */
    private int $next = 1;

    /**
     * @var array<int<1, max>, list<array{name: ?string, never: bool, after_accept: bool, min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool, decimalInt: bool, lowercase: bool, uppercase: bool, arms: list<Facts>}>>
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
     * The newline convention a verb sets, null for the default: LF, as
     * PHP's bundled PCRE2 is built.
     */
    private ?string $newline = null;

    /**
     * "$" matches at the very end only: /D.
     */
    private bool $dollarEndOnly = false;

    private bool $unicode = false;

    /**
     * Anchors the engine reads in multiline mode, by object id.
     *
     * @var array<int, true>
     */
    private array $multiline = [];

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
        $this->newline = null;
        $this->dollarEndOnly = str_contains($ast->flags, 'D');
        $this->unicode = $ast->isUnicode();
        $this->multiline = [];
        $this->scan($ast->pattern);
        $this->groups = GroupIndex::of($ast->pattern);
        $this->facts = new CaptureFacts($ast->isUnicode(), $this->ucp, $this->groups);
        $this->collectOption($ast->pattern, str_contains($ast->flags, 'i'), 'i', $this->facts->markCaseless(...));
        $this->collectOption($ast->pattern, str_contains($ast->flags, 'm'), 'm', function (NodeInterface $node): void {
            $this->multiline[spl_object_id($node)] = true;
        });
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

        return new CaptureShape($merged->whole, $merged->groups, $merged->marks, $cases, subject: $merged->subject);
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

        $wholePattern = $this->rebuild($pattern) ?? self::nothing($pattern);
        $this->collectAtStart($wholePattern);
        $this->collectAtEnd($wholePattern);
        $whole = $this->facts->of($wholePattern);
        // read as a group's are, with no zero-width first branch winning:
        // the arms hold for every match of a scan too
        $arms = $this->facts->arms($wholePattern, true);

        // (*ACCEPT) may end the match before the end anchor; \K moves the
        // start of the match, not what the pattern read from the subject
        $subject = $this->accepts ? null : $this->subject($wholePattern, $whole, $arms);

        // \K and (*ACCEPT) cut the whole match short: only its presence is known.
        if ($this->keeps || $this->accepts) {
            return new CaptureShape(new CaptureGroupShape(0, null, Participation::Always, 0, null, null), $groups, $this->marks, subject: $subject);
        }

        // a zero-width first branch wins every single match and not the
        // scan: the whole a scan reads joins the later branches' values
        $scanWhole = null;
        if ([''] === $whole['values']) {
            $scan = $this->facts->of($wholePattern, true);
            if ($scan['values'] !== ['']) {
                $scanWhole = self::record(0, null, Participation::Always, $scan);
            }
        }

        return new CaptureShape(self::record(0, null, Participation::Always, $whole, $arms), $groups, $this->marks, scanWhole: $scanWhole, subject: $subject);
    }

    /**
     * What the subject holds when a match proves the pattern read all of
     * it: every match starts at the start of the subject and ends at its
     * end, or before a final newline where the end anchor lets one through.
     * Null when the pattern does not pin both ends.
     *
     * @param Facts       $whole what the pattern reads
     * @param list<Facts> $arms  what each of its branches reads
     */
    private function subject(NodeInterface $pattern, array $whole, array $arms): ?CaptureGroupShape
    {
        $end = $this->endOfSubject($pattern);
        if (null === $end || !$this->startsAtSubjectStart($pattern)) {
            return null;
        }

        $newlines = 'end' === $end ? [] : $this->newlines();

        return self::record(0, null, Participation::Always, $this->followedBy($whole, $newlines), array_map(fn (array $arm): array => $this->followedBy($arm, $newlines), $arms));
    }

    /**
     * What a value reads followed by nothing or by one of the newlines: the
     * newlines hold no letter and no digit, and keep a value truthy.
     *
     * @param Facts        $facts
     * @param list<string> $newlines
     *
     * @return Facts
     */
    private function followedBy(array $facts, array $newlines): array
    {
        if ([] === $newlines) {
            return $facts;
        }

        $values = $facts['values'];
        if (null !== $values) {
            foreach ($facts['values'] as $value) {
                foreach ($newlines as $newline) {
                    $values[] = $value.$newline;
                }
            }
            $values = \count($values) > CaptureFacts::MAX_VALUES ? null : $values;
        }

        $longest = max(array_map(fn (string $newline): int => $this->unicode ? mb_strlen($newline, 'UTF-8') : \strlen($newline), $newlines));

        return ['values' => $values, 'max' => null === $facts['max'] ? null : $facts['max'] + $longest, 'digitsOnly' => false, 'decimalInt' => false] + $facts;
    }

    /**
     * The newlines "$" and "\Z" match before at the end of the subject,
     * under the newline convention of the pattern.
     *
     * @return non-empty-list<string>
     */
    private function newlines(): array
    {
        return match ($this->newline) {
            'CR' => ["\r"],
            'CRLF' => ["\r\n"],
            'ANYCRLF' => ["\r", "\n", "\r\n"],
            'ANY' => ["\r", "\n", "\r\n", "\x0b", "\x0c", ...($this->unicode ? ["\u{85}", "\u{2028}", "\u{2029}"] : ["\x85"])],
            'NUL' => ["\0"],
            default => ["\n"],
        };
    }

    /**
     * Whether every match of the node starts at the start of the subject:
     * "\A", or "^" without multiline, first on every path. What reads
     * nothing before the anchor leaves the match where it starts. "\G"
     * stands at the offset, and so does /A.
     */
    private function startsAtSubjectStart(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof AnchorNode => '^' === $node->value && !isset($this->multiline[spl_object_id($node)]),
            $node instanceof AssertionNode => 'A' === $node->value,
            $node instanceof SequenceNode => $this->firstPinned($node->children, $this->startsAtSubjectStart(...)),
            $node instanceof AlternationNode => self::every($node->alternatives, $this->startsAtSubjectStart(...)),
            $node instanceof GroupNode => \in_array($node->type, self::ANCHOR_GROUPS, true) && $this->startsAtSubjectStart($node->child),
            $node instanceof QuantifierNode => ($node->bounds->min ?? 0) >= 1 && $this->startsAtSubjectStart($node->node),
            $node instanceof ConditionalNode => $this->startsAtSubjectStart($node->yes) && $this->startsAtSubjectStart($node->no),
            default => false,
        };
    }

    /**
     * Where every match of the node ends: at the end of the subject ('end'),
     * there or before a final newline ('newline'), or anywhere (null). "\z",
     * and "$" under /D, end it; "\Z", and "$" without /D, may leave a
     * newline; under multiline "$" ends a line. What reads nothing after the
     * anchor leaves the match where it ends.
     *
     * @return 'end'|'newline'|null
     */
    private function endOfSubject(NodeInterface $node): ?string
    {
        return match (true) {
            $node instanceof AnchorNode => '$' !== $node->value || isset($this->multiline[spl_object_id($node)]) ? null : ($this->dollarEndOnly ? 'end' : 'newline'),
            $node instanceof AssertionNode => match ($node->value) {
                'z' => 'end',
                'Z' => 'newline',
                default => null,
            },
            $node instanceof SequenceNode => $this->lastPinned(array_reverse($node->children)),
            $node instanceof AlternationNode => self::joinEnds(array_map($this->endOfSubject(...), $node->alternatives)),
            $node instanceof GroupNode => \in_array($node->type, self::ANCHOR_GROUPS, true) ? $this->endOfSubject($node->child) : null,
            $node instanceof QuantifierNode => ($node->bounds->min ?? 0) >= 1 ? $this->endOfSubject($node->node) : null,
            $node instanceof ConditionalNode => self::joinEnds([$this->endOfSubject($node->yes), $this->endOfSubject($node->no)]),
            default => null,
        };
    }

    /**
     * Whether the first child that is pinned, past those that read nothing,
     * comes before any child that reads a character.
     *
     * @param list<NodeInterface>           $children
     * @param callable(NodeInterface): bool $pinned
     */
    private function firstPinned(array $children, callable $pinned): bool
    {
        foreach ($children as $child) {
            if ($pinned($child)) {
                return true;
            }

            if (0 !== $this->facts->lengths($child)[1]) {
                return false;
            }
        }

        return false;
    }

    /**
     * Where the last child that pins the end does, past those that read
     * nothing, read from the last child back.
     *
     * @param list<NodeInterface> $children from the last
     *
     * @return 'end'|'newline'|null
     */
    private function lastPinned(array $children): ?string
    {
        foreach ($children as $child) {
            $end = $this->endOfSubject($child);
            if (null !== $end) {
                return $end;
            }

            if (0 !== $this->facts->lengths($child)[1]) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param list<'end'|'newline'|null> $ends
     *
     * @return 'end'|'newline'|null
     */
    private static function joinEnds(array $ends): ?string
    {
        if ([] === $ends || \in_array(null, $ends, true)) {
            return null;
        }

        return \in_array('newline', $ends, true) ? 'newline' : 'end';
    }

    /**
     * array_all() of PHP 8.4.
     *
     * @param list<NodeInterface>           $nodes
     * @param callable(NodeInterface): bool $test
     */
    private static function every(array $nodes, callable $test): bool
    {
        foreach ($nodes as $node) {
            if (!$test($node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The record of a group, or of the whole match, from what it reads.
     *
     * @param Facts       $facts
     * @param list<Facts> $arms  what each way it is set reads
     */
    private static function record(int $number, ?string $name, Participation $participation, array $facts, array $arms = []): CaptureGroupShape
    {
        return new CaptureGroupShape(
            $number,
            $name,
            $participation,
            $facts['min'],
            $facts['max'],
            $facts['values'],
            $facts['nonFalsy'],
            $facts['digitsOnly'],
            $facts['decimalInt'],
            $facts['lowercase'],
            $facts['uppercase'],
            self::arms($number, $name, $participation, $facts, $arms),
        );
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
     * Reads what the whole pattern turns on: UCP, calls, the newline
     * convention.
     */
    private function scan(NodeInterface $node): void
    {
        if ($node instanceof PcreVerbNode && 'UCP' === $node->verb) {
            $this->ucp = true;
        }

        if ($node instanceof PcreVerbNode && \in_array($node->verb, self::NEWLINE_VERBS, true)) {
            $this->newline = $node->verb;
        }

        if ($node instanceof SubroutineNode) {
            $this->hasCalls = true;
        }

        foreach ($node->getChildren() as $child) {
            $this->scan($child);
        }
    }

    /**
     * Marks the nodes the engine reads under an option, "i" or "m", and
     * returns whether the option holds after the node. As in PCRE, a bare
     * "(?i)" holds to the end of its group, the following branches
     * included; a scoped "(?i:...)" holds inside; any group, like a
     * conditional, gives back at its end what held at its start.
     *
     * @param \Closure(NodeInterface): void $mark
     */
    private function collectOption(NodeInterface $node, bool $on, string $option, \Closure $mark): bool
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && null !== $node->flags) {
            $inside = self::optionAfter($on, $node->flags, $option);
            // a bare "(?i)" spans its flags and three bytes, a scoped "(?i:)" one more
            if ($node->getEndPosition() - $node->getStartPosition() === \strlen($node->flags) + 3) {
                return $inside;
            }

            $this->collectOption($node->child, $inside, $option, $mark);

            return $on;
        }

        if ($on) {
            $mark($node);
        }

        $carried = $on;
        foreach ($node->getChildren() as $child) {
            $carried = $this->collectOption($child, $carried, $option, $mark);
        }

        return $node instanceof SequenceNode || $node instanceof AlternationNode ? $carried : $on;
    }

    /**
     * Whether an option holds after inline flags such as "i", "-i", "^" or
     * "i-i", when it held before them.
     */
    private static function optionAfter(bool $on, string $flags, string $option): bool
    {
        if (str_starts_with($flags, '^')) {
            $on = false;
            $flags = substr($flags, 1);
        }

        [$set, $unset] = explode('-', $flags, 2) + [1 => ''];

        return !str_contains($unset, $option) && ($on || str_contains($set, $option));
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
     * sequences and groups that do not repeat: alternations,
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
                // A branch reset inside blocks a split there, not the one on the group itself.
                $reached = self::reach($node->node, $node, $path);
                if (!$reached['blocked'] && [] !== $reached['found']) {
                    return $reached;
                }
            }

            // Run or skipped, the group sets its groups together: split when it holds more than one.
            $splits = \in_array($node->node->type, self::PATH_GROUPS, true) && self::countCaptures($node->node) > 1;

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
        $bounds = $node->bounds;

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

        // Any other node on the path is a sequence or a group that does not repeat.
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
     * @param int<1, max>                                                                                                                                                                                                              $number
     * @param list<array{name: ?string, never: bool, after_accept: bool, min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool, decimalInt: bool, lowercase: bool, uppercase: bool, arms: list<Facts>}> $occurrences
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

        $join = ['min' => null, 'max' => 0, 'values' => [], 'nonFalsy' => [] !== $set, 'digitsOnly' => [] !== $set, 'decimalInt' => [] !== $set, 'lowercase' => [] !== $set, 'uppercase' => [] !== $set];
        foreach ([] === $set ? $occurrences : $set as $occurrence) {
            $join = [
                'min' => null === $join['min'] ? $occurrence['min'] : min($join['min'], $occurrence['min']),
                'max' => null === $join['max'] || null === $occurrence['max'] ? null : max($join['max'], $occurrence['max']),
                'values' => null === $join['values'] || null === $occurrence['values'] ? null : [...$join['values'], ...$occurrence['values']],
                'nonFalsy' => $join['nonFalsy'] && $occurrence['nonFalsy'],
                'digitsOnly' => $join['digitsOnly'] && $occurrence['digitsOnly'],
                'decimalInt' => $join['decimalInt'] && $occurrence['decimalInt'],
                'lowercase' => $join['lowercase'] && $occurrence['lowercase'],
                'uppercase' => $join['uppercase'] && $occurrence['uppercase'],
            ];
        }

        $values = $join['values'];
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

        return self::record($number, $name, $participation, ['min' => $join['min'] ?? 0, 'values' => [] === $set ? null : $values] + $join, $arms);
    }

    /**
     * The shape of each arm, when their join loses what they say: the join
     * holds no value set, and an arm holds one, or the digit fact the join
     * lacks; or an arm holds a decimal or a letter-case fact the join
     * lacks. Arms that read the same count once; past MAX_ARMS, the join
     * stands.
     *
     * @param Facts       $join what every arm reads together
     * @param list<Facts> $arms
     *
     * @return list<CaptureGroupShape>
     */
    private static function arms(int $number, ?string $name, Participation $participation, array $join, array $arms): array
    {
        if (null !== $join['values']) {
            return [];
        }

        $shapes = [];
        $telling = false;
        foreach ($arms as $arm) {
            $telling = $telling
                || (!$join['digitsOnly'] && (null !== $arm['values'] || $arm['digitsOnly']))
                || ($arm['decimalInt'] && !$join['decimalInt'])
                || ($arm['lowercase'] && !$join['lowercase'])
                || ($arm['uppercase'] && !$join['uppercase']);
            $shapes[serialize($arm)] = self::record($number, $name, $participation, $arm);
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
                $read = ['min' => $min, 'max' => $max, 'values' => null, 'nonFalsy' => false, 'digitsOnly' => false, 'decimalInt' => false, 'lowercase' => false, 'uppercase' => false, 'arms' => []];
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
        $bounds = $node->bounds;
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

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

namespace PHPRegex\Redos\Internal;

use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Redos\Internal\Backtrack\BacktrackProver;
use PHPRegex\Redos\Internal\Backtrack\Budget;
use PHPRegex\Redos\Internal\Backtrack\ItemAutomaton;
use PHPRegex\Redos\Internal\Backtrack\ModelLimit;
use PHPRegex\Redos\Internal\Backtrack\Pnfa;
use PHPRegex\Redos\RedosSearchCost;

/**
 * The cost of an unanchored search whose every attempt is proven linear,
 * read on the automaton the per-attempt proof built, on what is left of its
 * budget.
 *
 * The witness is a prefix, a run word, the word of a cycle of the
 * automaton, and a breaker. On the prefix, the run repeated n times, then
 * the breaker: an attempt started at any word of the run reads it from
 * there to its end (the word enters its own cycle from an attempt's start),
 * no attempt started anywhere succeeds, and every one fails on the breaker,
 * which holds the last code unit PCRE2 requires before it tries an
 * attempt. The search then starts n attempts, each linear in what is left
 * of the run: quadratic. The prefix is there when the first attempt would
 * match the bare run, as "^\s+" does in the trim regex "/^\s+|\s+$/". The
 * run word is one character, a cycle's word, or up to four characters of
 * different classes, so that a "\b" holds inside the run.
 *
 * PCRE2 ties every attempt to the search start under "A", and when each
 * alternative opens with "^" (without m), "\A", "\G", or a ".*" under s
 * outside an atomic group (a class of every character too, without u, where
 * PCRE2 reads it as the dot): none is reported. A leading "^" under m or
 * ".*" without s starts attempts only after a newline: reported only when
 * the run word ends with one. A possessive or atomic repeat of any
 * character moves to the end of the subject in one step: no run is read
 * through it.
 *
 * The model reads a bounded repeat above the unrolling cutoff as unbounded:
 * no run is read through it, the engine's attempts there stop at the bound.
 * It does not keep a lookaround's condition and keeps an atomic body with
 * every way through it: such a witness is reported only when the engine
 * replay confirms it. A pinned replay cannot confirm a pattern holding "\G",
 * which holds at every pinned offset.
 *
 * @internal
 */
final readonly class SearchCostProver
{
    /**
     * The most run words tried, and the most a cycle gives.
     */
    private const MAX_WORDS = 48;

    private const MAX_WORDS_PER_CYCLE = 8;

    /**
     * The most words of different classes tried, from the pairs and from
     * each two-step cycle: two or four characters.
     */
    private const MAX_MIXED_WORDS = 16;

    /**
     * The most one-character prefixes tried.
     */
    private const MAX_PREFIXES = 8;

    private const MAX_BREAKERS = 4;

    private const MAX_BREAKER_NODES = 64;

    private const MAX_BREAKER_LENGTH = 4;

    /**
     * The characters of "\h" and of "\v" (pcre2pattern, "Generic character
     * types"), whatever u.
     */
    private const HORIZONTAL_SPACES = [[0x09, 0x09], [0x20, 0x20], [0xA0, 0xA0], [0x1680, 0x1680], [0x180E, 0x180E], [0x2000, 0x200A], [0x202F, 0x202F], [0x205F, 0x205F], [0x3000, 0x3000]];

    private const VERTICAL_SPACES = [[0x0A, 0x0D], [0x85, 0x85], [0x2028, 0x2029]];

    public function __construct(private SearchCostReplayer $replayer = new SearchCostReplayer()) {}

    /**
     * The search-cost witness of the pattern the prover proved linear per
     * attempt, replayed on the engine when asked; null when none is found.
     *
     * A library failure inside the search proof says nothing of one
     * attempt: it gives no witness, and the per-attempt verdict the caller
     * holds stands. Any other error is a bug, and surfaces.
     */
    public function find(string $pattern, RegexNode $regex, BacktrackProver $prover, bool $replay): ?RedosSearchCost
    {
        try {
            return $this->seek($pattern, $regex, $prover, $replay);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    private function seek(string $pattern, RegexNode $regex, BacktrackProver $prover, bool $replay): ?RedosSearchCost
    {
        $search = $prover->search();
        if (null === $search || str_contains($regex->flags, 'A')) {
            return null;
        }

        [$automaton, $budget] = $search;
        $pnfa = $automaton->pnfa;
        $unicode = $pnfa->unicode;
        $sets = [];
        foreach ($pnfa->sets as $state => $set) {
            if (Pnfa::CHAR === $pnfa->kinds[$state]) {
                $sets[$pnfa->offsets[$state] ?? 0] ??= $set;
            }
        }

        // "^" holds at line starts under m: decided when no inline option
        // moves m and no lookaround brings the other kind.
        $multiline = match (true) {
            !$pnfa->hasLineStart => false,
            str_contains($regex->flags, 'm') && !self::setsMultiline($regex->pattern) => true,
            default => null,
        };

        // Whether a class may read the other case of its characters: "i"
        // set anywhere, or taken off.
        $caseless = str_contains($regex->flags, 'i')
            || self::holds($regex->pattern, static fn (NodeInterface $node): bool => $node instanceof GroupNode && GroupType::InlineFlags === $node->type && str_contains((string) $node->flags, 'i'));
        $start = self::start($regex->pattern, $sets, $multiline, $unicode, $caseless, false);
        if (null === $start || $start[0]) {
            return null;
        }

        $lineStart = $start[1];
        $jumps = [];
        self::jumps($regex->pattern, $sets, $unicode, $caseless, $jumps);

        try {
            $found = $this->witness($automaton, $budget, LastCodeUnit::of($regex), $lineStart, $jumps, $pnfa->readsFinalNewline);
        } catch (ModelLimit) {
            return null;
        }

        if (null === $found) {
            return null;
        }

        $prefix = Utf8::encode($found['prefix'], $unicode);
        $run = Utf8::encode($found['run'], $unicode);
        $breakers = array_map(static fn (array $breaker): string => Utf8::encode($breaker, $unicode), $found['breakers']);
        $exact = [] === $pnfa->marks && [] === $pnfa->approximated;

        if (!$replay) {
            return $exact ? new RedosSearchCost(2, $prefix, $run, $breakers[0], $unicode) : null;
        }

        $pinnable = !self::holds($regex->pattern, static fn (NodeInterface $node): bool => $node instanceof AssertionNode && 'G' === $node->value);
        foreach ($pinnable ? $breakers : [] as $breaker) {
            if ($this->replayer->replays($pattern, $prefix, $run, $breaker)) {
                return new RedosSearchCost(2, $prefix, $run, $breaker, $unicode, true);
            }
        }

        return $exact ? new RedosSearchCost(2, $prefix, $run, $breakers[0], $unicode, false) : null;
    }

    /**
     * Whether the run holds the code unit PCRE2 requires: the character
     * itself, or its other ASCII case when the pattern reads both alike
     * (one class, as under /i, where PCRE2 looks for either case). A
     * character that only shares a class with it, as ";" with "!" under
     * ".", is not it.
     *
     * @param list<int> $run
     */
    private static function holdsCodeUnit(ItemAutomaton $automaton, array $run, int $required): bool
    {
        $letter = static fn (int $character): bool => ($character >= 0x41 && $character <= 0x5A) || ($character >= 0x61 && $character <= 0x7A);
        foreach ($run as $character) {
            if ($character === $required || ($letter($character) && $letter($required)
                && ($character | 0x20) === ($required | 0x20) && $automaton->classOf($character) === $automaton->classOf($required))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node or one of its descendants passes the test.
     *
     * @param \Closure(NodeInterface): bool $test
     */
    private static function holds(NodeInterface $node, \Closure $test): bool
    {
        if ($test($node)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holds($child, $test)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every attempt of the node is tied to the search start, and
     * whether it starts only at the subject's start or after a newline, read
     * as PCRE2 reads the first item of each alternative; null when "^" may
     * be either kind.
     *
     * @param array<int, CharSet> $sets the set the automaton reads for the atom at each offset
     *
     * @return array{bool, bool}|null
     */
    private static function start(NodeInterface $node, array $sets, ?bool $multiline, bool $unicode, bool $caseless, bool $atomic): ?array
    {
        if ($node instanceof SequenceNode) {
            $items = self::items($node);

            return [] === $items ? [false, false] : self::start($items[0], $sets, $multiline, $unicode, $caseless, $atomic);
        }

        if ($node instanceof AlternationNode) {
            $anchored = true;
            $lineStart = true;
            foreach ($node->alternatives as $alternative) {
                $start = self::start($alternative, $sets, $multiline, $unicode, $caseless, $atomic);
                if (null === $start) {
                    return null;
                }

                $anchored = $anchored && $start[0];
                $lineStart = $lineStart && $start[1];
            }

            return [$anchored, $lineStart];
        }

        if ($node instanceof GroupNode) {
            return match ($node->type) {
                GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::BranchReset, GroupType::InlineFlags => self::start($node->child, $sets, $multiline, $unicode, $caseless, $atomic),
                GroupType::Atomic => self::start($node->child, $sets, $multiline, $unicode, $caseless, true),
                default => [false, false],
            };
        }

        if ($node instanceof AnchorNode || $node instanceof AssertionNode) {
            return match ($node->value) {
                // "^" without m holds where a line starts too; "\A" and "\G" are no line start for PCRE2.
                '^' => null === $multiline ? null : [!$multiline, true],
                'A', 'G' => [true, false],
                default => [false, false],
            };
        }

        if (!$node instanceof QuantifierNode) {
            return [false, false];
        }

        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null !== $bounds && $bounds->min > 0) {
            return self::start($node->node, $sets, $multiline, $unicode, $caseless, $atomic);
        }

        // A leading ".*": PCRE2 anchors it under s, and starts it after a
        // newline without s; inside an atomic group it does neither.
        if ($atomic || null === $bounds || null !== $bounds->max) {
            return [false, false];
        }

        $set = $sets[$node->node->getStartPosition()] ?? null;
        $universe = CharSet::universe($unicode);
        if (null === $set) {
            return [false, false];
        }

        if ($universe->subtract($set)->isEmpty()) {
            $anchored = self::isAnyCharacter($node->node, $unicode, $caseless);

            return null === $anchored ? null : [$anchored, false];
        }

        $dot = $node->node instanceof DotNode || ($node->node instanceof CharTypeNode && 'N' === $node->node->value);

        return [false, $dot && $set->key() === $universe->subtract(CharSet::single(0x0A))->key()];
    }

    /**
     * Whether PCRE2 reads the atom, which matches every character, as its
     * any-character item, which it anchors when it leads the pattern
     * repeated and moves to the end of the subject in one step when
     * possessive: the dot under s, "\p{Any}", and a class without u. Under u
     * (UTF and UCP) a class reads every item into a bitmap below U+0100,
     * and above it only its characters, ranges, "\h", "\v" and their
     * negations: it is that item when these cover every code point from
     * U+0100, the surrogates included, or when it holds "\p{Any}"
     * (pcre2test 10.49). "\d", "\s", "\w", their negations and the other
     * properties count for nothing there, so "[\s\S]" is no such class. A
     * POSIX class or a negated class is undecided, and so is under i a class
     * whose gaps the other cases may fill: null.
     */
    private static function isAnyCharacter(NodeInterface $atom, bool $unicode, bool $caseless): ?bool
    {
        if ($atom instanceof DotNode || $atom instanceof UnicodePropNode) {
            return true;
        }

        if (!$atom instanceof CharClassNode) {
            return false;
        }

        if (!$unicode) {
            return true;
        }

        if ($atom->isNegated) {
            return null;
        }

        $every = CharSet::range(0, 0x10FFFF);
        $covered = CharSet::empty();
        $undecided = false;
        foreach ($atom->expression instanceof AlternationNode ? $atom->expression->alternatives : [$atom->expression] as $part) {
            if ($part instanceof UnicodePropNode && 0 === strcasecmp(trim($part->prop, '{}'), 'Any')) {
                return true;
            }

            $characters = self::classCharacters($part, $every);
            if (null === $characters) {
                $undecided = true;
            } else {
                $covered = $covered->union($characters);
            }
        }

        // Below U+0100 PCRE2 reads every item into a bitmap, which the set
        // covering every character fills.
        $gap = CharSet::range(0x100, 0x10FFFF)->subtract($covered);
        if ($gap->isEmpty()) {
            return true;
        }

        if ($undecided) {
            return null;
        }

        // The other cases may fill a gap under i, never one among the
        // surrogates, which have none.
        return $caseless && $gap->intersect(CharSet::range(0xD800, 0xDFFF))->isEmpty() ? null : false;
    }

    /**
     * The code points a part of a class under u covers as PCRE2 reads it;
     * nothing for a property, null for a part not read here.
     */
    private static function classCharacters(NodeInterface $part, CharSet $every): ?CharSet
    {
        if ($part instanceof RangeNode) {
            $from = self::classCharacter($part->start);
            $to = self::classCharacter($part->end);

            return null === $from || null === $to ? null : CharSet::range($from, $to);
        }

        $character = self::classCharacter($part);
        if (null !== $character) {
            return CharSet::single($character);
        }

        if ($part instanceof UnicodePropNode) {
            return CharSet::empty();
        }

        if (!$part instanceof CharTypeNode) {
            return null;
        }

        return match ($part->value) {
            'h' => CharSet::fromRanges(self::HORIZONTAL_SPACES),
            'H' => $every->subtract(CharSet::fromRanges(self::HORIZONTAL_SPACES)),
            'v' => CharSet::fromRanges(self::VERTICAL_SPACES),
            'V' => $every->subtract(CharSet::fromRanges(self::VERTICAL_SPACES)),
            // Unicode properties under u.
            'd', 'D', 's', 'S', 'w', 'W' => CharSet::empty(),
            default => null,
        };
    }

    private static function classCharacter(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint;
        }

        $characters = $node instanceof LiteralNode ? Utf8::decode($node->value, true) : null;

        return null !== $characters && 1 === \count($characters) ? $characters[0] : null;
    }

    /**
     * The children of the sequence that are items: not an option setting
     * such as "(?i)", nor a comment.
     *
     * @return list<NodeInterface>
     */
    private static function items(SequenceNode $node): array
    {
        return array_values(array_filter($node->children, static fn (NodeInterface $child): bool => !$child instanceof CommentNode
            && !($child instanceof LiteralNode && '' === $child->value)
            && !($child instanceof GroupNode && GroupType::InlineFlags === $child->type && $child->child instanceof LiteralNode && '' === $child->child->value)));
    }

    private static function setsMultiline(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && str_contains((string) $node->flags, 'm')) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::setsMultiline($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the possessive or atomic repeats of any character read: PCRE2
     * moves such a repeat to the end of the subject in one step, so a run
     * read through it costs nothing. A run another loop reads is not
     * concerned.
     *
     * @param array<int, CharSet> $sets
     * @param array<int, true>    $jumps the offsets of their characters
     *
     * @param-out array<int, true> $jumps
     */
    private static function jumps(NodeInterface $node, array $sets, bool $unicode, bool $caseless, array &$jumps): void
    {
        $atomic = $node instanceof GroupNode && GroupType::Atomic === $node->type;
        $repeat = $atomic ? self::unwrap($node->child) : $node;
        if ($repeat instanceof QuantifierNode && ($atomic || QuantifierType::Possessive === $repeat->type)) {
            $bounds = QuantifierBounds::parse($repeat->quantifier);
            $offset = $repeat->node->getStartPosition();
            $set = $sets[$offset] ?? null;
            if (null !== $bounds && null === $bounds->max && null !== $set && CharSet::universe($unicode)->subtract($set)->isEmpty()
                && false !== self::isAnyCharacter($repeat->node, $unicode, $caseless)) {
                $jumps[$offset] = true;
            }
        }

        foreach ($node->getChildren() as $child) {
            self::jumps($child, $sets, $unicode, $caseless, $jumps);
        }
    }

    private static function unwrap(NodeInterface $node): NodeInterface
    {
        // An atomic body other than one repeat is kept with every way through
        // it, so no witness crosses it before the replay.
        while ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::BranchReset], true)) {
            $node = $node->child;
        }

        return $node;
    }

    /**
     * The first run word that enters its own cycle and that no attempt
     * succeeds on, after the shortest prefix that keeps the first attempt
     * from succeeding, with the breakers found after it, in characters.
     *
     * @param int|null         $required     the code unit PCRE2 requires, as a character
     * @param array<int, true> $jumps        where the repeats that jump to the end read
     * @param bool             $finalNewline whether "$" or "\Z" may hold before a final newline
     *
     * @throws ModelLimit
     *
     * @return array{prefix: list<int>, run: list<int>, breakers: non-empty-list<list<int>>}|null
     */
    private function witness(ItemAutomaton $automaton, Budget $budget, ?int $required, bool $lineStart, array $jumps, bool $finalNewline): ?array
    {
        $prefixes = [[]];
        for ($class = 0; $class < $automaton->classCount() && \count($prefixes) <= self::MAX_PREFIXES; $class++) {
            $prefixes[] = [$class];
        }

        foreach ($this->runWords($automaton, $budget, $jumps) as $word) {
            $last = $automaton->representatives[$word[\count($word) - 1]];
            if ($lineStart && 0x0A !== $last) {
                continue;
            }

            $later = self::set($automaton->laterStart(self::contextOf($automaton, $last)));
            if (!$this->entersItself($automaton, $budget, $later, $word, $jumps)) {
                continue;
            }

            $run = array_map(static fn (int $class): int => $automaton->representatives[$class], $word);
            $missing = null === $required || self::holdsCodeUnit($automaton, $run, $required) ? [] : [$required];
            // The shortest prefix after which the attempt at the run's start
            // reads the run too, and no attempt succeeds inside it.
            foreach ($prefixes as $prefix) {
                $first = [] === $prefix
                    ? self::set($automaton->initials[0]['items'])
                    : self::set($automaton->laterStart(self::contextOf($automaton, $automaton->representatives[$prefix[\count($prefix) - 1]])));
                $after = $this->entersItself($automaton, $budget, $first, $word, $jumps) ? $this->runEnd($automaton, $prefix, $word) : null;
                if (null === $after) {
                    continue;
                }

                $breakers = $this->breakers($automaton, $budget, $after, $missing, $finalNewline ? $last : null);
                if ([] !== $breakers) {
                    return [
                        'prefix' => array_map(static fn (int $class): int => $automaton->representatives[$class], $prefix),
                        'run' => $run,
                        'breakers' => $breakers,
                    ];
                }

                break;
            }
        }

        return null;
    }

    /**
     * The words of the shortest cycle through each reading item, as
     * classes: one class repeated when it fits every step, else each step's
     * first class, then each step's second, and so on; then words of up to
     * four characters that mix classes, so that a word boundary holds
     * inside the run: two classes the loops read, characters of different
     * kinds first, and a two-step cycle read twice with two choices. A
     * cycle goes through no repeat that jumps to the end and back into no
     * bounded repeat the model reads as unbounded. Whether the attempts
     * read a word again and again is entersItself()'s to say.
     *
     * @param array<int, true> $jumps
     *
     * @throws ModelLimit
     *
     * @return list<non-empty-list<int>>
     */
    private function runWords(ItemAutomaton $automaton, Budget $budget, array $jumps): array
    {
        // The ways a run may go, read once; a cycle stays in the strongly
        // connected component of its items.
        $graph = [];
        for ($item = 0; $item < $automaton->count(); $item++) {
            if (!$automaton->isFinal($item) && !isset($jumps[$automaton->offsetOf($item)])) {
                $budget->step();
                $graph[$item] = array_values(array_filter(self::exactSuccessors($automaton, $item, $jumps), static fn (int $next): bool => !$automaton->isFinal($next)));
            }
        }

        $components = self::components($graph, $budget);
        $sizes = array_count_values($components);
        // Mixing classes matters only where an attempt's start depends on
        // the character before it: the classes any item on a cycle reads.
        $kinds = null !== $automaton->pnfa->wordSet || $automaton->pnfa->hasLineStart;
        $words = [];
        $mixed = [];
        $looped = [];
        foreach ($graph as $item => $successors) {
            if (1 === $sizes[$components[$item]] && !\in_array($item, $successors, true)) {
                continue;
            }

            foreach ($kinds ? self::choices($automaton, [$automaton->masks[$item]]) : [] as $classes) {
                $looped += array_fill_keys($classes, true);
            }

            if (\count($words) >= self::MAX_WORDS) {
                continue;
            }

            $labels = $this->cycle($automaton, $budget, $item, $graph, $components);
            if (null === $labels) {
                continue;
            }

            foreach (self::wordsOf($automaton, $labels) as $word) {
                $words[implode(',', $word)] ??= $word;
            }

            foreach (self::mixedWordsOf($automaton, $labels) as $word) {
                $mixed[implode(',', $word)] ??= $word;
            }
        }

        $pairs = [];
        foreach (self::pairsOf($automaton, array_keys($looped)) as $word) {
            $pairs[implode(',', $word)] ??= $word;
        }

        return \array_slice([...array_values($words), ...array_values(array_diff_key($pairs + $mixed, $words))], 0, self::MAX_WORDS);
    }

    /**
     * Two-character words of two different classes: a word character then
     * another kind first, then two kinds, then the rest.
     *
     * @param list<int> $classes
     *
     * @return list<non-empty-list<int>>
     */
    private static function pairsOf(ItemAutomaton $automaton, array $classes): array
    {
        sort($classes);
        $ranked = [];
        foreach ($classes as $first) {
            foreach ($classes as $second) {
                if ($first === $second) {
                    continue;
                }

                $kinds = [self::contextOf($automaton, $automaton->representatives[$first]), self::contextOf($automaton, $automaton->representatives[$second])];
                $rank = match (true) {
                    ItemAutomaton::CONTEXT_WORD === $kinds[0] && $kinds[0] !== $kinds[1] => 0,
                    $kinds[0] !== $kinds[1] => 1,
                    default => 2,
                };
                $ranked[$rank][] = [$first, $second];
            }
        }

        ksort($ranked);

        return \array_slice(array_merge(...array_values($ranked)), 0, self::MAX_MIXED_WORDS);
    }

    /**
     * The strongly connected component of each item of the graph, by
     * Tarjan's algorithm, without recursion.
     *
     * @param array<int, list<int>> $graph
     *
     * @throws ModelLimit
     *
     * @return array<int, int>
     */
    private static function components(array $graph, Budget $budget): array
    {
        /** @var array<int, int> $index */
        $index = [];
        /** @var array<int, int> $low */
        $low = [];
        /** @var array<int, int> $position where each item still on the stack lies in it */
        $position = [];
        /** @var list<int> $stack */
        $stack = [];
        /** @var array<int, int> $components */
        $components = [];
        $counter = 0;
        foreach (array_keys($graph) as $root) {
            if (isset($index[$root])) {
                continue;
            }

            // The path of the depth-first walk, and the next successor each
            // of its items visits.
            $path = [$root];
            $nextOf = [0];
            $index[$root] = $low[$root] = $counter++;
            $position[$root] = \count($stack);
            $stack[] = $root;
            while ([] !== $path) {
                $budget->step();
                $depth = \count($path) - 1;
                $item = $path[$depth];
                $next = $nextOf[$depth];
                if ($next < \count($graph[$item])) {
                    $nextOf[$depth] = $next + 1;
                    $successor = $graph[$item][$next];
                    if (!isset($index[$successor])) {
                        $index[$successor] = $low[$successor] = $counter++;
                        $position[$successor] = \count($stack);
                        $stack[] = $successor;
                        $path[] = $successor;
                        $nextOf[] = 0;
                    } elseif (isset($position[$successor])) {
                        $low[$item] = min($low[$item], $index[$successor]);
                    }

                    continue;
                }

                array_pop($path);
                array_pop($nextOf);
                if ([] !== $path) {
                    $parent = $path[\count($path) - 1];
                    $low[$parent] = min($low[$parent], $low[$item]);
                }

                if ($low[$item] === $index[$item]) {
                    foreach (array_splice($stack, $position[$item]) as $member) {
                        unset($position[$member]);
                        $components[$member] = $index[$item];
                    }
                }
            }
        }

        return $components;
    }

    /**
     * The labels of the shortest cycle from the item back to itself, the
     * item's own first, inside its component.
     *
     * @param array<int, list<int>> $graph
     * @param array<int, int>       $components
     *
     * @throws ModelLimit
     *
     * @return non-empty-list<string>|null
     */
    private function cycle(ItemAutomaton $automaton, Budget $budget, int $item, array $graph, array $components): ?array
    {
        $parents = [$item => $item];
        $queue = [$item];
        for ($head = 0; $head < \count($queue); $head++) {
            $budget->step();
            $current = $queue[$head];
            foreach ($graph[$current] as $next) {
                if ($components[$next] !== $components[$item]) {
                    continue;
                }

                if ($next === $item) {
                    $path = [];
                    for ($at = $current; $at !== $item; $at = $parents[$at]) {
                        $path[] = $automaton->masks[$at];
                    }

                    return [$automaton->masks[$item], ...array_reverse($path)];
                }

                if (!isset($parents[$next])) {
                    $parents[$next] = $current;
                    $queue[] = $next;
                }
            }
        }

        return null;
    }

    /**
     * The successors of a reading item a run may go on to: not back into a
     * bounded repeat read as unbounded, not into a repeat that jumps to the
     * end.
     *
     * @param array<int, true> $jumps
     *
     * @return list<int>
     */
    private static function exactSuccessors(ItemAutomaton $automaton, int $item, array $jumps): array
    {
        $successors = [];
        foreach ($automaton->successors[$item] as $index => $next) {
            if (0 !== ($automaton->edges[$item][$index] & ItemAutomaton::EDGE_ABSTRACTED)
                || (!$automaton->isFinal($next) && isset($jumps[$automaton->offsetOf($next)]))) {
                continue;
            }

            $successors[] = $next;
        }

        return $successors;
    }

    /**
     * @param non-empty-list<string> $labels
     *
     * @return list<non-empty-list<int>>
     */
    private static function wordsOf(ItemAutomaton $automaton, array $labels): array
    {
        $common = $labels[0];
        foreach ($labels as $label) {
            $common &= $label;
        }

        $words = [];
        if (!ItemAutomaton::isEmpty($common)) {
            for ($class = 0; $class < $automaton->classCount() && \count($words) < self::MAX_WORDS_PER_CYCLE; $class++) {
                if (ItemAutomaton::has($common, $class)) {
                    $words[] = array_fill(0, \count($labels), $class);
                }
            }

            return $words;
        }

        $choices = self::choices($automaton, $labels);
        for ($rank = 0; \count($words) < self::MAX_WORDS_PER_CYCLE; $rank++) {
            $word = [];
            $fresh = false;
            foreach ($choices as $classes) {
                $word[] = $classes[min($rank, \count($classes) - 1)];
                $fresh = $fresh || $rank < \count($classes);
            }

            if (!$fresh || [] === $word) {
                break;
            }

            $words[] = $word;
        }

        return $words;
    }

    /**
     * Words of a two-step cycle read twice, four characters, whose two
     * halves differ.
     *
     * @param non-empty-list<string> $labels
     *
     * @return list<non-empty-list<int>>
     */
    private static function mixedWordsOf(ItemAutomaton $automaton, array $labels): array
    {
        if (2 !== \count($labels)) {
            return [];
        }

        $choices = self::choices($automaton, $labels);
        if (2 !== \count($choices)) {
            return [];
        }

        $halves = [];
        foreach ($choices[0] as $first) {
            foreach ($choices[1] as $second) {
                $halves[] = [$first, $second];
            }
        }

        $halves = \array_slice($halves, 0, self::MAX_MIXED_WORDS);
        $words = [];
        foreach ($halves as $first) {
            foreach ($halves as $second) {
                if ($first !== $second && \count($words) < self::MAX_MIXED_WORDS) {
                    $words[] = [...$first, ...$second];
                }
            }
        }

        return $words;
    }

    /**
     * The classes each label reads, in order.
     *
     * @param non-empty-list<string> $labels
     *
     * @return list<non-empty-list<int>>
     */
    private static function choices(ItemAutomaton $automaton, array $labels): array
    {
        $choices = [];
        foreach ($labels as $label) {
            $classes = [];
            for ($class = 0; $class < $automaton->classCount(); $class++) {
                if (ItemAutomaton::has($label, $class)) {
                    $classes[] = $class;
                }
            }

            if ([] !== $classes) {
                $choices[] = $classes;
            }
        }

        return $choices;
    }

    /**
     * Whether an attempt started with the items reads the run word again
     * and again without ever failing nor succeeding: it reads to the end of
     * any run. It reads along the ways a run may take: not back into a
     * bounded repeat read as unbounded, not through a repeat that jumps to
     * the end.
     *
     * @param array<int, true>    $current the attempt's items at its start
     * @param non-empty-list<int> $word
     * @param array<int, true>    $jumps
     *
     * @throws ModelLimit
     */
    private function entersItself(ItemAutomaton $automaton, Budget $budget, array $current, array $word, array $jumps): bool
    {
        // The sets are finite: they repeat, or the budget stops the search.
        $seen = [];
        while (true) {
            $key = implode(',', array_keys($current));
            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
            $budget->step(1 + \count($current));
            foreach ($word as $class) {
                $current = self::stepExactly($automaton, $current, $class, $jumps);
                if (null === $current || [] === $current) {
                    return false;
                }
            }
        }
    }

    /**
     * The items after the set reads the class along the ways a run may
     * take, or null when one of its final items succeeds before it.
     *
     * @param array<int, true> $items
     * @param array<int, true> $jumps
     *
     * @throws ModelLimit
     *
     * @return array<int, true>|null
     */
    private static function stepExactly(ItemAutomaton $automaton, array $items, int $class, array $jumps): ?array
    {
        $next = [];
        foreach ($items as $item => $_) {
            if ($automaton->isFinal($item)) {
                if ($automaton->accepts($item, $class)) {
                    return null;
                }

                continue;
            }

            if ($automaton->reads($item, $class)) {
                foreach (self::exactSuccessors($automaton, $item, $jumps) as $successor) {
                    $next[$successor] = true;
                }
            }
        }

        ksort($next);

        return $next;
    }

    /**
     * The items of every attempt of the search at the end of the run, an
     * attempt started at each character of the prefix and of the run,
     * whatever the run's length (one word at least); null when one of them
     * succeeds before the run ends.
     *
     * @param list<int>           $prefix classes
     * @param non-empty-list<int> $word
     *
     * @throws ModelLimit
     *
     * @return array<int, true>|null
     */
    private function runEnd(ItemAutomaton $automaton, array $prefix, array $word): ?array
    {
        $later = [];
        foreach ($word as $index => $class) {
            $later[$index + 1 === \count($word) ? 0 : $index + 1] = self::set($automaton->laterStart(self::contextOf($automaton, $automaton->representatives[$class])));
        }

        $current = self::set($automaton->initials[0]['items']);
        foreach ($prefix as $index => $class) {
            if ($index > 0) {
                $current += self::set($automaton->laterStart(self::contextOf($automaton, $automaton->representatives[$prefix[$index - 1]])));
            }

            $current = $automaton->stepSet($current, $class);
            if (null === $current) {
                return null;
            }
        }

        if ([] !== $prefix) {
            $current += self::set($automaton->laterStart(self::contextOf($automaton, $automaton->representatives[$prefix[\count($prefix) - 1]])));
        }

        $seen = [];
        $after = [];
        while (true) {
            foreach ($word as $index => $class) {
                if ($index > 0) {
                    $current += $later[$index];
                }

                $current = $automaton->stepSet($current, $class);
                if (null === $current) {
                    return null;
                }
            }

            $current += $later[0];
            ksort($current);
            $key = implode(',', array_keys($current));
            if (isset($seen[$key])) {
                ksort($after);

                return $after;
            }

            $seen[$key] = true;
            $after += $current;
        }
    }

    /**
     * The breakers, shortest first: characters after which every attempt of
     * the run has failed, then the code unit PCRE2 requires when the run
     * does not hold it. With them, no attempt started anywhere succeeds.
     * When "$" or "\Z" may hold before a final newline, a witness ending
     * with a newline gets one more character: the model reads such a
     * newline into the anchor, where a pattern item may read it instead.
     *
     * @param array<int, true> $after
     * @param list<int>        $missing characters
     * @param int|null         $runEnd  the run's last character, when the witness must not end with a newline
     *
     * @throws ModelLimit
     *
     * @return list<list<int>>
     */
    private function breakers(ItemAutomaton $automaton, Budget $budget, array $after, array $missing, ?int $runEnd): array
    {
        $found = [];
        $queue = [[$after, []]];
        $seen = [implode(',', array_keys($after)) => true];
        for ($head = 0; $head < \count($queue) && $head < self::MAX_BREAKER_NODES && \count($found) < self::MAX_BREAKERS; $head++) {
            $budget->step();
            [$current, $path] = $queue[$head];
            $this->collect($automaton, $after, $path, $missing, $runEnd, $found);
            if (\count($path) >= self::MAX_BREAKER_LENGTH) {
                continue;
            }

            for ($class = 0; $class < $automaton->classCount() && \count($found) < self::MAX_BREAKERS; $class++) {
                $next = $automaton->stepSet($current, $class);
                if (null === $next) {
                    continue;
                }

                if ([] === $next) {
                    $this->collect($automaton, $after, [...$path, $class], $missing, $runEnd, $found);

                    continue;
                }

                $key = implode(',', array_keys($next));
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $queue[] = [$next, [...$path, $class]];
                }
            }
        }

        return $found;
    }

    /**
     * Keeps the breaker the path and the missing code unit write when the
     * model rejects it.
     *
     * @param array<int, true> $after
     * @param list<int>        $path    classes
     * @param list<int>        $missing characters
     * @param list<list<int>>  $found
     *
     * @param-out list<list<int>> $found
     *
     * @throws ModelLimit
     */
    private function collect(ItemAutomaton $automaton, array $after, array $path, array $missing, ?int $runEnd, array &$found): void
    {
        $breaker = [...array_map(static fn (int $class): int => $automaton->representatives[$class], $path), ...$missing];
        $candidates = [$breaker];
        if (null !== $runEnd && 0x0A === ([] === $breaker ? $runEnd : $breaker[\count($breaker) - 1])) {
            $candidates = [];
            foreach ($automaton->representatives as $character) {
                if (0x0A !== $character) {
                    $candidates[] = [...$breaker, $character];
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (\in_array($candidate, $found, true)) {
                return;
            }

            if ($this->rejects($automaton, $after, $candidate)) {
                $found[] = $candidate;

                return;
            }
        }
    }

    /**
     * Whether the attempts of the run fail on the breaker, and no attempt
     * started inside it succeeds.
     *
     * @param array<int, true> $after
     * @param list<int>        $breaker characters
     *
     * @throws ModelLimit
     */
    private function rejects(ItemAutomaton $automaton, array $after, array $breaker): bool
    {
        $current = $after;
        $previous = null;
        foreach ($breaker as $character) {
            if (null !== $previous) {
                $current += self::set($automaton->laterStart(self::contextOf($automaton, $previous)));
            }

            $current = $automaton->stepSet($current, $automaton->classOf($character));
            if (null === $current) {
                return false;
            }

            $previous = $character;
        }

        if (null !== $previous) {
            $current += self::set($automaton->laterStart(self::contextOf($automaton, $previous)));
        }

        return !$automaton->acceptsAtEnd($current);
    }

    /**
     * The context the character leaves for an attempt started after it.
     */
    private static function contextOf(ItemAutomaton $automaton, int $character): int
    {
        $pnfa = $automaton->pnfa;

        return match (true) {
            null !== $pnfa->wordSet && $pnfa->wordSet->contains($character) => ItemAutomaton::CONTEXT_WORD,
            $pnfa->hasLineStart && 0x0A === $character => ItemAutomaton::CONTEXT_NEWLINE,
            default => ItemAutomaton::CONTEXT_OTHER,
        };
    }

    /**
     * @param list<int> $items
     *
     * @return array<int, true>
     */
    private static function set(array $items): array
    {
        $set = array_fill_keys($items, true);
        ksort($set);

        return $set;
    }
}

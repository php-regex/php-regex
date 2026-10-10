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

namespace PHPRegex\Redos\Internal\Backtrack;

use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Redos\RedosComplexity;

/**
 * Finds the worst ambiguity of one search that a witness can trigger:
 * exponential first, then the longest polynomial chain.
 *
 * An ambiguity is set aside only when it is disproved: every way around it
 * reads a character after which success is certain, or no continuation of
 * it is ever rejected. One found and neither witnessed nor disproved is
 * reported as such: the verdict is then no proof.
 *
 * @internal
 *
 * @phpstan-type Witness array{prefix: list<int>, pump: list<int>, suffix: list<int>, alternatives: list<list<int>>, published: list<int>, withoutMatches: bool}
 * @phpstan-type Verdict array{complexity: RedosComplexity, degree: int|null, prefix: list<int>, pump: list<int>, suffix: list<int>, alternatives: list<list<int>>, published: list<int>, withoutMatches: bool, approximated: bool, unwitnessed: int|null}
 */
final class AmbiguityFinder
{
    /**
     * The most pumps and prefixes a witness search tries for one ambiguity.
     */
    private const MAX_PUMPS = 8;

    private const MAX_PREFIXES = 8;

    /**
     * The most continuations a witness through a lookaround is tried with,
     * and the most sets searched for them.
     */
    private const MAX_CONTINUATIONS = 8;

    private const MAX_CONTINUATION_SETS = 64;

    /**
     * The most ways an acyclic stretch may read one input: a margin under
     * the default backtrack limit of 1,000,000.
     */
    private const MAX_ACYCLIC_AMBIGUITY = 1024;

    /**
     * @var array<int, int> the cyclic component of each reading item in one
     */
    private array $component = [];

    /**
     * @var list<list<int>> the components with a cycle, their items ascending,
     *                      from the start of the pattern
     */
    private array $cyclic = [];

    /**
     * @var array<string, list<int>|false> the rejecting suffix found from a set
     *                                     of items, false when there is none
     */
    private array $suffixes = [];

    /**
     * @var list<list<int>>
     */
    private array $literals = [];

    /**
     * The engine, asked about a witness through a lookaround; null when the
     * search holds none, or no pattern is given.
     */
    private ?WitnessCheck $check = null;

    /**
     * @var list<list<int>> what a lookbehind at the attempt start asks to
     *                      read before it, tried before the prefix
     */
    private array $leads = [];

    /**
     * @var list<list<int>> what a lookahead asks to read after it, tried
     *                      after a success the model leaves undecided
     */
    private array $ahead = [];

    /**
     * @var array<int, int> an item of each ambiguity left without witness,
     *                      by its offset in the pattern
     */
    private array $unwitnessedItems = [];

    /**
     * @var array<int, true>|null the components whose links alone are
     *                            searched, when set
     */
    private ?array $onlyWithin = null;

    /**
     * @var list<list<int>>|null every strongly connected component of the
     *                           reading items, once computed
     */
    private ?array $fullComponents = null;

    /**
     * @var array<string, array<int, true>> the items reading a class of each
     *                                      shared alphabet
     */
    private array $usable = [];

    private readonly int $size;

    public function __construct(private readonly ItemAutomaton $automaton, private readonly Budget $budget)
    {
        $this->size = $automaton->count();
    }

    /**
     * Whether the search reads some input in two ways at all, witness or
     * not.
     */
    public function isAmbiguous(): bool
    {
        $this->components(false);
        foreach ($this->cyclic as $members) {
            if ($this->exponentialCandidates($members)->valid()) {
                return true;
            }
        }

        return [] !== $this->links();
    }

    /**
     * An upper bound on the steps one attempt takes, as the degree d of
     * n^d, or null when an exponential ambiguity may exist. Without a state
     * that has two distinct paths back to itself on one word, two items of a
     * strongly connected component are joined by at most one path on any
     * word (Weber and Seidl, 1991): a path crosses at most c cyclic
     * components, so an attempt follows at most n^c partial paths, the nodes
     * of a backtracking matcher's search tree. The automaton reads at least
     * what the pattern reads, so the bound holds for the pattern.
     */
    public function stepBound(): ?int
    {
        $this->components(false);
        foreach ($this->cyclic as $members) {
            if ($this->splittingComponents($members)->valid()) {
                return null;
            }
        }

        // The most cyclic components a path crosses, over the components in
        // topological order, the last first.
        $edges = $this->edges([]);
        $components = $this->stronglyConnected($edges);
        $of = [];
        foreach ($components as $index => $members) {
            foreach ($members as $member) {
                $of[$member] = $index;
            }
        }

        $longest = [];
        for ($index = \count($components) - 1; $index >= 0; $index--) {
            $after = 0;
            foreach ($components[$index] as $member) {
                foreach ($edges[$member] as $next) {
                    if ($of[$next] !== $index) {
                        $after = max($after, $longest[$of[$next]]);
                    }
                }
            }

            $longest[$index] = $after + (isset($this->component[$components[$index][0]]) ? 1 : 0);
        }

        return [] === $longest ? 0 : max($longest);
    }

    /**
     * The worst ambiguity: its class, the degree of a polynomial, the
     * witness as characters (prefix, pump, suffix), other suffixes that also
     * reject for the replay, the suffix to publish, whether the pump crosses
     * an over-approximated atomic body, and the offset of an ambiguity left
     * without witness (then the class is that ambiguity's, not proven);
     * null when every attempt is linear.
     *
     * When the search holds a lookaround, the model leaves it undecided: a
     * witness is then asked of the engine too, its attempt pinned where it
     * starts. It must fail there, and, when the way to the ambiguity
     * crosses a lookaround, match once the suffix is replaced with one the
     * search accepts: the lookarounds on the way hold.
     *
     * @param list<list<int>> $literals literals every match of the search
     *                                  holds, which PCRE looks for before it
     *                                  backtracks, the likeliest first
     * @param list<list<int>> $leads    what a lookbehind at the attempt start
     *                                  asks to read before it
     * @param list<list<int>> $ahead    what a lookahead asks to read after it
     *
     * @throws ModelLimit
     *
     * @return Verdict|null
     */
    public function find(array $literals = [], ?WitnessCheck $check = null, array $leads = [], array $ahead = []): ?array
    {
        $this->literals = $literals;
        $this->check = $check;
        $this->leads = $leads;
        $this->ahead = $ahead;
        $this->components(false);
        $this->checkAcyclicAmbiguity();
        $cyclic = $this->cyclic;
        $component = $this->component;
        $this->components(true);

        // A pump through an over-approximated atomic body, or resting only on
        // loops the model made of bounded repeats, proves nothing: it counts
        // only when no exact pump of the same class exists.
        $approximated = null;
        $unwitnessed = null;
        foreach ($this->cyclic as $members) {
            foreach ($this->exponentialCandidates($members) as [$state, $labels, $crosses, $real, $lookaround]) {
                $inexact = $crosses || !$real;
                if ($lookaround) {
                    // The pump crosses a lookaround whose condition the
                    // witness is not checked against.
                    $unwitnessed ??= $state;

                    continue;
                }

                if (($inexact && null !== $approximated) || $this->disproved($state)) {
                    continue;
                }

                $witness = $this->witness($state, $this->pumps($labels));
                if (null === $witness) {
                    $unwitnessed ??= $state;

                    continue;
                }

                $verdict = $this->verdict(RedosComplexity::Exponential, null, $witness, $inexact);
                if (!$inexact) {
                    return $verdict;
                }

                $approximated = $verdict;
            }
        }

        if (null !== $approximated) {
            return $approximated;
        }

        if (null !== $unwitnessed) {
            return $this->unwitnessed(RedosComplexity::Exponential, null, $unwitnessed);
        }

        // Chains across the components a certain success separates, then
        // the polynomial search over every item: the loops of a branch PCRE
        // tries and abandons before a certain success count toward the degree.
        $split = $this->polynomial();
        $separated = $this->cyclic;
        $this->cyclic = $cyclic;
        $this->component = $component;
        if ($separated === $cyclic) {
            return $split;
        }

        // Only a component a certain success split or removed holds anything
        // new: the links touching it, a branch tried and abandoned around it.
        $kept = array_fill_keys(array_map(static fn (array $members): string => implode(',', $members), $separated), true);
        $this->onlyWithin = [];
        foreach ($cyclic as $index => $members) {
            if (!isset($kept[implode(',', $members)])) {
                $this->onlyWithin[$index] = true;
            }
        }

        $whole = $this->polynomial();
        $this->onlyWithin = null;

        return self::worse($split, $whole);
    }

    /**
     * An item of the ambiguity left without witness at the offset, from the
     * last search.
     */
    public function unwitnessedItem(int $offset): ?int
    {
        return $this->unwitnessedItems[$offset] ?? null;
    }

    /**
     * The worse of two polynomial verdicts, the second over every item: the
     * higher degree; at a tie, a witnessed exact one.
     *
     * @param Verdict|null $first
     * @param Verdict|null $second
     *
     * @return Verdict|null
     */
    private static function worse(?array $first, ?array $second): ?array
    {
        if (null === $first || null === $second) {
            return $first ?? $second;
        }

        // An unwitnessed link over every item is a branch tried and abandoned
        // around a loop; chains inside one component are not counted, so its
        // degree is a floor: no proven degree stands beside it.
        if (null !== $second['unwitnessed']) {
            return $second;
        }

        if (($first['degree'] ?? 0) !== ($second['degree'] ?? 0)) {
            return ($first['degree'] ?? 0) > ($second['degree'] ?? 0) ? $first : $second;
        }

        $firstExact = !$first['approximated'] && null === $first['unwitnessed'];

        return $firstExact ? $first : $second;
    }

    /**
     * An acyclic stretch read in more ways than the cap, written out copies
     * or nested options, costs PCRE that many tries per input: out of the
     * model, whose classes speak of growth with the input.
     *
     * @throws ModelLimit
     */
    private function checkAcyclicAmbiguity(): void
    {
        $automaton = $this->automaton;
        $cyclic = $this->component;
        $starts = [];
        foreach ($automaton->initials as $start) {
            $starts[] = $start['items'];
        }

        foreach ($this->cyclic as $members) {
            $exits = [];
            foreach ($members as $member) {
                foreach ($automaton->successors[$member] as $successor) {
                    if (!isset($cyclic[$successor])) {
                        $exits[] = $successor;
                    }
                }
            }

            $starts[] = array_values(array_unique($exits));
        }

        // The paths through the acyclic part, whatever they read, bound the
        // paths that read one input: under the cap, nothing to count.
        $paths = [];
        $within = true;
        foreach ($starts as $items) {
            $total = 0;
            foreach ($items as $item) {
                if (!$automaton->isFinal($item) && !isset($cyclic[$item])) {
                    $total = min(self::MAX_ACYCLIC_AMBIGUITY + 1, $total + $this->pathsFrom($item, $cyclic, $paths));
                }
            }

            $within = $within && $total <= self::MAX_ACYCLIC_AMBIGUITY;
        }

        if ($within) {
            return;
        }

        // When no list of items holds two reading the same class, every input
        // is read one way at most: nothing to count.
        $lists = $starts;
        for ($item = 0; $item < $this->size; $item++) {
            if (!$automaton->isFinal($item) && !isset($cyclic[$item])) {
                $lists[] = $automaton->successors[$item];
            }
        }

        $ambiguous = false;
        foreach ($lists as $list) {
            $union = null;
            foreach ($list as $item) {
                if ($automaton->isFinal($item) || isset($cyclic[$item])) {
                    continue;
                }

                $mask = $automaton->masks[$item];
                if (null !== $union && !ItemAutomaton::isEmpty($union & $mask)) {
                    $ambiguous = true;

                    break 2;
                }

                $union = null === $union ? $mask : $union | $mask;
            }
        }

        if (!$ambiguous) {
            return;
        }

        // The classes each item reads, once.
        $reading = [];
        for ($item = 0; $item < $this->size; $item++) {
            if ($automaton->isFinal($item) || isset($cyclic[$item])) {
                continue;
            }

            $reading[$item] = [];
            for ($class = 0; $class < $automaton->classCount(); $class++) {
                if (ItemAutomaton::has($automaton->masks[$item], $class)) {
                    $reading[$item][] = $class;
                }
            }
        }

        foreach ($starts as $items) {
            $vector = [];
            foreach ($items as $item) {
                if (isset($reading[$item])) {
                    $vector[$item] = ($vector[$item] ?? 0) + 1;
                }
            }

            $queue = [] === $vector ? [] : [$vector];
            $seen = [];
            for ($head = 0; $head < \count($queue); $head++) {
                $current = $queue[$head];
                if (array_sum($current) > self::MAX_ACYCLIC_AMBIGUITY) {
                    throw ModelLimit::outOfModel(\sprintf('An acyclic stretch read in more than %d ways', self::MAX_ACYCLIC_AMBIGUITY));
                }

                // Every class's next vector, in one pass over the items.
                $nexts = [];
                foreach ($current as $item => $count) {
                    $this->budget->step(1 + \count($reading[$item]));
                    foreach ($reading[$item] as $class) {
                        foreach ($automaton->successors[$item] as $successor) {
                            if (isset($reading[$successor])) {
                                $nexts[$class][$successor] = min(self::MAX_ACYCLIC_AMBIGUITY + 1, ($nexts[$class][$successor] ?? 0) + $count);
                            }
                        }
                    }
                }

                foreach ($nexts as $next) {
                    ksort($next);
                    $key = implode(',', array_keys($next)).'|'.implode(',', $next);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $queue[] = $next;
                    }
                }
            }
        }
    }

    /**
     * How many paths start at the item and stay in the acyclic part, capped
     * just past the most ways allowed.
     *
     * @param array<int, int> $cyclic
     * @param array<int, int> $paths  the counts found so far
     *
     * @param-out array<int, int> $paths
     */
    private function pathsFrom(int $item, array $cyclic, array &$paths): int
    {
        if (isset($paths[$item])) {
            return $paths[$item];
        }

        // The item and every item after it; a cycle never meets here, as the
        // cyclic items are left out.
        /** @var list<array{int, bool}> $stack */
        $stack = [[$item, false]];
        while ([] !== $stack) {
            [$current, $expanded] = array_pop($stack);
            if (isset($paths[$current])) {
                continue;
            }

            $next = array_filter(
                $this->automaton->successors[$current],
                fn (int $successor): bool => !$this->automaton->isFinal($successor) && !isset($cyclic[$successor]),
            );
            if (!$expanded) {
                $stack[] = [$current, true];
                foreach ($next as $successor) {
                    if (!isset($paths[$successor])) {
                        $stack[] = [$successor, false];
                    }
                }

                continue;
            }

            $this->budget->step(1 + \count($next));
            $count = 1;
            foreach ($next as $successor) {
                $count = min(self::MAX_ACYCLIC_AMBIGUITY + 1, $count + ($paths[$successor] ?? 0));
            }

            $paths[$current] = $count;
        }

        return $paths[$item] ?? 1;
    }

    /**
     * @param Witness $witness
     *
     * @return Verdict
     */
    private function verdict(RedosComplexity $complexity, ?int $degree, array $witness, bool $crosses): array
    {
        return ['complexity' => $complexity, 'degree' => $degree, ...$witness, 'approximated' => $crosses, 'unwitnessed' => null];
    }

    /**
     * @return Verdict
     */
    private function unwitnessed(RedosComplexity $complexity, ?int $degree, int $state): array
    {
        $this->unwitnessedItems[$this->automaton->offsetOf($state)] ??= $state;

        return [
            'complexity' => $complexity,
            'degree' => $degree,
            'prefix' => [],
            'pump' => [],
            'suffix' => [],
            'alternatives' => [],
            'published' => [],
            'withoutMatches' => false,
            'approximated' => false,
            'unwitnessed' => $this->automaton->offsetOf($state),
        ];
    }

    /**
     * Whether no witness can exist for an ambiguity of the item: after it
     * reads, every continuation is accepted.
     */
    private function disproved(int $state): bool
    {
        $after = array_fill_keys($this->automaton->successors[$state], true);
        ksort($after);

        return null === $this->rejectingSuffix($after);
    }

    /**
     * @param list<int> $classes
     *
     * @return list<int>
     */
    private function representativesOf(array $classes): array
    {
        $characters = [];
        foreach ($classes as $class) {
            $characters[] = $this->automaton->representatives[$class];
        }

        return $characters;
    }

    /**
     * The states of a component with two distinct paths back to themselves
     * on one word, and the labels of that word: the pairs of states a word
     * leads to from the same state, where a strongly connected set of pairs
     * holds both a state paired with itself and two paths that split.
     *
     * @param list<int> $members
     *
     * @return \Generator<int, array{int, list<string>, bool, bool, bool}>
     */
    private function exponentialCandidates(array $members): \Generator
    {
        foreach ($this->splittingComponents($members) as [$diagonal, $within]) {
            foreach ($diagonal as $state) {
                $cycle = $this->splittingCycle($state, $within);
                if (null !== $cycle) {
                    yield [$state, ...$cycle];
                }
            }
        }
    }

    /**
     * The strongly connected sets of pairs of states a word leads to from
     * the same state that hold both a state paired with itself and two paths
     * that split: each is an exponential ambiguity, the states paired with
     * themselves listed.
     *
     * @param list<int> $members
     *
     * @return \Generator<int, array{list<int>, array<int, true>}>
     */
    private function splittingComponents(array $members): \Generator
    {
        $automaton = $this->automaton;
        $size = $this->size;
        $inside = array_fill_keys($members, true);

        /** @var array<int, list<int>> $edges */
        $edges = [];
        /** @var array<int, true> $splits */
        $splits = [];
        $queue = [];
        foreach ($members as $state) {
            $node = $state * $size + $state;
            $edges[$node] = [];
            $queue[] = $node;
        }

        for ($head = 0; $head < \count($queue); $head++) {
            $this->budget->step();
            $node = $queue[$head];
            $left = intdiv($node, $size);
            $right = $node % $size;
            foreach ($automaton->successors[$left] as $leftIndex => $nextLeft) {
                if (!isset($inside[$nextLeft])) {
                    continue;
                }

                foreach ($automaton->successors[$right] as $rightIndex => $nextRight) {
                    // From a state paired with itself, each pair of paths once.
                    if (!isset($inside[$nextRight]) || ($left === $right && $leftIndex > $rightIndex)) {
                        continue;
                    }

                    $this->budget->step();
                    if (ItemAutomaton::isEmpty($automaton->masks[$nextLeft] & $automaton->masks[$nextRight])) {
                        continue;
                    }

                    $next = $nextLeft * $size + $nextRight;
                    $edges[$node][] = $next;
                    if ($left === $right && $leftIndex !== $rightIndex) {
                        $splits[$next] = true;
                    }

                    if (!isset($edges[$next])) {
                        $edges[$next] = [];
                        $queue[] = $next;
                    }
                }
            }
        }

        if ([] === $splits) {
            return;
        }

        foreach ($this->stronglyConnected($edges) as $component) {
            $diagonal = [];
            $split = false;
            foreach ($component as $node) {
                $left = intdiv($node, $size);
                if ($left === $node % $size) {
                    $diagonal[] = $left;
                } else {
                    $split = true;
                }

                $split = $split || isset($splits[$node]);
            }

            if ([] === $diagonal || !$split) {
                continue;
            }

            sort($diagonal);
            yield [$diagonal, array_fill_keys($component, true)];
        }
    }

    /**
     * The labels of the shortest word on which the state has two distinct
     * paths back to itself, through the given pairs only, and whether either
     * path reads inside an over-approximated atomic body.
     *
     * @param array<int, true> $within
     *
     * @return array{list<string>, bool, bool, bool}|null
     */
    private function splittingCycle(int $state, array $within): ?array
    {
        return $this->cycleThrough($state, $within);
    }

    /**
     * @param array<int, true> $within
     *
     * @return array{list<string>, bool, bool, bool}|null the labels, whether a
     *                                                    path crosses an
     *                                                    over-approximated atomic
     *                                                    body, whether it goes
     *                                                    back into a loop of the
     *                                                    pattern, whether it
     *                                                    crosses a lookaround
     */
    private function cycleThrough(int $state, array $within): ?array
    {
        $automaton = $this->automaton;
        $size = $this->size;
        $origin = (($state * $size + $state) * 2) * 2;
        // A cycle through a loop of the pattern first; one through the
        // model's loops only when there is none.
        $target = (($state * $size + $state) * 2 + 1) * 2 + 1;
        $fallback = $target - 1;
        $found = null;
        $parents = [$origin => null];
        $queue = [[$state, $state, 0, 0, $automaton->isApproximated($state), false]];

        for ($head = 0; $head < \count($queue); $head++) {
            $this->budget->step();
            [$left, $right, $diverged, $real, $crosses, $lookaround] = $queue[$head];
            $key = (($left * $size + $right) * 2 + $diverged) * 2 + $real;
            $label = $automaton->masks[$left] & $automaton->masks[$right];

            foreach ($automaton->successors[$left] as $leftIndex => $nextLeft) {
                foreach ($automaton->successors[$right] as $rightIndex => $nextRight) {
                    if (!isset($within[$nextLeft * $size + $nextRight])) {
                        continue;
                    }

                    $this->budget->step();
                    $edge = $automaton->edges[$left][$leftIndex] | $automaton->edges[$right][$rightIndex];
                    $split = 1 === $diverged || ($left === $right && $leftIndex !== $rightIndex) ? 1 : 0;
                    $loop = 1 === $real || 0 !== ($edge & ItemAutomaton::EDGE_LOOP) ? 1 : 0;
                    $next = (($nextLeft * $size + $nextRight) * 2 + $split) * 2 + $loop;
                    if (\array_key_exists($next, $parents)) {
                        continue;
                    }

                    $parents[$next] = [$key, $label];
                    $crossing = $crosses || $automaton->isApproximated($nextLeft) || $automaton->isApproximated($nextRight);
                    $looking = $lookaround || 0 !== ($edge & ItemAutomaton::EDGE_LOOKAROUND);
                    if ($next === $target) {
                        return [$this->labels($parents, $target), $crossing, true, $looking];
                    }

                    if ($next === $fallback) {
                        $found ??= [$this->labels($parents, $fallback), $crossing, false, $looking];
                    }

                    $queue[] = [$nextLeft, $nextRight, $split, $loop, $crossing, $looking];
                }
            }
        }

        return $found;
    }

    /**
     * @return Verdict|null
     */
    private function polynomial(): ?array
    {
        $links = $this->links();
        $longest = [];
        $best = null;
        $unwitnessed = null;
        foreach ($links as $targets) {
            foreach ($targets as $to => [$state, $labels, $crosses, $real, $lookaround]) {
                $degree = 2 + $this->longestChain($to, $links, $longest);
                $inexact = $crosses || !$real;
                // An exact pump wins a tie with an approximated one.
                if (null !== $best && ($degree < $best['degree'] || ($degree === $best['degree'] && ($inexact || !$best['approximated'])))) {
                    continue;
                }

                $witness = $lookaround ? null : $this->witness($state, $this->pumps($labels));
                if (null !== $witness) {
                    $best = $this->verdict(RedosComplexity::Polynomial, $degree, $witness, $inexact);
                } elseif (null === $unwitnessed || $degree > $unwitnessed[0]) {
                    $unwitnessed = [$degree, $state];
                }
            }
        }

        if (null !== $unwitnessed && (null === $best || $unwitnessed[0] > $best['degree'])) {
            return $this->unwitnessed(RedosComplexity::Polynomial, $unwitnessed[0], $unwitnessed[1]);
        }

        return $best;
    }

    /**
     * Every pair of cyclic components with a polynomial link: a state of the
     * first with a loop on a word, a path on the same word to a state of the
     * second, and a loop there on it.
     *
     * @return array<int, array<int, array{int, list<string>, bool, bool, bool, int}>>
     */
    private function links(): array
    {
        $count = \count($this->cyclic);
        if (0 === $count) {
            return [];
        }

        $alphabets = [];
        foreach ($this->cyclic as $index => $members) {
            $alphabet = $this->automaton->masks[$members[0]];
            foreach ($members as $member) {
                $alphabet |= $this->automaton->masks[$member];
            }

            $alphabets[$index] = $alphabet;
        }

        $reachable = $this->componentReachability($alphabets);

        $links = [];
        for ($from = 0; $from < $count; $from++) {
            for ($to = 0; $to < $count; $to++) {
                if (null !== $this->onlyWithin && !isset($this->onlyWithin[$from]) && !isset($this->onlyWithin[$to])) {
                    continue;
                }

                // Inside one component, two distinct states count too: a run
                // tried and abandoned at every step of the loop around it.
                if ($from !== $to && !isset($reachable[$from][$to])) {
                    continue;
                }

                if ($from === $to && 2 > \count($this->cyclic[$from])) {
                    continue;
                }

                $common = $alphabets[$from] & $alphabets[$to];
                if (ItemAutomaton::isEmpty($common)) {
                    continue;
                }

                $link = $this->link($this->cyclic[$from], $this->cyclic[$to], $common);
                if (null !== $link) {
                    $links[$from][$to] = $link;
                }
            }
        }

        return $links;
    }

    /**
     * @param array<int, array<int, array{int, list<string>, bool, bool, bool, int}>> $links
     * @param array<int, int>                                                         $longest
     */
    private function longestChain(int $component, array $links, array &$longest): int
    {
        if (isset($longest[$component])) {
            return $longest[$component];
        }

        // Two components may link both ways through a character the
        // components leave out: a chain never comes back to one it holds.
        $longest[$component] = 0;
        $best = 0;
        foreach ($links[$component] ?? [] as $to => $_) {
            if ($to !== $component) {
                $best = max($best, 1 + $this->longestChain($to, $links, $longest));
            }
        }

        return $longest[$component] = $best;
    }

    /**
     * A state of the first component with a loop on a word, a path on the
     * same word to a state of the second one, and a loop there on it: the
     * state, the labels of the word, and whether a path reads inside an
     * over-approximated atomic body. Every item on the way reads a class both
     * loops read.
     *
     * @param list<int> $from
     * @param list<int> $to
     *
     * @return array{int, list<string>, bool, bool, bool, int}|null the state, the
     *                                                              labels, whether a path
     *                                                              crosses an
     *                                                              over-approximated
     *                                                              atomic body, whether
     *                                                              the loops are the
     *                                                              pattern's, whether a
     *                                                              path crosses a
     *                                                              lookaround
     */
    private function link(array $from, array $to, string $common): ?array
    {
        $automaton = $this->automaton;
        $size = $this->size;
        $usable = static fn (int $item): bool => !$automaton->isFinal($item) && !ItemAutomaton::isEmpty($automaton->masks[$item] & $common);

        // The items reading only shared classes: without a path through them
        // from the first component to the second, no link. Inside one
        // component, the second state is a run, looping on itself.
        $same = $from === $to;
        $starts = array_values(array_filter($from, $usable));
        $between = array_fill_keys($starts, true);
        $targets = array_fill_keys(array_values(array_filter(
            $to,
            static fn (int $item): bool => $usable($item) && (!$same || \in_array($item, $automaton->successors[$item], true)),
        )), true);
        if ($same) {
            $reached = [] !== $targets;
        }
        $queue = $starts;
        $reached ??= false;
        for ($head = 0; $head < \count($queue) && !$reached; $head++) {
            $this->budget->step();
            foreach ($automaton->successors[$queue[$head]] as $successor) {
                if (isset($between[$successor]) || !$usable($successor)) {
                    continue;
                }

                $between[$successor] = true;
                $queue[] = $successor;
                $reached = $reached || isset($targets[$successor]);
            }
        }

        if (!$reached) {
            return null;
        }

        // Every item reading a shared class may lie between.
        if (!isset($this->usable[$common])) {
            $this->usable[$common] = [];
            for ($item = 0; $item < $size; $item++) {
                if ($usable($item)) {
                    $this->usable[$common][$item] = true;
                }
            }
        }

        $between += $this->usable[$common];

        // A run whose every continuation succeeds is never tried and
        // abandoned: no work in it, whatever comes before.
        foreach ($targets as $end => $_) {
            if ($this->disproved($end)) {
                unset($targets[$end]);
            }
        }

        $inFrom = array_fill_keys($starts, true);
        foreach ($starts as $start) {
            foreach ($targets as $end => $_) {
                if ($start === $end) {
                    continue;
                }

                $target = ($start * $size + $end) * $size + $end;
                $parents = [($start * $size + $start) * $size + $end => null];
                $queue = [[$start, $start, $end, $automaton->isApproximated($start) || $automaton->isApproximated($end), false, false]];
                for ($head = 0; $head < \count($queue); $head++) {
                    $this->budget->step();
                    [$x, $y, $z, $crosses, $real, $lookaround] = $queue[$head];
                    $key = ($x * $size + $y) * $size + $z;
                    $label = $automaton->masks[$x] & $automaton->masks[$y] & $automaton->masks[$z];
                    if (ItemAutomaton::isEmpty($label)) {
                        continue;
                    }

                    foreach ($automaton->successors[$x] as $xIndex => $x2) {
                        if (!isset($inFrom[$x2])) {
                            continue;
                        }

                        foreach ($automaton->successors[$y] as $yIndex => $y2) {
                            if (!isset($between[$y2])) {
                                continue;
                            }

                            foreach ($automaton->successors[$z] as $zIndex => $z2) {
                                if (!isset($targets[$z2])) {
                                    continue;
                                }

                                $loops = $automaton->edges[$x][$xIndex] | $automaton->edges[$z][$zIndex];
                                $edge = $loops | $automaton->edges[$y][$yIndex];

                                $this->budget->step();
                                $next = ($x2 * $size + $y2) * $size + $z2;
                                if (\array_key_exists($next, $parents)) {
                                    continue;
                                }

                                $parents[$next] = [$key, $label];
                                $crossing = $crosses || $automaton->isApproximated($x2)
                                    || $automaton->isApproximated($y2) || $automaton->isApproximated($z2);
                                $loop = $real || 0 !== ($loops & ItemAutomaton::EDGE_LOOP);
                                $looking = $lookaround || 0 !== ($edge & ItemAutomaton::EDGE_LOOKAROUND);
                                if ($next === $target) {
                                    return [$start, $this->labels($parents, $target), $crossing, $loop, $looking, $end];
                                }

                                $queue[] = [$x2, $y2, $z2, $crossing, $loop, $looking];
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * The labels along the parent links, from the first step.
     *
     * @param array<int, array{int, string}|null> $parents
     *
     * @return list<string>
     */
    private function labels(array $parents, int $target): array
    {
        $labels = [];
        for ($key = $target; null !== $parents[$key]; $key = $parents[$key][0]) {
            $labels[] = $parents[$key][1];
        }

        return array_reverse($labels);
    }

    /**
     * The words a pump may be, as classes: when one class fits every step,
     * that class repeated, for each such class; otherwise each step's first
     * class, then each step's second, and so on.
     *
     * @param list<string> $labels
     *
     * @return list<list<int>>
     */
    private function pumps(array $labels): array
    {
        $common = $labels[0];
        foreach ($labels as $label) {
            $common &= $label;
        }

        $pumps = [];
        if (!ItemAutomaton::isEmpty($common)) {
            for ($class = 0; $class < $this->automaton->classCount() && \count($pumps) < self::MAX_PUMPS; $class++) {
                if (ItemAutomaton::has($common, $class)) {
                    $pumps[] = array_fill(0, \count($labels), $class);
                }
            }

            return $pumps;
        }

        $classes = [];
        foreach ($labels as $step => $label) {
            for ($class = 0; $class < $this->automaton->classCount(); $class++) {
                if (ItemAutomaton::has($label, $class)) {
                    $classes[$step][] = $class;
                }
            }
        }

        for ($rank = 0; \count($pumps) < self::MAX_PUMPS; $rank++) {
            $pump = [];
            $fresh = false;
            foreach ($classes as $choices) {
                $pump[] = $choices[min($rank, \count($choices) - 1)];
                $fresh = $fresh || $rank < \count($choices);
            }

            if (!$fresh) {
                break;
            }

            $pumps[] = $pump;
        }

        return $pumps;
    }

    /**
     * A prefix reaching the state before any success of a path tried
     * earlier, a pump, and a suffix that, after the pump repeated any number
     * of times, no path tried up to that state accepts; tried over the
     * prefixes and pumps until one holds. The prefix is in characters, the
     * pump and the suffixes in classes.
     *
     * @param list<list<int>> $pumps
     *
     * @return Witness|null
     */
    private function witness(int $state, array $pumps): ?array
    {
        // A lookbehind at the attempt start may fail where the subject
        // starts: the witness may then be led by what it asks for.
        foreach ([[], ...$this->leads] as $lead) {
            $context = [] === $lead ? null : $this->automaton->contextAfter($lead[\count($lead) - 1]);
            foreach ($this->prefixes($state) as [$initial, $start, $prefix, $refusing]) {
                if (null !== $context && \count($this->automaton->initials) > 1 && $start !== $context) {
                    continue;
                }

                foreach ($pumps as $pump) {
                    $found = $this->witnessFrom($state, $initial, $start, $prefix, $pump, $lead, $refusing);
                    if (null !== $found) {
                        return [...$found, 'withoutMatches' => $refusing];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<int> $initial the attempt start's items
     * @param list<int> $prefix  classes
     * @param list<int> $pump    classes
     * @param list<int> $lead    characters read before the attempt starts
     *
     * @return array{prefix: list<int>, pump: list<int>, suffix: list<int>, alternatives: list<list<int>>, published: list<int>}|null
     */
    private function witnessFrom(int $state, array $initial, int $context, array $prefix, array $pump, array $lead = [], bool $refusing = false): ?array
    {
        $automaton = $this->automaton;
        $threads = array_values(array_unique($initial));
        // Whether the way to the ambiguity may cross a lookaround.
        $crosses = [] !== $lead;
        foreach ($automaton->initials as $start) {
            $crosses = $crosses || ($start['context'] === $context && [] !== $start['marks']);
        }

        // Whether a path met may succeed where the model leaves it undecided.
        $undecided = false;
        foreach ($prefix as $class) {
            foreach ($threads as $thread) {
                $crosses = $crosses || ([] !== ($automaton->marksAfter[$thread] ?? []));
            }

            $undecided = $undecided || $automaton->holdsUndecided(array_fill_keys($threads, true));
            $threads = $automaton->stepOrdered($threads, $class);
        }

        $undecided = $undecided || $automaton->holdsUndecided(array_fill_keys($threads, true));

        $position = array_search($state, $threads, true);
        if (false === $position) {
            return null;
        }

        // The paths PCRE tries before this one, and this one: each must fail.
        $current = array_fill_keys(\array_slice($threads, 0, $position + 1), true);
        ksort($current);
        $seen = [self::key($current) => 0];
        $boundaries = [$current];
        while (true) {
            foreach ($pump as $class) {
                $current = $automaton->stepSet($current, $class);
                if (null === $current) {
                    return null;
                }

                $undecided = $undecided || $automaton->holdsUndecided($current);
            }

            $key = self::key($current);
            if (isset($seen[$key])) {
                $periodic = \array_slice($boundaries, $seen[$key]);

                break;
            }

            $seen[$key] = \count($boundaries);
            $boundaries[] = $current;
        }

        $after = [];
        foreach ($periodic as $boundary) {
            $after += $boundary;
        }
        ksort($after);

        $suffix = $this->rejectingSuffix($after);
        if (null === $suffix) {
            return null;
        }

        // The engine may accept at the end what the model leaves undecided
        // (a lookahead): a suffix that rejects with a character to read is
        // replayed too, and so is one followed by a literal PCRE requires.
        $alternatives = [];
        $nonEmpty = [] === $suffix ? $this->rejectingSuffix($after, true) : $suffix;
        // An end the model only rejects for want of deciding a lookahead may
        // accept on the engine: the witness then reads a character more.
        if ([] === $suffix && null !== $nonEmpty && $automaton->mayAcceptAtEnd($after)) {
            $suffix = $nonEmpty;
        }

        if (null !== $nonEmpty && $nonEmpty !== $suffix) {
            $alternatives[] = $this->representativesOf($nonEmpty);
        }

        $contextCharacter = $automaton->contextCharacter($context);
        $before = [] !== $lead ? $lead : (null === $contextCharacter ? [] : [$contextCharacter]);
        $characters = [...$before, ...$this->representativesOf($prefix)];
        $pumpCharacters = $this->representativesOf($pump);
        $withLiterals = $this->suffixesBeforeLiterals($after, $suffix, [...$characters, ...$pumpCharacters, ...$pumpCharacters]);
        foreach ($withLiterals as [$classes, $literal]) {
            $alternatives[] = [...$this->representativesOf($classes), ...$literal];
        }

        $suffixCharacters = $this->representativesOf($suffix);
        // When PCRE requires a literal, the witness carrying it is the one
        // likely to reproduce.
        $published = [] === $withLiterals ? $suffixCharacters : $alternatives[\count($alternatives) - \count($withLiterals)];
        $found = [
            'prefix' => $characters,
            'pump' => $pumpCharacters,
            'suffix' => $suffixCharacters,
            'alternatives' => $alternatives,
            'published' => $published,
        ];

        foreach ([$suffixCharacters, $published, ...$alternatives] as $candidate) {
            $undecided = $undecided || $this->meetsUndecided($after, $candidate);
        }

        return null === $this->check ? $found : $this->checked($this->check, $found, \count($before), $after, $crosses, $undecided && !$refusing);
    }

    /**
     * The witness as the engine sees it, its attempt pinned after the
     * characters before it: null when the way to the ambiguity crosses a
     * lookaround and no accepting continuation makes the attempt match.
     * When the witness fails only for a success the model leaves undecided,
     * the suffixes on which the attempt fails, the published one first;
     * null when it fails on none. Otherwise the model decided the failure
     * of every path tried up to the ambiguity, and a later one may still
     * match after the cost is paid: the witness stands as found.
     *
     * Without $matches, PHP retries an empty match, which the pinned
     * attempt does not: such a witness is not asked to fail.
     *
     * @param array{prefix: list<int>, pump: list<int>, suffix: list<int>, alternatives: list<list<int>>, published: list<int>} $found
     * @param array<int, true>                                                                                                  $after
     *
     * @return array{prefix: list<int>, pump: list<int>, suffix: list<int>, alternatives: list<list<int>>, published: list<int>}|null
     */
    private function checked(WitnessCheck $check, array $found, int $before, array $after, bool $crosses, bool $undecided): ?array
    {
        $unicode = $this->automaton->pnfa->unicode;
        $offset = \strlen(Utf8::encode(\array_slice($found['prefix'], 0, $before), $unicode));
        $head = Utf8::encode($found['prefix'], $unicode);
        $pump = Utf8::encode($found['pump'], $unicode);

        if ($crosses) {
            $reached = false;
            foreach ($this->continuations($after) as $continuation) {
                if (true === $check->matches($head.$pump.$pump.Utf8::encode($continuation, $unicode), $offset)) {
                    $reached = true;

                    break;
                }
            }

            if (!$reached) {
                return null;
            }
        }

        if (!$undecided) {
            return $found;
        }

        // The suffix with a literal PCRE requires is published when it
        // fails: the model may have picked the character before the literal
        // on a success it leaves undecided, so each one is tried.
        $candidates = [$found['published']];
        $held = ','.implode(',', [...$found['prefix'], ...$found['pump'], ...$found['pump']]).',';
        foreach (\array_slice($this->literals, 0, 2) as $literal) {
            if (!str_contains($held, ','.implode(',', $literal).',')) {
                foreach ($this->automaton->representatives as $character) {
                    $candidates[] = [...$found['suffix'], $character, ...$literal];
                }
            }
        }

        $failing = [];
        foreach ([...$candidates, $found['suffix'], ...$found['alternatives']] as $suffix) {
            if (\in_array($suffix, $failing, true)) {
                continue;
            }

            $tail = Utf8::encode($suffix, $unicode);
            foreach ([1, 2, 3] as $pumps) {
                if (false !== $check->matches($head.str_repeat($pump, $pumps).$tail, $offset)) {
                    continue 2;
                }
            }

            $failing[] = $suffix;
        }

        if ([] === $failing) {
            return null;
        }

        return [
            'prefix' => $found['prefix'],
            'pump' => $found['pump'],
            'suffix' => $failing[0],
            'alternatives' => \array_slice($failing, 1),
            'published' => $failing[0],
        ];
    }

    /**
     * Whether reading the characters from the set meets a success the model
     * leaves undecided.
     *
     * @param array<int, true> $items
     * @param list<int>        $characters
     */
    private function meetsUndecided(array $items, array $characters): bool
    {
        $automaton = $this->automaton;
        foreach ($characters as $character) {
            if ($automaton->holdsUndecided($items)) {
                return true;
            }

            $items = $automaton->stepSet($items, $automaton->classOf($character)) ?? [];
        }

        return $automaton->holdsUndecided($items);
    }

    /**
     * Continuations, as characters, after which the set may succeed: one
     * the model accepts, or one reaching a success it leaves undecided,
     * alone and followed by what each lookahead asks to read.
     *
     * @param array<int, true> $items
     *
     * @return list<list<int>>
     */
    private function continuations(array $items): array
    {
        $automaton = $this->automaton;
        $found = [];
        $queue = [[$items, []]];
        $seen = [self::key($items) => true];
        for ($head = 0; $head < \count($queue) && $head < self::MAX_CONTINUATION_SETS && \count($found) < self::MAX_CONTINUATIONS; $head++) {
            $this->budget->step();
            [$current, $path] = $queue[$head];
            $characters = $this->representativesOf($path);
            if ($automaton->acceptsAtEnd($current)) {
                $found[] = $characters;
            }

            if ($automaton->holdsUndecided($current)) {
                $found[] = $characters;
                foreach ($this->ahead as $word) {
                    $found[] = [...$characters, ...$word];
                }
            }

            for ($class = 0; $class < $automaton->classCount(); $class++) {
                $next = $automaton->stepSet($current, $class);
                if (null === $next) {
                    $found[] = [...$characters, $automaton->representatives[$class]];

                    continue;
                }

                $key = self::key($next);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $queue[] = [$next, [...$path, $class]];
                }
            }
        }

        return $found;
    }

    /**
     * The prefixes to try for the state, from each attempt start: the
     * shortest, then the shortest beginning with each class.
     *
     * @return list<array{list<int>, int, list<int>, bool}> the start's items,
     *                                                      its context, the
     *                                                      prefix, and whether
     *                                                      the start refuses an
     *                                                      empty success
     */
    private function prefixes(int $state): array
    {
        $automaton = $this->automaton;
        $prefixes = [];
        $seen = [];
        foreach ($automaton->initials as $start) {
            $initial = $start['items'];
            // Without $matches, PHP retries an attempt whose match is empty,
            // refusing the empty match: the start is also tried that way.
            $refusing = array_values(array_filter($initial, static fn (int $item): bool => !$automaton->isFinal($item)));
            if ($refusing !== $initial && null !== $this->automaton->pnfa->parent) {
                $refusing = $initial;
            }

            $first = [null];
            for ($class = 0; $class < $automaton->classCount(); $class++) {
                $first[] = $class;
            }

            foreach ($first as $class) {
                if (\count($prefixes) >= self::MAX_PREFIXES) {
                    break 2;
                }

                $path = $this->prefixTo($initial, $state, $class);
                $key = $start['context'].':'.(null === $path ? '-' : implode(',', $path));
                if (null === $path || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $prefixes[] = [$initial, $start['context'], $path, false];
                if ($refusing !== $initial) {
                    $prefixes[] = [$refusing, $start['context'], $path, true];
                }
            }
        }

        return $prefixes;
    }

    /**
     * The shortest input, as classes, after which the item is among the
     * items of the start, its first character in the class when one is given.
     *
     * @param list<int> $initial
     *
     * @return list<int>|null
     */
    private function prefixTo(array $initial, int $state, ?int $firstClass): ?array
    {
        $automaton = $this->automaton;
        $paths = [];
        $queue = [];
        if (null === $firstClass) {
            foreach ($initial as $item) {
                if (!isset($paths[$item])) {
                    $paths[$item] = [];
                    $queue[] = $item;
                }
            }
        } else {
            foreach ($initial as $item) {
                if ($automaton->isFinal($item) || !$automaton->reads($item, $firstClass)) {
                    continue;
                }

                foreach ($automaton->successors[$item] as $successor) {
                    if (!isset($paths[$successor])) {
                        $paths[$successor] = [$firstClass];
                        $queue[] = $successor;
                    }
                }
            }
        }

        for ($head = 0; $head < \count($queue); $head++) {
            $this->budget->step();
            $item = $queue[$head];
            if ($item === $state) {
                return $paths[$item];
            }

            if ($automaton->isFinal($item)) {
                continue;
            }

            $path = [...$paths[$item], ItemAutomaton::first($automaton->masks[$item])];
            foreach ($automaton->successors[$item] as $successor) {
                if (!isset($paths[$successor])) {
                    $paths[$successor] = $path;
                    $queue[] = $successor;
                }
            }
        }

        return null;
    }

    /**
     * For each literal, the rejecting suffix followed by it, with at least
     * one character before it (the one that ends the pump), the first that
     * still rejects: the classes before the literal, and the literal.
     *
     * A literal the prefix and the pump already hold needs no repeating.
     *
     * @param array<int, true> $items
     * @param list<int>        $suffix
     * @param list<int>        $witness the prefix and the pump twice, as characters
     *
     * @return list<array{list<int>, list<int>}>
     */
    private function suffixesBeforeLiterals(array $items, array $suffix, array $witness): array
    {
        $automaton = $this->automaton;
        $found = [];
        $held = ','.implode(',', $witness).',';
        foreach ($this->literals as $literal) {
            if (str_contains($held, ','.implode(',', $literal).',')) {
                continue;
            }

            $classes = array_map($automaton->classOf(...), $literal);
            $candidates = [];
            if ([] !== $suffix) {
                $candidates[] = $suffix;
            }

            for ($class = 0; $class < $automaton->classCount(); $class++) {
                $candidates[] = [...$suffix, $class];
            }

            $candidates[] = $suffix;
            foreach ($candidates as $candidate) {
                $current = $items;
                foreach ([...$candidate, ...$classes] as $class) {
                    $current = $automaton->stepSet($current, $class);
                    if (null === $current) {
                        continue 2;
                    }
                }

                if (!$automaton->acceptsAtEnd($current)) {
                    $found[] = [$candidate, $literal];

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The shortest input, as classes, after which no item of the set reaches
     * success, the end of the subject included; one character at least when
     * asked.
     *
     * @param array<int, true> $items
     *
     * @return list<int>|null
     */
    private function rejectingSuffix(array $items, bool $nonEmpty = false): ?array
    {
        $origin = self::key($items);
        if (isset($this->suffixes[$origin]) && (!$nonEmpty || false === $this->suffixes[$origin])) {
            return false === $this->suffixes[$origin] ? null : $this->suffixes[$origin];
        }

        $automaton = $this->automaton;
        $classes = $automaton->classCount();
        $queue = [[$items, []]];
        $seen = [$origin => true];

        for ($head = 0; $head < \count($queue); $head++) {
            $this->budget->step();
            [$current, $suffix] = $queue[$head];
            if ((!$nonEmpty || [] !== $suffix) && !$automaton->acceptsAtEnd($current)) {
                if ($nonEmpty) {
                    return $suffix;
                }

                return $this->suffixes[$origin] = $suffix;
            }

            for ($class = 0; $class < $classes; $class++) {
                $next = $automaton->stepSet($current, $class);
                if (null === $next) {
                    continue;
                }

                $key = self::key($next);
                if (isset($seen[$key])) {
                    continue;
                }

                // Known to reject nothing: neither does anything after it.
                if (false === ($this->suffixes[$key] ?? null)) {
                    continue;
                }

                $seen[$key] = true;
                $queue[] = [$next, [...$suffix, $class]];
            }
        }

        // Every set met rejects nothing either: all it reaches was searched.
        // The first one may still reject at once when a character was asked.
        foreach ($seen as $key => $_) {
            if (!$nonEmpty || $key !== $origin) {
                $this->suffixes[$key] = false;
            }
        }

        return null;
    }

    /**
     * The cyclic strongly connected components of the reading items, from
     * the start of the pattern. With $blocking, an item is left out when one
     * of the items after it succeeds before whatever character the loop reads
     * next: a pump through it is accepted at once, so no ambiguity through it
     * can make an attempt fail.
     */
    private function components(bool $blocking): void
    {
        $automaton = $this->automaton;
        $this->component = [];
        $this->cyclic = [];
        $edges = $this->edges([]);
        if ($blocking) {
            $within = [];
            foreach ($this->fullComponents ?? $this->stronglyConnected($edges) as $index => $members) {
                foreach ($members as $member) {
                    $within[$member] = $index;
                }
            }

            $blocked = [];
            foreach ($edges as $item => $next) {
                // The classes the loop may read after the item.
                $ahead = null;
                foreach ($next as $successor) {
                    if (($within[$successor] ?? -1) === ($within[$item] ?? -2)) {
                        $ahead = null === $ahead ? $automaton->masks[$successor] : $ahead | $automaton->masks[$successor];
                    }
                }

                foreach ($automaton->successors[$item] as $successor) {
                    if ($automaton->isFinal($successor) && $this->acceptsAll($successor, $ahead)) {
                        $blocked[$item] = true;

                        break;
                    }
                }
            }

            $edges = $this->edges($blocked);
        }

        $components = $this->stronglyConnected($edges);
        if (!$blocking) {
            $this->fullComponents = $components;
        }

        foreach ($components as $members) {
            if ([] === $members || (1 === \count($members) && !\in_array($members[0], $edges[$members[0]], true))) {
                continue;
            }

            sort($members);
            foreach ($members as $member) {
                $this->component[$member] = \count($this->cyclic);
            }

            $this->cyclic[] = $members;
        }
    }

    /**
     * Whether a final item succeeds before every class of the mask; before
     * anything at all when there is no mask.
     */
    private function acceptsAll(int $final, ?string $classes): bool
    {
        $automaton = $this->automaton;
        if ($automaton->isUnconditional($final)) {
            return true;
        }

        $peek = $automaton->peeks[$final];
        if (null === $classes || null === $peek || ItemAutomaton::isEmpty($peek)) {
            return null === $peek && null !== $classes;
        }

        return ($classes & $peek) === $classes;
    }

    /**
     * The reading items and their reading successors, the blocked ones left
     * out.
     *
     * @param array<int, true> $blocked
     *
     * @return array<int, list<int>>
     */
    private function edges(array $blocked): array
    {
        $automaton = $this->automaton;
        $edges = [];
        for ($item = 0; $item < $this->size; $item++) {
            if ($automaton->isFinal($item) || isset($blocked[$item])) {
                continue;
            }

            $edges[$item] = array_values(array_filter(
                $automaton->successors[$item],
                static fn (int $next): bool => !$automaton->isFinal($next) && !isset($blocked[$next]),
            ));
        }

        return $edges;
    }

    /**
     * Tarjan's strongly connected components, without recursion, in
     * topological order: a component comes before the ones it reaches.
     *
     * @param array<int, list<int>> $edges every node a key, its successors among the keys
     *
     * @return list<list<int>>
     */
    private function stronglyConnected(array $edges): array
    {
        $index = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $counter = 0;
        $components = [];

        foreach ($edges as $root => $_) {
            if (isset($index[$root])) {
                continue;
            }

            $work = [[$root, 0]];
            $index[$root] = $low[$root] = $counter++;
            $stack[] = $root;
            $onStack[$root] = true;

            while ([] !== $work) {
                $this->budget->step();
                $top = \count($work) - 1;
                [$node, $position] = $work[$top];
                $successors = $edges[$node];
                if ($position < \count($successors)) {
                    $work[$top][1]++;
                    $next = $successors[$position];
                    if (!isset($index[$next])) {
                        $index[$next] = $low[$next] = $counter++;
                        $stack[] = $next;
                        $onStack[$next] = true;
                        $work[] = [$next, 0];
                    } elseif (isset($onStack[$next])) {
                        $low[$node] = min($low[$node], $index[$next]);
                    }

                    continue;
                }

                array_pop($work);
                if ([] !== $work) {
                    $parent = $work[\count($work) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$node]);
                }

                if ($low[$node] === $index[$node]) {
                    $members = [];
                    while ([] !== $stack) {
                        $member = $stack[\count($stack) - 1];
                        array_pop($stack);
                        unset($onStack[$member]);
                        $members[] = $member;
                        if ($member === $node) {
                            break;
                        }
                    }

                    $components[] = $members;
                }
            }
        }

        // Tarjan closes a component after every component it reaches.
        return array_reverse($components);
    }

    /**
     * Which cyclic component reaches which through items that all read a
     * class the first one's loop reads: a link needs such a path.
     *
     * @param array<int, string> $alphabets the classes each component's loop reads
     *
     * @return array<int, array<int, true>>
     */
    private function componentReachability(array $alphabets): array
    {
        $automaton = $this->automaton;
        $reachable = [];
        foreach ($this->cyclic as $from => $members) {
            $alphabet = $alphabets[$from];
            $seen = array_fill_keys($members, true);
            $queue = $members;
            for ($head = 0; $head < \count($queue); $head++) {
                $this->budget->step();
                $item = $queue[$head];
                if (isset($this->component[$item]) && $this->component[$item] !== $from) {
                    $reachable[$from][$this->component[$item]] = true;
                }

                foreach ($automaton->successors[$item] as $successor) {
                    if (isset($seen[$successor]) || $automaton->isFinal($successor)
                        || ItemAutomaton::isEmpty($automaton->masks[$successor] & $alphabet)) {
                        continue;
                    }

                    $seen[$successor] = true;
                    $queue[] = $successor;
                }
            }
        }

        return $reachable;
    }

    /**
     * @param array<int, true> $items
     */
    private static function key(array $items): string
    {
        return implode(',', array_keys($items));
    }
}

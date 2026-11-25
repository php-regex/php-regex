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

namespace PHPRegex\Redos\Backtrack;

use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Redos\Exception\ModelLimitException;
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
 * @phpstan-type Verdict array{complexity: RedosComplexity, degree: int|null, prefix: list<int>, pump: list<int>, suffix: list<int>, alternatives: list<list<int>>, published: list<int>, withoutMatches: bool, approximated: bool, abstracted: bool, unwitnessed: int|null}
 */
final class AmbiguityFinder
{
    /**
     * The most paths one attempt may try at once on one input, the ways an
     * acyclic stretch reads it multiplied along the attempt: a margin under
     * the default backtrack limit of 1,000,000. A run abandoned inside a
     * loop is held to it too.
     */
    public const MAX_ACYCLIC_AMBIGUITY = 1024;

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
     * The most sets of items read to bound the runs abandoned inside one
     * loop: the reading stays cheap, and past it no bound is proven.
     */
    private const MAX_ABANDONED_RUN_SETS = 64;

    /**
     * The slots of what an attempt tries at once, besides the classes: what
     * stands in every class, and what stands at the attempt start, before
     * any character.
     */
    private const SLOT_ANY = -1;

    private const SLOT_START = -2;

    /**
     * @var array<int, bool> whether every run abandoned inside a strongly
     *                       connected component is short, by its first item
     */
    private array $boundedRuns = [];

    /**
     * @var array<int, true> the items of the components whose abandoned runs
     *                       are short
     */
    private array $shortRuns = [];

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

    /**
     * What checkWays() read, to count again sharper (sharpen()): the paths
     * that reach the search, the most routes round each loop, the stretches
     * from each attempt start and out of each item of a loop, and the items
     * of every loop; with what each stretch tries, as sharp as counted yet.
     *
     * @var array{int, array<int, int>, list<array{array<int, int>, int, array<int, int>, list<int>, array<int, true>|null}>, array<int, list<array{array<int, int>, int, array<int, int>, list<int>, array<int, true>|null}>>, array<int, int>}|null
     */
    private ?array $stretches = null;

    /**
     * @var array{list<array{array<int, int>, array<int, array<int, array<int, int>>>}>, array<int, list<array{array<int, int>, array<int, array<int, array<int, int>>>}>>}|null
     */
    private ?array $counts = null;

    /**
     * How the stretches are counted yet: whatever they read (0), one way at
     * most (1), on one input (2).
     */
    private int $sharpness = 0;

    /**
     * @var array{array<int, int>, array<int, int>, array<int, array<int, int>>} the leaves and the nodes under each acyclic item, and the routes from it to each item of a loop and through each mark
     */
    private array $whateverPaths = [[], [], []];

    /**
     * Whether the paths one attempt tries at once were counted, and whether
     * they go past the margin: then no attempt is proven linear.
     */
    private bool $counted = false;

    private bool $beyond = false;

    /**
     * @var array<int, list<int>> the classes each item of a loop reads, once
     *                            read
     */
    private array $entries = [];

    /**
     * @var array<int, array<int, int>> the epsilon paths from each reading
     *                                  item to each item after it, once read
     */
    private array $routesFrom = [];

    /**
     * @var array<int, array<int, int>> the routes from each reading item
     *                                  through each mark, once read
     */
    private array $marksFrom = [];

    /**
     * @var array<int, bool> whether reaching each item is a success the
     *                       engine stops at, once read
     */
    private array $successes = [];

    private readonly int $size;

    /**
     * Whether the search holds a bounded repeat read as unbounded or an
     * atomic body kept with every way through it: then a success it reads
     * may not be the engine's.
     */
    private readonly bool $widens;

    public function __construct(public readonly ItemAutomaton $automaton, private readonly Budget $budget)
    {
        $this->size = $automaton->count();
        $this->widens = [] !== $automaton->pnfa->abstractedLoops || [] !== $automaton->pnfa->keptWhole;
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
     * Where the first lookaround read in place stands whose loop a loop
     * outside it links to, on one word, both loops the pattern's own: the
     * body reads that word again from each position the outer loop gives,
     * a product of the two. Null when there is none. The search is the one
     * built with its lookarounds in place; whether a run in their bodies is
     * abandoned or not, the engine reads it.
     */
    public function rereadLookaround(): ?int
    {
        $pnfa = $this->automaton->pnfa;
        $this->components(false);
        $found = null;
        foreach ($this->links() as $from => $targets) {
            $outside = $this->lookaroundOf($from);
            foreach ($targets as $to => $_) {
                $inside = $this->lookaroundOf($to);
                if (null === $inside || $inside === $outside || !$this->loopsOwn($from) || !$this->loopsOwn($to)) {
                    continue;
                }

                $found = min($found ?? \PHP_INT_MAX, $pnfa->lookarounds[$inside]);
            }
        }

        return $found;
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
        $strong = StronglyConnectedComponents::of($edges, $this->budget);
        $components = $strong->components;
        $of = $strong->componentOf;

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

        if ([] === $longest) {
            return 0;
        }

        $bound = \max($longest);

        return \is_int($bound) ? $bound : 0;
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
     * @throws ModelLimitException
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
                    if (!$this->abandonsShortRuns($state)) {
                        $unwitnessed ??= $state;
                    }

                    continue;
                }

                $verdict = $this->verdict(RedosComplexity::Exponential, null, $witness, $inexact, !$crosses && !$real);
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
     * Whether one attempt tries more paths at once than the margin allows
     * (checkWays()): no attempt of the search is then proven linear, its cost
     * out of the model, whose classes speak of growth with the input. An
     * ambiguity found stands: the paths only add to its cost, and they are
     * counted only for a search found linear.
     *
     * @throws ModelLimitException
     */
    public function beyondMargin(): bool
    {
        if (!$this->counted) {
            $this->checkWays();
        }

        return $this->beyond;
    }

    /**
     * Counts the paths an attempt tries at once on one input, against the
     * margin of an acyclic stretch, the paths the attempt reaches the search
     * by included: a lookaround's body runs once for each path through its
     * mark. Past the margin, no attempt is proven linear (beyondMargin()).
     * An attempt that fails tries every path: every epsilon route, every way
     * an input is read, and every route that dies, at "\b" between two word
     * characters or at a success "(*NOTEMPTY)" refuses, which the engine
     * walks as it walks one that goes on. The paths of one stretch multiply
     * those of the next: a stretch that ends at a loop or at a lookaround
     * does not start the count again. Ten groups "(?:b{0}|)" on each side
     * of "a+" give one attempt 2^20 routes, and ten copies "(?:a|[a])"
     * before "x+" with ten groups after it 2^20 ways to read ten "a", an
     * "x" and a newline, each stretch within the margin. A loop is entered
     * by the ways that reach it at once, its routes round it counted once:
     * going round again is its own ambiguity.
     *
     * Paths that leave a loop at different times meet at one position: the
     * stretches out of a loop count every step they stand at at once, one
     * for each time the loop was left. "a+a{0,15}" gives "b+" after it
     * sixteen ways at once. Paths that enter a loop at different times go on
     * in it together, where it reads what brought the later ones. Paths
     * from stretches apart stand at once only after one character: what each
     * tries is counted by the class of the character read last (slots). Each
     * stretch is counted first whatever it reads, then, past the margin, by
     * the ways it reads one input: one way at most when no two items any
     * list holds read one class, or class by class.
     *
     * @param int $before the paths through the mark of the search, when it is a lookaround's body
     *
     * @throws ModelLimitException when the budget runs out
     *
     * @return array<int, int> the most paths through each sub-search's mark at once, for its body to start from
     */
    public function checkWays(int $before = 1): array
    {
        if (null === $this->fullComponents) {
            $this->components(false);
        }

        $this->counted = true;
        // An automaton that reads every input one way at most tries one list
        // at once: nothing to count further.
        $oneList = $this->oneListAtOnce();
        if (null !== $oneList) {
            $this->beyond = $before * $oneList > self::MAX_ACYCLIC_AMBIGUITY;

            return [];
        }

        $automaton = $this->automaton;
        $loops = $this->loopItems();
        // The stretches, with the routes to their first items, the routes
        // that die and those through each mark, the slots their first paths
        // stand in, and whether they start again at each step: from each
        // attempt start, once, and out of each item of a loop, after the
        // character it reads, each time the loop is left.
        $starts = [];
        foreach ($automaton->initials as $index => $start) {
            $starts[] = [self::routes($start['items'], $automaton->initialRoutes[$index] ?? []), $automaton->initialDead[$index] ?? 0, $automaton->initialMarkRoutes[$index] ?? [], [self::SLOT_START], null];
        }

        $exits = [];
        $rounds = [];
        $reads = [];
        foreach ($loops as $item => $loop) {
            $reads[$loop] = ($reads[$loop] ?? []) + array_fill_keys($this->slotsOf($item, false), true);
        }

        foreach ($loops as $item => $loop) {
            $own = array_fill_keys($this->fullComponents[$loop] ?? [], true);
            $routes = $this->routesOf($item);
            $exits[$loop][] = [array_diff_key($routes, $own), $automaton->dead[$item] ?? 0, $automaton->markRoutes[$item] ?? [], $this->slotsOf($item), $reads[$loop]];
            foreach (array_intersect_key($routes, $own) as $round) {
                $rounds[$loop] = max($rounds[$loop] ?? 1, $round);
            }
        }

        ksort($exits);
        $this->stretches = [$before, $rounds, $starts, $exits, $loops];
        $this->whateverPaths = $this->paths($loops);
        $this->counts = $this->each(null);
        $this->sharpness = 0;
        do {
            [$marks, $most] = $this->multiply($before, $rounds, ...$this->counts);
            $within = $most <= self::MAX_ACYCLIC_AMBIGUITY;
        } while (!$within && $this->sharpen());

        $this->beyond = !$within;

        return $marks;
    }

    /**
     * The most items one list holds, when every list, from an attempt start
     * or after an item, loops included, holds each item once, no two that
     * read one class, and no route that dies or goes through a mark: every
     * input is then read one way at most, and one list is tried at once.
     * Null otherwise.
     *
     * @throws ModelLimitException
     */
    private function oneListAtOnce(): ?int
    {
        $automaton = $this->automaton;
        if ([] !== $automaton->routes || [] !== $automaton->initialRoutes || [] !== $automaton->dead || [] !== $automaton->initialDead
            || [] !== $automaton->markRoutes || [] !== $automaton->initialMarkRoutes) {
            return null;
        }

        $most = 0;
        foreach ([...array_column($automaton->initials, 'items'), ...$automaton->successors] as $list) {
            $this->budget->step();
            $union = null;
            $met = [];
            foreach ($list as $item) {
                if (isset($met[$item])) {
                    return null;
                }

                $met[$item] = true;
                if ($automaton->isFinal($item)) {
                    continue;
                }

                $mask = $automaton->masks[$item];
                if (null !== $union && !ItemAutomaton::isEmpty($union & $mask)) {
                    return null;
                }

                $union = null === $union ? $mask : $union | $mask;
            }

            $most = max($most, \count($list));
        }

        return $most;
    }

    /**
     * Counts the stretches more sharply, when they can be: one way at most
     * when no list reads an input two ways (oneWay()), otherwise on one
     * input. False once counted on one input.
     *
     * @throws ModelLimitException
     */
    private function sharpen(): bool
    {
        if (null === $this->stretches || null === $this->counts || $this->sharpness >= 2) {
            return false;
        }

        $automaton = $this->automaton;
        [, , $starts, , $loops] = $this->stretches;
        [$tree] = $this->whateverPaths;
        if (0 === $this->sharpness) {
            $this->sharpness = 1;
            $lists = $starts;
            foreach ($loops as $item => $_) {
                $lists[] = [$this->routesOf($item), $automaton->dead[$item] ?? 0, $automaton->markRoutes[$item] ?? [], [], null];
            }

            foreach ($tree as $item => $_) {
                $lists[] = [$this->routesOf($item), $automaton->dead[$item] ?? 0, $automaton->markRoutes[$item] ?? [], [], null];
            }

            $oneWay = $this->oneWay($lists, $loops);
            if (null !== $oneWay) {
                [$fromStarts, $fromLoops] = $this->counts;
                foreach ($fromStarts as $index => $count) {
                    $fromStarts[$index] = self::within($count, $oneWay);
                }

                foreach ($fromLoops as $loop => $counts) {
                    foreach ($counts as $index => $count) {
                        $fromLoops[$loop][$index] = self::within($count, $oneWay);
                    }
                }

                $this->counts = [$fromStarts, $fromLoops];

                return true;
            }
        }

        $this->sharpness = 2;
        $reading = [];
        foreach ($tree as $item => $_) {
            $reading[$item] = $this->slotsOf($item, false);
        }

        $this->counts = $this->each($reading);

        return true;
    }

    /**
     * The slots the paths after a reading item stand in: the classes it
     * reads, or every class at once.
     *
     * @return list<int>
     */
    private function slotsOf(int $item, bool $any = true): array
    {
        $classes = [];
        for ($class = 0; $class < $this->automaton->classCount(); $class++) {
            if (ItemAutomaton::has($this->automaton->masks[$item], $class)) {
                $classes[] = $class;
            }
        }

        return $any && \count($classes) === $this->automaton->classCount() ? [self::SLOT_ANY] : $classes;
    }

    /**
     * What each stretch tries: whatever it reads, or on one input when the
     * classes each acyclic item reads are given.
     *
     * @param array<int, list<int>>|null $reading
     *
     * @throws ModelLimitException
     *
     * @return array{list<array{array<int, int>, array<int, array<int, array<int, int>>>}>, array<int, list<array{array<int, int>, array<int, array<int, array<int, int>>>}>>}
     */
    private function each(?array $reading): array
    {
        [, , $starts, $exits, $loops] = $this->stretches ?? [1, [], [], [], []];
        $fromStarts = [];
        foreach ($starts as $stretch) {
            $fromStarts[] = null === $reading ? $this->whatever($stretch, $loops) : $this->readOneInput($stretch, $loops, $reading);
        }

        $fromLoops = [];
        foreach ($exits as $loop => $stretches) {
            $fromLoops[$loop] = [];
            foreach ($stretches as $stretch) {
                $fromLoops[$loop][] = null === $reading ? $this->whatever($stretch, $loops) : $this->readOneInput($stretch, $loops, $reading);
            }
        }

        return [$fromStarts, $fromLoops];
    }

    /**
     * What a stretch tries whatever it reads, held to what one list tries
     * when every input is read one way at most (oneWay()).
     *
     * @param array{array<int, int>, array<int, array<int, array<int, int>>>} $count
     * @param array{int, array<int, int>}                                     $oneWay
     *
     * @return array{array<int, int>, array<int, array<int, array<int, int>>>}
     */
    private static function within(array $count, array $oneWay): array
    {
        [$paths, $arrivals] = $count;
        foreach ($paths as $slot => $ways) {
            $paths[$slot] = min($ways, $oneWay[0]);
        }

        foreach ($arrivals as $key => $groups) {
            foreach ($groups as $group => $slots) {
                foreach ($slots as $slot => $ways) {
                    $arrivals[$key][$group][$slot] = min($ways, $oneWay[1][$key] ?? $ways);
                }
            }
        }

        return [$paths, $arrivals];
    }

    /**
     * The most paths through each sub-search's mark at once, the most paths
     * the attempt tries at once, and the most ways it enters each loop at
     * once: what the stretches try in one slot, each times the ways the
     * attempt enters at once the loop it leaves, the routes round that loop
     * counted once, add up. Each start is an attempt of its own.
     *
     * @param array<int, int>                                                                   $rounds the most routes round each loop
     * @param list<array{array<int, int>, array<int, array<int, array<int, int>>>}>             $starts what each stretch from an attempt start tries: the paths at once by slot, and the routes to each item of a loop and through each mark (marks()), by the time they stand at, then by slot
     * @param array<int, list<array{array<int, int>, array<int, array<int, array<int, int>>>}>> $exits  what each stretch out of an item of a loop tries, by loop, in the order an attempt meets them
     *
     * @return array{array<int, int>, int, array<int, int>}
     */
    private function multiply(int $before, array $rounds, array $starts, array $exits): array
    {
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        $marks = [];
        $most = 0;
        $into = [];
        foreach ($starts as $start) {
            $paths = [];
            $arrivals = [];
            self::add($paths, $arrivals, $start, $before, 'start');
            foreach ($exits as $loop => $stretches) {
                $in = $this->entering($arrivals, $loop);
                if (0 === $in) {
                    continue;
                }

                // The items of a loop left at one time read one character:
                // their stretches stand at once, one group.
                $into[$loop] = max($into[$loop] ?? 0, $in);
                $out = min($limit, $in * ($rounds[$loop] ?? 1));
                foreach ($stretches as $stretch) {
                    self::add($paths, $arrivals, $stretch, $out, 'loop'.$loop);
                }
            }

            $most = max($most, self::most($paths));
            foreach ($arrivals as $key => $groups) {
                if ($key < 0) {
                    $passes = [];
                    foreach ($groups as $slots) {
                        foreach ($slots as $slot => $ways) {
                            $passes[$slot] = min($limit, ($passes[$slot] ?? 0) + $ways);
                        }
                    }

                    $marks[-1 - $key] = max($marks[-1 - $key] ?? 0, self::most($passes));
                }
            }
        }

        return [$marks, min($limit, max([$most, ...array_values($marks)])), $into];
    }

    /**
     * Adds what a stretch tries, times the ways it is reached, to what the
     * attempt tries in each slot, its routes to the items of loops and
     * through marks kept by the time they stand at, apart for each source.
     *
     * @param array<int, int>                                                 $paths
     * @param array<int, array<string, array<int, int>>>                      $arrivals
     * @param array{array<int, int>, array<int, array<int, array<int, int>>>} $count
     *
     * @param-out array<int, int> $paths
     * @param-out array<int, array<string, array<int, int>>> $arrivals
     */
    private static function add(array &$paths, array &$arrivals, array $count, int $ways, string $source): void
    {
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        foreach ($count[0] as $slot => $tried) {
            $paths[$slot] = min($limit, ($paths[$slot] ?? 0) + $ways * $tried);
        }

        foreach ($count[1] as $key => $groups) {
            foreach ($groups as $group => $slots) {
                foreach ($slots as $slot => $reached) {
                    $arrivals[$key][$source.':'.$group][$slot] = min($limit, ($arrivals[$key][$source.':'.$group][$slot] ?? 0) + $ways * $reached);
                }
            }
        }
    }

    /**
     * The most ways the attempt enters a loop at once. Of the routes that
     * reach its items at one time, those whose item reads the next
     * character go on: the most over the classes they read, in the slot of
     * the character read last. Routes that reach it at different times go
     * on in it together only where it reads what brought the later ones: the
     * oldest stands in any slot, every later one in a slot the loop reads.
     *
     * @param array<int, array<string, array<int, int>>> $arrivals by item, by the time they stand at, by slot
     */
    private function entering(array $arrivals, int $loop): int
    {
        $byTime = [];
        $reads = [];
        foreach ($this->fullComponents[$loop] ?? [] as $item) {
            $this->entries[$item] ??= $this->slotsOf($item, false);
            $reads += array_fill_keys($this->entries[$item], true);
            foreach ($arrivals[$item] ?? [] as $time => $slots) {
                foreach ($slots as $slot => $ways) {
                    foreach ($this->entries[$item] as $class) {
                        $byTime[$time][$slot][$class] = ($byTime[$time][$slot][$class] ?? 0) + $ways;
                    }
                }
            }
        }

        // At one time, the routes stand in one slot: the most over its slots.
        // Every time counts the most in a slot the loop reads, and the oldest
        // the most in any.
        $together = 0;
        $oldest = 0;
        foreach ($byTime as $bySlot) {
            $any = isset($bySlot[self::SLOT_ANY]) ? max($bySlot[self::SLOT_ANY]) : 0;
            $readable = $any;
            $all = max($any, isset($bySlot[self::SLOT_START]) ? max($bySlot[self::SLOT_START]) : 0);
            foreach ($bySlot as $slot => $classes) {
                if ($slot < 0) {
                    continue;
                }

                $all = max($all, max($classes) + $any);
                if (isset($reads[$slot])) {
                    $readable = max($readable, max($classes) + $any);
                }
            }

            $together += $readable;
            $oldest = max($oldest, $all - $readable);
        }

        return min(self::MAX_ACYCLIC_AMBIGUITY + 1, $together + $oldest);
    }

    /**
     * The most in one slot: a class's own, and what stands in every class.
     *
     * @param array<int, int> $slots
     */
    private static function most(array $slots): int
    {
        $any = $slots[self::SLOT_ANY] ?? 0;
        $most = $slots[self::SLOT_START] ?? 0;
        foreach ($slots as $slot => $count) {
            if ($slot >= 0) {
                $most = max($most, $count + $any);
            }
        }

        return max($most, $any);
    }

    /**
     * Under each acyclic reading item, whatever they read and those that
     * die included, a loop or the end ending one: the paths, the nodes
     * every step of them stands at, and how many reach each item of a loop
     * and go through each mark; capped just past the margin. The paths a
     * stretch tries at once on one input are as many as its paths at most,
     * two items tried together leading to paths apart; at every step at
     * once, as many as its nodes.
     *
     * @param array<int, int> $loops the items of every loop, by their component
     *
     * @return array{array<int, int>, array<int, int>, array<int, array<int, int>>}
     */
    private function paths(array $loops): array
    {
        $automaton = $this->automaton;
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        $tree = [];
        $nodes = [];
        $reach = [];
        // The components from the last back, a component after the ones it reaches.
        $components = $this->fullComponents ?? [];
        for ($index = \count($components) - 1; $index >= 0; $index--) {
            $item = $components[$index][0];
            if (isset($loops[$item])) {
                continue;
            }

            $leaves = $automaton->dead[$item] ?? 0;
            $under = $leaves;
            $into = self::marks($automaton->markRoutes[$item] ?? []);
            foreach ($this->routesOf($item) as $successor => $routes) {
                $this->budget->step();
                if (isset($tree[$successor])) {
                    $leaves = min($limit, $leaves + $routes * $tree[$successor]);
                    $under = min($limit, $under + $routes * (1 + $nodes[$successor]));
                    foreach ($reach[$successor] as $key => $ways) {
                        $into[$key] = min($limit, ($into[$key] ?? 0) + $routes * $ways);
                    }

                    continue;
                }

                $leaves = min($limit, $leaves + $routes);
                $under = min($limit, $under + $routes);
                if (isset($loops[$successor])) {
                    $into[$successor] = min($limit, ($into[$successor] ?? 0) + $routes);
                }
            }

            $tree[$item] = max(1, $leaves);
            $nodes[$item] = $under;
            $reach[$item] = $into;
        }

        return [$tree, $nodes, $reach];
    }

    /**
     * The routes through each mark, by the key that keeps them apart from
     * the items: -1 for the first.
     *
     * @param array<int, int> $routes
     *
     * @return array<int, int>
     */
    private static function marks(array $routes): array
    {
        $keyed = [];
        foreach ($routes as $mark => $count) {
            $keyed[-1 - $mark] = min(self::MAX_ACYCLIC_AMBIGUITY + 1, $count);
        }

        return $keyed;
    }

    /**
     * What a stretch tries, whatever it reads: its first paths, in the slots
     * they stand in, and every path after them, in every class, its nodes
     * when it starts again at each step; and every route to each item of a
     * loop and through each mark, at any step.
     *
     * @param array{array<int, int>, int, array<int, int>, list<int>, array<int, true>|null} $stretch the routes to its first items, those that die, those through each mark, the slots they stand in, and when it starts again at each step, out of a loop, the classes that loop reads
     * @param array<int, int>                                                                $loops
     *
     * @return array{array<int, int>, array<int, array<int, array<int, int>>>}
     */
    private function whatever(array $stretch, array $loops): array
    {
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        [$tree, $nodes, $reach] = $this->whateverPaths;
        [$routes, $first, $passes, $slots, $loopReads] = $stretch;
        $again = null !== $loopReads;
        $first = min($limit, $first);
        $later = 0;
        $firstArrivals = self::marks($passes);
        $laterArrivals = [];
        foreach ($routes as $item => $count) {
            $first = min($limit, $first + $count);
            if (isset($loops[$item])) {
                $firstArrivals[$item] = min($limit, ($firstArrivals[$item] ?? 0) + $count);

                continue;
            }

            if (!isset($tree[$item])) {
                continue;
            }

            $later = min($limit, $later + $count * ($again ? $nodes[$item] : $tree[$item]));
            foreach ($reach[$item] as $key => $ways) {
                $laterArrivals[$key] = min($limit, ($laterArrivals[$key] ?? 0) + $count * $ways);
            }
        }

        // Out of a loop, every step stands at one time; from an attempt start,
        // the steps after the first at any time.
        $paths = [];
        $arrivals = [];
        foreach ($slots as $slot) {
            $paths[$slot] = $first;
            foreach ($firstArrivals as $key => $ways) {
                $arrivals[$key][0][$slot] = $ways;
            }
        }

        if (0 !== $later) {
            $paths[self::SLOT_ANY] = min($limit, ($paths[self::SLOT_ANY] ?? 0) + $later);
        }

        foreach ($laterArrivals as $key => $ways) {
            $arrivals[$key][$again ? 0 : 1][self::SLOT_ANY] = min($limit, ($arrivals[$key][$again ? 0 : 1][self::SLOT_ANY] ?? 0) + $ways);
        }

        return [$paths, $arrivals];
    }

    /**
     * When no list holds an item reached by two routes, nor two items, loops
     * included, that read one class, every input is read one way at most: at
     * once, one list is tried, and reaches an item of a loop or goes through
     * a mark the ways that list does. The most paths a list tries and the
     * most routes one has to each item of a loop or through each mark; null
     * when some list reads an input two ways.
     *
     * @param list<array{array<int, int>, int, array<int, int>, list<int>, array<int, true>|null}> $lists every list of routes, with its routes that die and those through each mark
     * @param array<int, int>                                                                      $loops
     *
     * @return array{int, array<int, int>}|null
     */
    private function oneWay(array $lists, array $loops): ?array
    {
        $automaton = $this->automaton;
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        $most = 0;
        $into = [];
        foreach ($lists as [$routes, $paths, $passes]) {
            $union = null;
            $at = self::marks($passes);
            foreach ($routes as $item => $count) {
                $paths = min($limit, $paths + $count);
                if (isset($loops[$item])) {
                    $at[$item] = min($limit, ($at[$item] ?? 0) + $count);
                }

                if ($automaton->isFinal($item)) {
                    continue;
                }

                $mask = $automaton->masks[$item];
                if ($count > 1 || (null !== $union && !ItemAutomaton::isEmpty($union & $mask))) {
                    return null;
                }

                $union = null === $union ? $mask : $union | $mask;
            }

            $most = max($most, $paths);
            foreach ($at as $key => $ways) {
                $into[$key] = max($into[$key] ?? 0, $ways);
            }
        }

        return [$most, $into];
    }

    /**
     * What a stretch tries on one input: the routes alive, read class by
     * class, those that went into a loop or to the end too, which are not
     * read further, and those that died, step by step, but a success the
     * engine stops at. At once, in the slot
     * of the class read last: the most paths at one step, or every step
     * together when the stretch starts again at each; and every route to
     * each item of a loop and through each mark, at any step.
     *
     * @param array{array<int, int>, int, array<int, int>, list<int>, array<int, true>|null} $stretch the routes to its first items, those that die, those through each mark, the slots they stand in, and when it starts again at each step, out of a loop, the classes that loop reads
     * @param array<int, int>                                                                $loops
     * @param array<int, list<int>>                                                          $reading the classes each acyclic item reads
     *
     * @throws ModelLimitException
     *
     * @return array{array<int, int>, array<int, array<int, array<int, int>>>}
     */
    private function readOneInput(array $stretch, array $loops, array $reading): array
    {
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        [$routes, $died, $passes, $slots, $loopReads] = $stretch;
        // Each entry: a vector, the routes that died and those through each
        // mark on the way to it, the slots it stands in (by their number in
        // $lists), whether its next vectors are still to read, its step, how
        // many characters from the first the loop it leaves reads too, and
        // the vector's number (-1 for the first one).
        $lists = [$slots];
        $queue = [[array_map(static fn (int $count): int => min($limit, $count), $routes), min($limit, $died), self::marks($passes), 0, true, 0, 0, -1]];
        $seen = [];
        // The most paths tried at each step and the most routes to each item
        // of a loop and through each mark, by the list of slots they stand
        // in, spread over those slots once every entry is read.
        $tries = [];
        $arrivals = [];
        // The vectors met, by number: what each tries and reaches, and what
        // reading it gives. A vector met again at another step reads the
        // same, and takes the same steps from the budget.
        $numbers = [];
        $tallies = [];
        $expansions = [];
        for ($head = 0; isset($queue[$head]); $head++) {
            [$current, $died, $at, $list, $unread, $step, $alongside, $number] = $queue[$head];
            unset($queue[$head]);
            [$alive, $loopItems] = $tallies[$number] ??= $this->tally($current, $loops);
            $tried = $died + $alive;
            $tries[$step][$alongside][$list] = max($tries[$step][$alongside][$list] ?? 0, min($limit, $tried));
            foreach ($at + $loopItems as $key => $ways) {
                $arrivals[$step][$alongside][$key][$list] = max($arrivals[$step][$alongside][$key][$list] ?? 0, min($limit, $ways));
            }

            if (!$unread || $tried > self::MAX_ACYCLIC_AMBIGUITY) {
                continue;
            }

            if (isset($expansions[$number])) {
                [$charges, $along, $apart] = $expansions[$number];
                foreach ($charges as $charge) {
                    $this->budget->step($charge);
                }
            } else {
                [$charges, $along, $apart] = $this->expand($current, $reading, $loopReads, $numbers, $lists);
                $expansions[$number] = [$charges, $along, $apart];
            }

            // A vector met again at one step stands in one more slot: its
            // next ones are read once.
            foreach ($alongside === $step ? $along : $apart as [$classes, $next, $dies, $through, $nextNumber, $readAlong]) {
                $further = $readAlong ? $alongside + 1 : $alongside;
                $key = ($step + 1).'|'.$further.'|'.$nextNumber;
                $queue[] = [$next, $dies, $through, $classes, !isset($seen[$key]), $step + 1, $further, $nextNumber];
                $seen[$key] = true;
            }
        }

        $steps = [];
        foreach ($tries as $step => $byAlongside) {
            foreach ($byAlongside as $alongside => $byList) {
                foreach ($byList as $list => $tried) {
                    foreach ($lists[$list] as $slot) {
                        $steps[$step][$alongside][$slot] = max($steps[$step][$alongside][$slot] ?? 0, $tried);
                    }
                }
            }
        }

        $reached = [];
        foreach ($arrivals as $step => $byAlongside) {
            foreach ($byAlongside as $alongside => $byKey) {
                foreach ($byKey as $key => $byList) {
                    foreach ($byList as $list => $ways) {
                        foreach ($lists[$list] as $slot) {
                            $reached[$step][$alongside][$key][$slot] = max($reached[$step][$alongside][$key][$slot] ?? 0, $ways);
                        }
                    }
                }
            }
        }

        if (null === $loopReads) {
            // From an attempt start, each step is a time of its own.
            $paths = [];
            $arrivals = [];
            foreach ($steps as $step => $byAlongside) {
                foreach ($byAlongside as $alongside => $bySlot) {
                    foreach ($bySlot as $slot => $tried) {
                        $paths[$slot] = max($paths[$slot] ?? 0, $tried);
                    }

                    foreach ($reached[$step][$alongside] ?? [] as $key => $slotWays) {
                        foreach ($slotWays as $slot => $ways) {
                            $arrivals[$key][$step][$slot] = max($arrivals[$key][$step][$slot] ?? 0, $ways);
                        }
                    }
                }
            }

            return [$paths, $arrivals];
        }

        return [self::atOnceOut($steps), self::atOnceOutReached($reached)];
    }

    /**
     * What reading a vector gives: the steps it takes from the budget, one
     * for each item that reads, then the vectors after it, with the classes
     * read into each, in the order an attempt meets them; once with the
     * classes the loop left reads apart from the others, for a step whose
     * every character that loop read too, and once with them together. The
     * classes the same items read share one next vector, numbered by what it
     * holds in $numbers; each list of classes is numbered in $lists.
     *
     * @param array<int, int>       $vector
     * @param array<int, list<int>> $reading
     * @param array<int, true>|null $loopReads
     * @param array<string, int>    $numbers
     * @param list<list<int>>       $lists
     *
     * @param-out array<string, int> $numbers
     * @param-out list<list<int>> $lists
     *
     * @throws ModelLimitException
     *
     * @return array{list<int>, list<array{int, array<int, int>, int, array<int, int>, int, bool}>, list<array{int, array<int, int>, int, array<int, int>, int, bool}>}
     */
    private function expand(array $vector, array $reading, ?array $loopReads, array &$numbers, array &$lists): array
    {
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        $charges = [];
        $readers = [];
        foreach (array_intersect_key($vector, $reading) as $item => $_) {
            $charges[] = 1 + \count($reading[$item]);
            $this->budget->step(1 + \count($reading[$item]));
            foreach ($reading[$item] as $class) {
                $readers[$class][] = $item;
            }
        }

        $shared = [];
        $along = [];
        $apart = [];
        foreach ($readers as $class => $items) {
            $group = implode(',', $items);
            if (!isset($shared[$group])) {
                $next = [];
                $dies = 0;
                $through = [];
                foreach ($items as $item) {
                    $count = $vector[$item];
                    foreach ($this->routesOf($item) as $successor => $ways) {
                        $next[$successor] = min($limit, ($next[$successor] ?? 0) + $count * $ways);
                    }

                    $dies = min($limit, $dies + $count * ($this->automaton->dead[$item] ?? 0));
                    foreach ($this->marksOf($item) as $key => $ways) {
                        $through[$key] = min($limit, ($through[$key] ?? 0) + $count * $ways);
                    }
                }

                ksort($next);
                ksort($through);
                $held = implode(',', array_keys($next)).'|'.implode(',', $next).'|'.$dies.'|'.implode(',', array_keys($through)).'|'.implode(',', $through);
                $shared[$group] = [$next, $dies, $through, $numbers[$held] ??= \count($numbers)];
            }

            [$next, $dies, $through, $number] = $shared[$group];
            $readAlong = isset($loopReads[$class]);
            $key = $number.'|'.($readAlong ? 1 : 0);
            $along[$key] ??= [[], $next, $dies, $through, $number, $readAlong];
            $along[$key][0][] = $class;
            $apart[$number] ??= [[], $next, $dies, $through, $number, false];
            $apart[$number][0][] = $class;
        }

        return [$charges, self::numbered($along, $lists), self::numbered($apart, $lists)];
    }

    /**
     * The next vectors with their list of classes numbered in $lists.
     *
     * @param array<array{list<int>, array<int, int>, int, array<int, int>, int, bool}> $groups
     * @param list<list<int>>                                                           $lists
     *
     * @param-out list<list<int>> $lists
     *
     * @return list<array{int, array<int, int>, int, array<int, int>, int, bool}>
     */
    private static function numbered(array $groups, array &$lists): array
    {
        $numbered = [];
        foreach ($groups as [$classes, $next, $dies, $through, $number, $readAlong]) {
            $numbered[] = [\count($lists), $next, $dies, $through, $number, $readAlong];
            $lists[] = $classes;
        }

        return $numbered;
    }

    /**
     * What a vector tries at once: its paths, but those that reached a
     * success the engine stops at, and its routes to each item of a loop.
     *
     * @param array<int, int> $vector
     * @param array<int, int> $loops
     *
     * @return array{int, array<int, int>}
     */
    private function tally(array $vector, array $loops): array
    {
        $alive = 0;
        $loopItems = [];
        foreach ($vector as $item => $count) {
            if (isset($loops[$item])) {
                $loopItems[$item] = $count;
            }

            // A success ends the attempt: one at most is ever reached.
            if (!$this->succeeds($item)) {
                $alive += $count;
            }
        }

        return [$alive, $loopItems];
    }

    /**
     * What the stretch out of a loop tries at one time, the loop left at
     * several: the paths at the shallowest step, with those at each deeper
     * step whose first characters, as many as the steps between, the loop
     * reads too, for it went on reading them to be left again.
     *
     * @param array<int, array<int, array<int, int>>> $steps the most paths by step, by the characters from the first the loop reads too, by slot
     *
     * @return array<int, int>
     */
    private static function atOnceOut(array $steps): array
    {
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        // At each step, by slot, the most paths whose first characters, as
        // many at least as the index, the loop reads too.
        $alongside = [];
        foreach ($steps as $step => $byAlongside) {
            $most = [];
            $deepest = 0;
            foreach ($byAlongside as $read => $_) {
                $deepest = max($deepest, $read);
            }

            for ($read = $deepest; $read >= 0; $read--) {
                foreach ($byAlongside[$read] ?? [] as $slot => $tried) {
                    $most[$slot] = max($most[$slot] ?? 0, $tried);
                }

                $alongside[$step][$read] = $most;
            }
        }

        $paths = [];
        foreach ($alongside as $shallowest => $atShallowest) {
            $together = $atShallowest[0] ?? [];
            foreach ($alongside as $step => $deeper) {
                if ($step <= $shallowest) {
                    continue;
                }

                foreach ($deeper[$step - $shallowest] ?? [] as $slot => $tried) {
                    $together[$slot] = min($limit, ($together[$slot] ?? 0) + $tried);
                }
            }

            foreach ($together as $slot => $tried) {
                $paths[$slot] = max($paths[$slot] ?? 0, $tried);
            }
        }

        return $paths;
    }

    /**
     * The routes out of a loop to each item of a loop and through each mark
     * at one time, the loop left at several (atOnceOut()): one time.
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $reached by step, by the characters from the first the loop reads too, by key, by slot
     *
     * @return array<int, array<int, array<int, int>>>
     */
    private static function atOnceOutReached(array $reached): array
    {
        $byKey = [];
        foreach ($reached as $step => $byAlongside) {
            foreach ($byAlongside as $alongside => $keys) {
                foreach ($keys as $key => $bySlot) {
                    $byKey[$key][$step][$alongside] = $bySlot;
                }
            }
        }

        $arrivals = [];
        foreach ($byKey as $key => $steps) {
            $arrivals[$key][0] = self::atOnceOut($steps);
        }

        return $arrivals;
    }

    /**
     * Whether reaching the item is a success the engine stops at: the end
     * of the pattern whatever comes next, past no undecided check, where the
     * model reads no success the engine may refuse.
     */
    private function succeeds(int $item): bool
    {
        return $this->successes[$item] ??= !$this->widens && $this->automaton->isUnconditional($item) && !$this->automaton->holdsUndecided([$item => true]);
    }

    /**
     * Whether every run the engine tries and abandons inside the loop of the
     * item is short: whatever character the loop goes on with, a success is
     * sure a few characters later. "^(?:a|a)+." is such a loop: once it can
     * read an "a", the dot can too.
     *
     * The engine never backtracks out of a node that holds a success: it
     * returns from inside it. A run it abandons holds none, so its paths
     * stand, after each character, at a set of items some continuation
     * rejects (rejectingSuffix()). Those sets are read from what follows
     * each item of the loop, on every class, as long as one of their items
     * is in the loop: when no cycle of them exists, an abandoned run reads a
     * bounded number of characters inside the loop, and the paths through
     * the loop's items over that many characters bound the ways it reads
     * them, each way the attempt has into one of them read again with each.
     * Every path counts: k groups "(?:|)" before a character give 2^k ways
     * to it, and a route that dies at a check is walked as one that goes on.
     * Held to the margin of the paths an attempt tries at once, they cost a
     * bounded number of steps per character, however many ways the loop has
     * around it. A run abandoned past the loop is read by the links out of it.
     *
     * The model must count no success the engine may refuse: a search with
     * a lookaround or inside one, an atomic body kept with every way through
     * it (one that reads nothing too, "(?>\K|)"), a bounded repeat read as
     * unbounded, a "$" read with the final newline, or a "^" under m, which
     * the model takes to hold after a newline that ends the subject, is not
     * read.
     *
     * @throws ModelLimitException
     */
    private function abandonsShortRuns(int $item): bool
    {
        $pnfa = $this->automaton->pnfa;
        if ($this->widens || null !== $pnfa->parent || [] !== $pnfa->marks || $pnfa->readsFinalNewline || $pnfa->hasLineStart) {
            return false;
        }

        $members = [$item];
        foreach ($this->fullComponents ?? [] as $component) {
            if (\in_array($item, $component, true)) {
                $members = $component;

                break;
            }
        }

        $first = min([$item, ...$members]);
        if (!isset($this->boundedRuns[$first])) {
            $this->boundedRuns[$first] = $this->runsShortInside($members);
            if ($this->boundedRuns[$first]) {
                $this->shortRuns += array_fill_keys($members, true);
            }
        }

        return $this->boundedRuns[$first];
    }

    /**
     * Whether every run abandoned after an item of the strongly connected
     * component reads it on a bounded stretch, in no more ways than an
     * acyclic stretch may, each way the attempt enters it at once
     * (checkWays()) read again with each.
     *
     * @param list<int> $members
     *
     * @throws ModelLimitException
     */
    private function runsShortInside(array $members): bool
    {
        if (!$this->counted) {
            $this->checkWays();
        }

        // Counted as one list at once, the search reads every input one way:
        // no loop of it is read in two.
        if (null === $this->stretches || null === $this->counts) {
            return false;
        }

        [$before, $rounds, , , $loops] = $this->stretches;
        $loop = $loops[$members[0]] ?? -1;
        [, , $entering] = $this->multiply($before, $rounds, ...$this->counts);
        $into = $entering[$loop] ?? 1;
        $inside = array_fill_keys($members, true);
        $heights = [];
        $outs = [];
        foreach ($members as $member) {
            $after = array_fill_keys($this->automaton->successors[$member], true);
            ksort($after);
            $height = $this->runInside($after, $inside, $heights);
            if (null === $height || $into * $this->waysInside($member, $inside, $height, $loop, $outs) > self::MAX_ACYCLIC_AMBIGUITY) {
                return false;
            }
        }

        return true;
    }

    /**
     * The most characters a run some continuation rejects reads inside the
     * loop after the set, -1 when it is not such a run; null when a cycle of
     * such sets keeps it inside, or when more sets than the reading allows
     * would tell. Depth first, each set met kept with its answer, null while
     * it is on the way to the current one.
     *
     * @param array<int, true>        $items
     * @param array<int, true>        $inside  the items of the loop
     * @param array<string, int|null> $heights
     *
     * @throws ModelLimitException
     */
    private function runInside(array $items, array $inside, array &$heights): ?int
    {
        $key = self::key($items);
        if (\array_key_exists($key, $heights)) {
            return $heights[$key];
        }

        if (\count($heights) >= self::MAX_ABANDONED_RUN_SETS) {
            return null;
        }

        if ([] === array_intersect_key($items, $inside) || null === $this->rejectingSuffix($items)) {
            return $heights[$key] = -1;
        }

        $heights[$key] = null;
        $height = 0;
        for ($class = 0; $class < $this->automaton->classCount(); $class++) {
            $next = $this->automaton->stepSet($items, $class);
            $below = null === $next ? -1 : $this->runInside($next, $inside, $heights);
            if (null === $below) {
                return null;
            }

            $height = max($height, $below + 1);
        }

        return $heights[$key] = $height;
    }

    /**
     * The paths a run abandoned after the item tries, at most: through the
     * items of the loop over that many characters, and out of the loop after
     * the item and each of them that reads, every path on after one way out
     * (outOf()). The items the run stands at last read nothing more: a
     * character they read would keep it inside.
     *
     * @param array<int, true> $inside the items of the loop
     * @param array<int, int>  $outs   the paths after one way out of each item, once counted
     *
     * @param-out array<int, int> $outs
     *
     * @throws ModelLimitException
     */
    private function waysInside(int $item, array $inside, int $characters, int $loop, array &$outs): int
    {
        $limit = self::MAX_ACYCLIC_AMBIGUITY + 1;
        $current = [$item => 1];
        $ways = 0;
        for ($read = 0; $read <= $characters; $read++) {
            $next = [];
            foreach ($current as $from => $count) {
                $outs[$from] ??= $this->outOf($from, $loop);
                $ways = min($limit, $ways + $count * $outs[$from]);
                foreach ($this->routesOf($from) as $successor => $routes) {
                    if (isset($inside[$successor])) {
                        $next[$successor] = min($limit, ($next[$successor] ?? 0) + $count * $routes);
                    }
                }
            }

            $current = $next;
            $ways = min($limit, $ways + array_sum($current));
        }

        return $ways;
    }

    /**
     * The most paths tried at once after one way out of an item of a loop:
     * the stretch out of it, as checkWays() counts it, the routes that die
     * after the item included, and the stretches out of the loops after it,
     * one way reaching it; past the margin, counted sharper.
     *
     * @throws ModelLimitException
     */
    private function outOf(int $item, int $loop): int
    {
        [, $rounds, , , $loops] = $this->stretches ?? [1, [], [], [], []];
        // The stretches out of a loop stand in the order of its items.
        $index = 0;
        foreach ($loops as $member => $component) {
            if ($member === $item) {
                break;
            }

            if ($component === $loop) {
                $index++;
            }
        }

        do {
            $exits = $this->counts[1] ?? [];
            [, $most] = $this->multiply(1, $rounds, [$exits[$loop][$index] ?? [[], []]], $exits);
        } while ($most > self::MAX_ACYCLIC_AMBIGUITY && $this->sharpen());

        return $most;
    }

    /**
     * The items of every strongly connected component that goes round, by
     * that component.
     *
     * @return array<int, int>
     */
    private function loopItems(): array
    {
        $loops = [];
        foreach ($this->fullComponents ?? [] as $index => $members) {
            if (\count($members) > 1 || \in_array($members[0], $this->automaton->successors[$members[0]], true)) {
                $loops += array_fill_keys($members, $index);
            }
        }

        return $loops;
    }

    /**
     * The epsilon paths to each item of a list, a list holding an item twice
     * at most: those reaching one more often, counted apart.
     *
     * @param list<int>       $list
     * @param array<int, int> $folded
     *
     * @return array<int, int>
     */
    private static function routes(array $list, array $folded): array
    {
        $routes = array_count_values($list);

        return [] === $folded ? $routes : array_replace($routes, $folded);
    }

    /**
     * The epsilon paths from a reading item to each item after it.
     *
     * @return array<int, int>
     */
    private function routesOf(int $item): array
    {
        return $this->routesFrom[$item] ??= self::routes($this->automaton->successors[$item], $this->automaton->routes[$item] ?? []);
    }

    /**
     * The routes from a reading item through each mark, keyed apart from
     * the items (marks()).
     *
     * @return array<int, int>
     */
    private function marksOf(int $item): array
    {
        return $this->marksFrom[$item] ??= self::marks($this->automaton->markRoutes[$item] ?? []);
    }

    /**
     * The lookaround read in place a cyclic component lies in, null for the
     * search itself: a component never leaves one, whose body ends nowhere.
     */
    private function lookaroundOf(int $component): ?int
    {
        return $this->automaton->pnfa->insideLookaround[$this->automaton->states[$this->cyclic[$component][0]]] ?? null;
    }

    /**
     * Whether a cyclic component goes round a loop of the pattern, not only
     * one the model made of a bounded repeat.
     */
    private function loopsOwn(int $component): bool
    {
        foreach ($this->cyclic[$component] as $item) {
            foreach ($this->automaton->successors[$item] as $index => $successor) {
                if (($this->component[$successor] ?? null) === $component && 0 !== ($this->automaton->edges[$item][$index] & ItemAutomaton::EDGE_LOOP)) {
                    return true;
                }
            }
        }

        return false;
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
     * @throws ModelLimitException
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
                    throw ModelLimitException::outOfModel(\sprintf('An acyclic stretch read in more than %d ways', self::MAX_ACYCLIC_AMBIGUITY));
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
     * @param bool    $abstracted whether the pump rests only on loops the model
     *                            made of bounded repeats, through exact sets
     *
     * @return Verdict
     */
    private function verdict(RedosComplexity $complexity, ?int $degree, array $witness, bool $crosses, bool $abstracted = false): array
    {
        return ['complexity' => $complexity, 'degree' => $degree, ...$witness, 'approximated' => $crosses, 'abstracted' => $abstracted, 'unwitnessed' => null];
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
            'abstracted' => false,
            'unwitnessed' => $this->automaton->offsetOf($state),
        ];
    }

    /**
     * Whether no witness can exist for an ambiguity of the item: after it
     * reads, every continuation is accepted, on paths the model reads as the
     * engine does, or every run abandoned inside its loop is bounded
     * (abandonsShortRuns()). A bounded repeat read as unbounded, or an atomic
     * body kept with every way through it, widens what succeeds:
     * "[^x]{0,20}(?:x|\z)" read as "[^x]*(?:x|\z)" accepts whatever follows,
     * where the engine fails once twenty characters are not enough.
     */
    private function disproved(int $state): bool
    {
        if (isset($this->shortRuns[$state])) {
            return true;
        }

        $after = $this->automaton->exactSuccessors($state);
        ksort($after);

        return $this->widens ? $this->acceptsEverythingExactly($after) : null === $this->rejectingSuffix($after);
    }

    /**
     * Whether every continuation of the set is accepted on paths the model
     * reads as the engine does (ItemAutomaton::stepExactly()), the end of the
     * subject included.
     *
     * @param array<int, true> $items
     *
     * @throws ModelLimitException
     */
    private function acceptsEverythingExactly(array $items): bool
    {
        $automaton = $this->automaton;
        $origin = self::key($items);
        $queue = [$items];
        $seen = [$origin => true];
        for ($head = 0; $head < \count($queue); $head++) {
            $this->budget->step();
            $current = $queue[$head];
            if (!$automaton->acceptsAtEnd($current)) {
                return false;
            }

            for ($class = 0; $class < $automaton->classCount(); $class++) {
                $next = $automaton->stepExactly($current, $class);
                if (null === $next || isset($seen[$key = self::key($next)])) {
                    continue;
                }

                $seen[$key] = true;
                $queue[] = $next;
            }
        }

        return true;
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

        foreach (StronglyConnectedComponents::of($edges, $this->budget)->components as $component) {
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
        $bestDegree = 0;
        $unwitnessed = null;
        foreach ($links as $targets) {
            foreach ($targets as $to => [$state, $labels, $crosses, $real, $lookaround, $end]) {
                $degree = 2 + $this->longestChain($to, $links, $longest);
                $inexact = $crosses || !$real;
                // An exact pump wins a tie with an approximated one.
                if (null !== $best && ($degree < $bestDegree || ($degree === $bestDegree && ($inexact || !$best['approximated'])))) {
                    continue;
                }

                // The links of the chain may read different words: a pump
                // shows the degree of the chain its own word drives, and
                // the pumps are tried from the highest.
                $witness = null;
                $witnessed = 0;
                foreach ($lookaround ? [] : $this->pumpsByDegree($this->pumps($labels), $end, $degree) as $pumpDegree => $pumps) {
                    if (null !== $best && ($pumpDegree < $bestDegree || ($pumpDegree === $bestDegree && ($inexact || !$best['approximated'])))) {
                        break;
                    }

                    $witness = $this->witness($state, $pumps);
                    if (null !== $witness) {
                        $witnessed = $pumpDegree;

                        break;
                    }
                }

                if (null !== $witness) {
                    $best = $this->verdict(RedosComplexity::Polynomial, $witnessed, $witness, $inexact);
                    $bestDegree = $witnessed;
                } elseif ($this->abandonsShortRuns($end)) {
                    // The runs tried and abandoned in the second loop are
                    // bounded: the link costs nothing.
                    continue;
                }

                if ($witnessed < $degree && (null === $unwitnessed || $degree > $unwitnessed[0])) {
                    $unwitnessed = [$degree, $state];
                }
            }
        }

        if (null !== $unwitnessed && (null === $best || $unwitnessed[0] > $bestDegree)) {
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
     * The pumps of a link by the degree the chain on each one's word
     * reaches, the highest first, none above the degree of the chain of
     * components: the link's two states, then each further state the word
     * leads to and loops on.
     *
     * @param list<list<int>> $pumps
     *
     * @return array<int, list<list<int>>>
     */
    private function pumpsByDegree(array $pumps, int $end, int $degree): array
    {
        $byDegree = [];
        foreach ($pumps as $pump) {
            $chains = [];
            $byDegree[min($degree, 2 + $this->chainOn($pump, $end, $chains))][] = $pump;
        }

        krsort($byDegree);

        return $byDegree;
    }

    /**
     * How many states a chain on the pump's word reaches after the state:
     * a state of another cyclic component the word, read once or more from
     * the state, leads to, which loops on the word and whose continuations
     * do not all succeed, then the states after it.
     *
     * @param list<int>       $pump   classes
     * @param array<int, int> $chains the chain from each state met, for the pump
     */
    private function chainOn(array $pump, int $state, array &$chains): int
    {
        if (isset($chains[$state])) {
            return $chains[$state];
        }

        $chains[$state] = 0;
        $component = $this->component[$state] ?? null;
        $reached = [];
        $current = [$state => true];
        // The states the word read again and again leads to, until they
        // repeat.
        $seen = [];
        while ([] !== $current && !isset($seen[$key = self::key($current)])) {
            $seen[$key] = true;
            $current = $this->read($current, $pump);
            $reached += $current;
        }

        $best = 0;
        foreach ($reached as $next => $_) {
            $nextComponent = $this->component[$next] ?? null;
            if (null === $nextComponent || $nextComponent === $component || $this->disproved($next) || !isset($this->read([$next => true], $pump)[$next])) {
                continue;
            }

            $best = max($best, 1 + $this->chainOn($pump, $next, $chains));
        }

        return $chains[$state] = $best;
    }

    /**
     * The reading items the word leads to from the items, on any path.
     *
     * @param array<int, true> $items
     * @param list<int>        $word  classes
     *
     * @return array<int, true>
     */
    private function read(array $items, array $word): array
    {
        $automaton = $this->automaton;
        foreach ($word as $class) {
            $next = [];
            foreach ($items as $item => $_) {
                $this->budget->step();
                if ($automaton->isFinal($item) || !ItemAutomaton::has($automaton->masks[$item], $class)) {
                    continue;
                }

                foreach ($automaton->successors[$item] as $successor) {
                    $next[$successor] = true;
                }
            }

            $items = $next;
        }

        ksort($items);

        return $items;
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
            foreach ($this->fullComponents ?? StronglyConnectedComponents::of($edges, $this->budget)->components as $index => $members) {
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

                foreach ($automaton->exactSuccessors($item) as $successor => $_) {
                    if ($automaton->isFinal($successor) && $this->acceptsAll($successor, $ahead)) {
                        $blocked[$item] = true;

                        break;
                    }
                }
            }

            $edges = $this->edges($blocked);
        }

        $components = StronglyConnectedComponents::of($edges, $this->budget)->components;
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

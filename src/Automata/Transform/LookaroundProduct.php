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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Automata\Builder\DfaBuilder;
use PHPRegex\Automata\Builder\NfaBuilder;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaFragment;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Support\WorkBudget;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Hir\LookKind;

/**
 * Turns an automaton holding lookaround marks into a plain one, as the
 * language a lookaround keeps is regular (Berglund, van der Merwe and van
 * Litsenborgh, "Regular Expressions with Lookahead", 2021).
 *
 * A lookahead is a promise about what follows: crossing its mark starts a
 * run of its body's automaton on the characters still to come. A positive
 * promise is kept once that run accepts and broken when it can no longer
 * accept; a negative one is broken once it accepts. A lookbehind asks about
 * what came before: the automaton of "anything, then its body" runs from the
 * start of the subject, and the mark reads where it stands. The product of
 * the pattern's automaton with the pending promises and those runs accepts
 * where the pattern accepts with no positive promise pending.
 *
 * @internal
 *
 * @phpstan-type PromisePair array{int, int}
 * @phpstan-type ProductState array{int, list<PromisePair>, list<PromisePair>, list<int>}
 */
final class LookaroundProduct
{
    private const DEAD = -1;

    /**
     * @var list<LookHir>
     */
    private array $looks = [];

    /**
     * @var list<Dfa>
     */
    private array $automata = [];

    /**
     * @var list<array<int, true>> per lookaround, the states its run can still accept from
     */
    private array $live = [];

    /**
     * @var array<int, int> mark state => lookaround index
     */
    private array $marks = [];

    /**
     * @var list<int> the lookbehind indexes, in the order their runs are kept
     */
    private array $behind = [];

    /**
     * @var array<int, int> lookbehind index => where its run is kept
     */
    private array $runOf = [];

    /**
     * @var array<int, array<int, array<int, int>>> lookaround index => state of its run => character read => state reached
     */
    private array $steps = [];

    public function __construct(
        private readonly string $pattern,
        private readonly bool $unicode,
        private readonly SolverOptions $options,
    ) {}

    /**
     * @param array<int, LookHir> $marks the mark state of each lookaround
     *
     * @throws ComplexityException
     */
    public function build(Nfa $nfa, array $marks): Nfa
    {
        // Repetition expands marks but keeps their HIR object. Share the
        // assertion index as well as its DFA, so promises and behind runs
        // are not duplicated either.
        $indexes = [];
        $nfaStates = 0;
        $dfaStates = 0;
        $bodySets = [];
        $budget = new WorkBudget($this->options->maxTransitionsProcessed, 'lookaround bodies', 0, 0, 0);

        foreach ($marks as $state => $look) {
            $key = $look->kind->name.':'.spl_object_id($look->body);
            if (isset($indexes[$key])) {
                $this->marks[$state] = $indexes[$key];

                continue;
            }

            // Check the remaining aggregate allowance before allocating a body.
            $budget->consume();
            if ($nfaStates >= $this->options->maxNfaStates) {
                throw new ComplexityException(\sprintf('Lookaround body NFA state limit exceeded (%d).', $this->options->maxNfaStates), $look->startPosition, $this->pattern, diagnostic: ['phase' => 'lookaround bodies', 'limit' => $this->options->maxNfaStates]);
            }
            if ($dfaStates >= $this->options->maxDfaStates) {
                throw new ComplexityException(\sprintf('Lookaround body DFA state limit exceeded (%d).', $this->options->maxDfaStates), $look->startPosition, $this->pattern, diagnostic: ['phase' => 'lookaround bodies', 'limit' => $this->options->maxDfaStates]);
            }
            $bodyOptions = new SolverOptions(
                matchMode: MatchMode::Full,
                maxNfaStates: $this->options->maxNfaStates - $nfaStates,
                maxDfaStates: $this->options->maxDfaStates - $dfaStates,
                maxTransitionsProcessed: $this->options->maxTransitionsProcessed,
            );
            $body = (new HirToNfaTransformer($this->pattern, $this->unicode))->lookaroundBody($look, $bodyOptions, $budget);
            $nfaStates += \count($body->states);
            foreach ($body->states as $bodyState) {
                foreach ($bodyState->transitions as $transition) {
                    $bodySets[] = $transition->charSet;
                }
            }
            $dfa = (new DfaBuilder())->determinize($body, $bodyOptions, $budget);
            $dfaStates += \count($dfa->states);
            // Charge stored transitions before retaining the DFA or building
            // its reverse graph. Count both representations when both exist.
            foreach ($dfa->states as $dfaState) {
                $budget->consume(1 + \count($dfaState->byteTransitions()) + $dfaState->rangeCount());
            }

            $index = \count($this->looks);
            $indexes[$key] = $index;
            $this->looks[] = $look;
            $this->marks[$state] = $index;
            $this->automata[] = $dfa;
            $this->live[] = self::liveStates($dfa);
            if (self::isBehind($look)) {
                $this->runOf[$index] = \count($this->behind);
                $this->behind[] = $index;
            }
        }

        // The product has a budget of its own, as the determinization that
        // follows it has: splitting the alphabet and every move are counted.
        $transitions = 0;
        foreach ($nfa->states as $counted) {
            $transitions += \count($counted->transitions);
        }
        $work = new WorkBudget($this->options->maxTransitionsProcessed, 'lookaround product', 0, $transitions, 0);
        [$classes, $reads] = $this->alphabet($nfa, $bodySets, $work);
        $builder = new NfaBuilder($this->options->maxNfaStates, $nfa->minCodePoint, $nfa->maxCodePoint);

        $trackers = array_map(fn (int $index): int => $this->automata[$index]->startState, $this->behind);
        $start = [$nfa->startState, [], [], $trackers];
        $ids = [];
        $accepting = [];
        $queue = [$start];
        $ids[self::key($start)] = $builder->createState();

        for ($head = 0; $head < \count($queue); $head++) {
            [$state, $kept, $avoided, $tracked] = $current = $queue[$head];
            $from = $ids[self::key($current)];
            $nfaState = $nfa->getState($state);

            if ($nfaState->isAccepting && [] === $kept) {
                $accepting[] = $from;
            }

            // One unit a move: a step that reads nothing, or a transition
            // read on one class of characters, whether it leads somewhere
            // or breaks a promise.
            $work->updateStats(\count($queue), $transitions, \count($classes));
            $moves = [];
            foreach ($nfaState->epsilonTransitions as $target) {
                $work->consume();
                $moves[] = [null, $this->cross($state, $target, $kept, $avoided, $tracked)];
            }

            foreach ($reads[$state] ?? [] as [$target, $held]) {
                foreach ($held as $class) {
                    $work->consume();
                    [$symbol, $set] = $classes[$class];
                    $moves[] = [$set, $this->read($target, $symbol, $kept, $avoided, $tracked)];
                }
            }

            foreach ($moves as [$set, $next]) {
                if (null === $next) {
                    continue;
                }

                $key = self::key($next);
                if (!isset($ids[$key])) {
                    $ids[$key] = $builder->createState();
                    $queue[] = $next;
                }

                null === $set ? $builder->addEpsilon($from, $ids[$key]) : $builder->addTransition($from, $set, $ids[$key]);
            }
        }

        return $builder->build(new NfaFragment($ids[self::key($start)], $accepting));
    }

    /**
     * Follows a move that reads nothing: across a mark, the lookaround makes
     * its promise or asks its question; null when it fails at once.
     *
     * @param list<PromisePair> $kept
     * @param list<PromisePair> $avoided
     * @param list<int>         $tracked
     *
     * @return ProductState|null
     */
    private function cross(int $state, int $target, array $kept, array $avoided, array $tracked): ?array
    {
        $index = $this->marks[$state] ?? null;
        if (null === $index) {
            return [$target, $kept, $avoided, $tracked];
        }

        $look = $this->looks[$index];
        $dfa = $this->automata[$index];
        if (self::isBehind($look)) {
            $run = $tracked[$this->runOf[$index]];
            $holds = self::DEAD !== $run && $dfa->getState($run)->isAccepting;

            return $holds === (LookKind::Behind === $look->kind) ? [$target, $kept, $avoided, $tracked] : null;
        }

        if (!$this->promise($index, $dfa->startState, $kept, $avoided)) {
            return null;
        }

        return [$target, self::settled($kept), self::settled($avoided), $tracked];
    }

    /**
     * Reads one character: every pending promise and every run moves on it.
     *
     * @param list<PromisePair> $kept
     * @param list<PromisePair> $avoided
     * @param list<int>         $tracked
     *
     * @return ProductState|null
     */
    private function read(int $target, int $symbol, array $kept, array $avoided, array $tracked): ?array
    {
        $tracked = array_map(fn (int $run, int $position): int => $this->step($this->behind[$position], $run, $symbol), $tracked, array_keys($tracked));

        $pending = [...$kept, ...$avoided];
        $kept = [];
        $avoided = [];
        foreach ($pending as [$index, $run]) {
            if (!$this->promise($index, $this->step($index, $run, $symbol), $kept, $avoided)) {
                return null;
            }
        }

        return [$target, self::settled($kept), self::settled($avoided), $tracked];
    }

    /**
     * Records where a promise's run stands, and tells whether the promise
     * still holds: a positive one is kept once its run accepts and broken
     * once it cannot; a negative one is broken once its run accepts and
     * forgotten once it cannot. A promise still pending is added to its
     * list as it comes: settled() puts the list in order.
     *
     * @param list<PromisePair> $kept
     * @param list<PromisePair> $avoided
     */
    private function promise(int $index, int $run, array &$kept, array &$avoided): bool
    {
        $accepts = self::DEAD !== $run && $this->automata[$index]->getState($run)->isAccepting;
        $alive = self::DEAD !== $run && isset($this->live[$index][$run]);
        $pending = $alive && !$accepts;

        if (LookKind::Ahead === $this->looks[$index]->kind) {
            if ($pending) {
                $kept[] = [$index, $run];
            }

            // A run that accepts can still accept: it is alive.
            return $alive;
        }

        if ($pending) {
            $avoided[] = [$index, $run];
        }

        return !$accepts;
    }

    /**
     * The pending promises as a set, each once and in order, so that two
     * ways to the same promises reach one state. It is done once a move,
     * after every promise has moved, not once a promise.
     *
     * @param list<PromisePair> $promises
     *
     * @return list<PromisePair>
     */
    private static function settled(array $promises): array
    {
        $promises = array_unique($promises, \SORT_REGULAR);
        sort($promises);

        return $promises;
    }

    /**
     * Where a run stands after a character. A run moves alike on every
     * state of the product that holds it, so each move is looked up once.
     */
    private function step(int $index, int $run, int $symbol): int
    {
        return $this->steps[$index][$run][$symbol] ??= self::DEAD === $run ? self::DEAD : ($this->automata[$index]->getState($run)->transitionFor($symbol) ?? self::DEAD);
    }

    /**
     * The classes of the alphabet: the characters that belong to the same
     * sets, of the automaton and of the lookarounds' bodies, move every
     * state and every run alike, so one of them is read for all. A Unicode
     * class cuts the alphabet into hundreds of stretches and two classes.
     *
     * The first list holds each class, in the order of its first character:
     * that character, and the class as a set. The second holds, for each
     * state of the automaton, the target of each transition and the classes
     * its set holds. One unit is counted for each stretch a set covers, and
     * one for each stretch kept.
     *
     * @param list<CharSet> $bodySets the sets the lookarounds' bodies read
     *
     * @throws ComplexityException
     *
     * @return array{list<array{int, CharSet}>, array<int, list<array{int, list<int>}>>}
     */
    private function alphabet(Nfa $nfa, array $bodySets, WorkBudget $work): array
    {
        // Each set has a number, shared by the sets of the same characters:
        // a literal builds one set for each time it is repeated.
        /** @var \SplObjectStorage<CharSet, int> $numbers */
        $numbers = new \SplObjectStorage();
        $sets = [];
        $numberOf = static function (CharSet $set) use ($numbers, &$sets): int {
            if (!isset($numbers[$set])) {
                $key = $set->key();
                $sets[$key] ??= [\count($sets), $set];
                $numbers[$set] = $sets[$key][0];
            }

            return $numbers[$set];
        };

        $transitions = [];
        foreach ($nfa->states as $id => $state) {
            foreach ($state->transitions as $transition) {
                $transitions[$id][] = [$transition->target, $numberOf($transition->charSet)];
            }
        }
        foreach ($bodySets as $set) {
            $numberOf($set);
        }

        $bounds = [];
        foreach ($sets as [, $set]) {
            foreach ($set->ranges as [$from, $to]) {
                $bounds[$from] = true;
                $bounds[$to + 1] = true;
            }
        }
        $bounds = array_keys($bounds);
        sort($bounds);
        $position = array_flip($bounds);

        // The sets each stretch belongs to, a stretch running from one
        // bound to the next.
        $holders = array_fill(0, \count($bounds), []);
        foreach ($sets as [$number, $set]) {
            foreach ($set->ranges as [$from, $to]) {
                for ($at = $position[$from]; $bounds[$at] <= $to; $at++) {
                    $work->consume();
                    $holders[$at][] = $number;
                }
            }
        }

        $firsts = [];
        $classOf = [];
        $ranges = [];
        $held = [];
        foreach ($holders as $at => $holding) {
            // No move reads a stretch no set holds: the last bound opens
            // none. A stretch a set holds lies in the alphabet, as the set.
            if ([] === $holding) {
                continue;
            }

            $work->consume();
            $signature = implode(',', $holding);
            if (!isset($classOf[$signature])) {
                $classOf[$signature] = \count($firsts);
                $firsts[] = $bounds[$at];
                foreach ($holding as $number) {
                    $held[$number][] = $classOf[$signature];
                }
            }
            $ranges[$classOf[$signature]][] = [$bounds[$at], $bounds[$at + 1] - 1];
        }

        $classes = array_map(static fn (int $first, array $stretches): array => [$first, CharSet::fromSorted($stretches)], $firsts, $ranges);

        $reads = [];
        foreach ($transitions as $id => $moves) {
            foreach ($moves as [$target, $number]) {
                // A set holds a stretch at least: none is empty.
                $reads[$id][] = [$target, $held[$number]];
            }
        }

        return [$classes, $reads];
    }

    /**
     * @return array<int, true>
     */
    private static function liveStates(Dfa $dfa): array
    {
        $predecessors = [];
        $live = [];
        $queue = [];
        foreach ($dfa->states as $id => $state) {
            foreach ($state->byteTransitions() as $target) {
                $predecessors[$target][$id] = true;
            }
            $ranges = $state->packedRanges();
            for ($at = 2, $count = \count($ranges); $at < $count; $at += 3) {
                $predecessors[$ranges[$at]][$id] = true;
            }

            if ($state->isAccepting) {
                $live[$id] = true;
                $queue[] = $id;
            }
        }

        while ([] !== $queue) {
            $target = array_pop($queue);
            foreach (array_keys($predecessors[$target] ?? []) as $source) {
                if (!isset($live[$source])) {
                    $live[$source] = true;
                    $queue[] = $source;
                }
            }
        }

        return $live;
    }

    private static function isBehind(LookHir $look): bool
    {
        return LookKind::Behind === $look->kind || LookKind::NegativeBehind === $look->kind;
    }

    /**
     * @param ProductState $state
     */
    private static function key(array $state): string
    {
        return json_encode($state, \JSON_THROW_ON_ERROR);
    }
}

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
        $bodyOptions = new SolverOptions(
            matchMode: MatchMode::Full,
            maxNfaStates: $this->options->maxNfaStates,
            maxDfaStates: $this->options->maxDfaStates,
        );

        foreach ($marks as $state => $look) {
            $index = \count($this->looks);
            $this->looks[] = $look;
            $this->marks[$state] = $index;
            $dfa = (new DfaBuilder())->determinize((new HirToNfaTransformer($this->pattern, $this->unicode))->lookaroundBody($look, $bodyOptions), $bodyOptions);
            $this->automata[] = $dfa;
            $this->live[] = self::liveStates($dfa);
            if (self::isBehind($look)) {
                $this->runOf[$index] = \count($this->behind);
                $this->behind[] = $index;
            }
        }

        $symbols = $this->alphabet($nfa);
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

            $moves = [];
            foreach ($nfaState->epsilonTransitions as $target) {
                $moves[] = [null, $this->cross($state, $target, $kept, $avoided, $tracked)];
            }

            foreach ($nfaState->transitions as $transition) {
                foreach ($symbols as [$symbol, $class]) {
                    if ($transition->charSet->contains($symbol)) {
                        $moves[] = [$class, $this->read($transition->target, $symbol, $kept, $avoided, $tracked)];
                    }
                }
            }

            foreach ($moves as [$class, $next]) {
                if (null === $next) {
                    continue;
                }

                $key = self::key($next);
                if (!isset($ids[$key])) {
                    $ids[$key] = $builder->createState();
                    $queue[] = $next;
                }

                null === $class ? $builder->addEpsilon($from, $ids[$key]) : $builder->addTransition($from, $class, $ids[$key]);
            }
        }

        return $builder->build(new NfaFragment($ids[self::key($start)], $accepting));
    }

    /**
     * Follows a move that reads nothing: across a mark, the lookaround makes
     * its promise or asks its question; null when it fails at once.
     *
     * @param list<array{int, int}> $kept
     * @param list<array{int, int}> $avoided
     * @param list<int>             $tracked
     *
     * @return array{int, list<array{int, int}>, list<array{int, int}>, list<int>}|null
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

        return $this->promise($target, $index, $dfa->startState, $kept, $avoided, $tracked);
    }

    /**
     * Reads one character: every pending promise and every run moves on it.
     *
     * @param list<array{int, int}> $kept
     * @param list<array{int, int}> $avoided
     * @param list<int>             $tracked
     *
     * @return array{int, list<array{int, int}>, list<array{int, int}>, list<int>}|null
     */
    private function read(int $target, int $symbol, array $kept, array $avoided, array $tracked): ?array
    {
        $tracked = array_map(fn (int $run, int $position): int => $this->step($this->behind[$position], $run, $symbol), $tracked, array_keys($tracked));

        $next = [$target, [], [], $tracked];
        foreach ([...$kept, ...$avoided] as [$index, $run]) {
            $next = $this->promise($next[0], $index, $this->step($index, $run, $symbol), $next[1], $next[2], $next[3]);
            if (null === $next) {
                return null;
            }
        }

        return $next;
    }

    /**
     * Records where a promise's run stands: a positive one kept once its run
     * accepts and broken once it cannot; a negative one broken once its run
     * accepts and forgotten once it cannot.
     *
     * @param list<array{int, int}> $kept
     * @param list<array{int, int}> $avoided
     * @param list<int>             $tracked
     *
     * @return array{int, list<array{int, int}>, list<array{int, int}>, list<int>}|null
     */
    private function promise(int $target, int $index, int $run, array $kept, array $avoided, array $tracked): ?array
    {
        $accepts = self::DEAD !== $run && $this->automata[$index]->getState($run)->isAccepting;
        $alive = self::DEAD !== $run && isset($this->live[$index][$run]);
        $positive = LookKind::Ahead === $this->looks[$index]->kind;

        if ($positive) {
            if ($accepts) {
                return [$target, $kept, $avoided, $tracked];
            }

            if (!$alive) {
                return null;
            }

            $kept[] = [$index, $run];
            $kept = array_values(array_unique($kept, \SORT_REGULAR));
            sort($kept);

            return [$target, $kept, $avoided, $tracked];
        }

        if ($accepts) {
            return null;
        }

        if ($alive) {
            $avoided[] = [$index, $run];
            $avoided = array_values(array_unique($avoided, \SORT_REGULAR));
            sort($avoided);
        }

        return [$target, $kept, $avoided, $tracked];
    }

    private function step(int $index, int $run, int $symbol): int
    {
        return self::DEAD === $run ? self::DEAD : ($this->automata[$index]->getState($run)->transitionFor($symbol) ?? self::DEAD);
    }

    /**
     * One character per stretch of characters every set of the automaton and
     * of the lookarounds' runs treats alike, with the stretch as a set.
     *
     * @return list<array{int, CharSet}>
     */
    private function alphabet(Nfa $nfa): array
    {
        $universe = CharSet::universe($this->unicode);
        $bounds = [];
        foreach ($universe->ranges as [$from, $to]) {
            $bounds[$from] = true;
            $bounds[$to + 1] = true;
        }

        foreach ($nfa->states as $state) {
            foreach ($state->transitions as $transition) {
                foreach ($transition->charSet->ranges as [$from, $to]) {
                    $bounds[$from] = true;
                    $bounds[$to + 1] = true;
                }
            }
        }

        foreach ($this->automata as $dfa) {
            foreach ($dfa->states as $state) {
                foreach (array_keys($state->transitions) as $codePoint) {
                    $bounds[$codePoint] = true;
                    $bounds[$codePoint + 1] = true;
                }
                foreach ($state->ranges as [$from, $to]) {
                    $bounds[$from] = true;
                    $bounds[$to + 1] = true;
                }
            }
        }

        $bounds = array_keys($bounds);
        sort($bounds);

        $symbols = [];
        for ($index = 0, $count = \count($bounds) - 1; $index < $count; $index++) {
            $class = CharSet::range($bounds[$index], $bounds[$index + 1] - 1)->intersect($universe);
            if (!$class->isEmpty()) {
                $symbols[] = [$class->ranges[0][0], $class];
            }
        }

        return $symbols;
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
            foreach ([...array_values($state->transitions), ...array_column($state->ranges, 2)] as $target) {
                $predecessors[$target][$id] = true;
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
     * @param array{int, list<array{int, int}>, list<array{int, int}>, list<int>} $state
     */
    private static function key(array $state): string
    {
        return json_encode($state, \JSON_THROW_ON_ERROR);
    }
}

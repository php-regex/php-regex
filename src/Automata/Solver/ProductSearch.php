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

namespace PHPRegex\Automata\Solver;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Alphabet;
use PHPRegex\Automata\Model\PackedNfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Support\WorkBudget;
use PHPRegex\Automata\Unicode\CodePointHelper;

/**
 * The search for a witness on the product of two automata, built as it is
 * reached:
 *
 * - an intersection pairs the states of the two NFAs: a pair is two NFA
 *   states, so the product holds at most the product of their sizes, and
 *   neither side is determinized;
 * - a difference, L minus R, pairs the states of the left NFA with the
 *   states of the right one determinized lazily (LazyDfa): only the
 *   subsets a string of L reaches are built.
 *
 * The witness is the one the eager search gives: the shortest string, and
 * of those the least, each character the smallest of its class. The pairs
 * are reached breadth first, and none past the depth of the first accepting
 * one: the distance of each to an accepting pair is read backward, and the
 * witness spelled from the start, one class at a time, keeping the pairs
 * that still lie on a shortest path.
 *
 * Each move is charged to the budget, and each pair to the pair limit, as it
 * is reached: neither the starting pairs nor the pairs a class leads to are
 * built ahead. A pair is numbered by the states of both sides, the right one
 * times the size of the left one plus the left one; where that passes the
 * integers of the running PHP, the search gives up.
 *
 * @internal
 */
final class ProductSearch
{
    private const UNNUMBERED_MESSAGE = 'The comparison holds more pairs of states than this PHP numbers.';

    private readonly WorkBudget $budget;

    public function __construct(private readonly SolverOptions $options)
    {
        $this->budget = new WorkBudget($options->maxTransitionsProcessed, 'comparison', 1, 0, 0);
    }

    /**
     * The least shortest string both NFAs match, as the classes of their
     * shared alphabet, or null when none does.
     *
     * @throws ComplexityException
     *
     * @return list<int>|null
     */
    public function intersection(PackedNfa $left, PackedNfa $right): ?array
    {
        $width = $left->stateCount;
        if ($right->stateCount > intdiv(\PHP_INT_MAX, $width)) {
            throw new ComplexityException(self::UNNUMBERED_MESSAGE, diagnostic: ['phase' => 'comparison']);
        }

        if (self::acceptsAny($left, $left->closure($left->start)) && self::acceptsAny($right, $right->closure($right->start))) {
            return [];
        }

        return $this->search(
            (static function () use ($left, $right, $width): \Generator {
                foreach ($right->closure($right->start) as $r) {
                    foreach ($left->closure($left->start) as $l) {
                        yield $r * $width + $l;
                    }
                }
            })(),
            static function (int $pair) use ($left, $right, $width): \Generator {
                $l = $pair % $width;
                $r = intdiv($pair, $width);
                foreach ($left->classesFrom($l) as $class) {
                    $rightTargets = $right->step($r, $class);
                    if ([] === $rightTargets) {
                        continue;
                    }

                    $leftTargets = $left->step($l, $class);
                    foreach ($rightTargets as $rightTarget => $_) {
                        foreach ($leftTargets as $leftTarget => $__) {
                            yield $class => $rightTarget * $width + $leftTarget;
                        }
                    }
                }
            },
            static fn (int $pair): bool => $left->isAccepting($pair % $width) && $right->isAccepting(intdiv($pair, $width)),
        );
    }

    /**
     * The least shortest string the left NFA matches and the right DFA
     * does not, as classes, or null when none does.
     *
     * @throws ComplexityException
     *
     * @return list<int>|null
     */
    public function difference(PackedNfa $left, LazyDfa $right): ?array
    {
        $width = $left->stateCount;
        // The right side grows as it is read: past this state, a pair is
        // not numbered.
        $lastRight = intdiv(\PHP_INT_MAX, $width) - 1;
        $starts = $left->closure($left->start);
        if (self::acceptsAny($left, $starts) && !$right->isAccepting($right->start)) {
            return [];
        }

        return $this->search(
            (static function () use ($starts, $right, $width): \Generator {
                foreach ($starts as $l) {
                    yield $right->start * $width + $l;
                }
            })(),
            static function (int $pair) use ($left, $right, $width, $lastRight): \Generator {
                $l = $pair % $width;
                $r = intdiv($pair, $width);
                foreach ($left->classesFrom($l) as $class) {
                    $next = $right->next($r, $class);
                    if ($next > $lastRight) {
                        throw new ComplexityException(self::UNNUMBERED_MESSAGE, diagnostic: ['phase' => 'comparison']);
                    }

                    foreach ($left->step($l, $class) as $leftTarget => $_) {
                        yield $class => $next * $width + $leftTarget;
                    }
                }
            },
            static fn (int $pair): bool => $left->isAccepting($pair % $width) && !$right->isAccepting(intdiv($pair, $width)),
        );
    }

    /**
     * The characters of the classes: the smallest of each, a byte when both
     * automata read bytes, else the UTF-8 of a code point.
     *
     * @param list<int> $classes
     */
    public static function spell(array $classes, Alphabet $alphabet, bool $bytes): string
    {
        $text = '';
        foreach ($classes as $class) {
            $codePoint = $alphabet->representative[$class];
            $text .= $bytes ? \chr($codePoint) : (CodePointHelper::toString($codePoint) ?? '');
        }

        return $text;
    }

    /**
     * @param iterable<int>                     $starts    the starting pairs, none accepting
     * @param \Closure(int): iterable<int, int> $expand    each pair a pair leads to, by its class
     * @param \Closure(int): bool               $accepting
     *
     * @throws ComplexityException
     *
     * @return list<int>|null
     */
    private function search(iterable $starts, \Closure $expand, \Closure $accepting): ?array
    {
        /** @var array<int, int> $index each pair's place, by pair */
        $index = [];
        /** @var list<int> $pairs */
        $pairs = [];
        /** @var list<int> $depths each place's distance from the start */
        $depths = [];
        /** @var array<int, array<int, list<int>>> $successors the places each class leads to, by place */
        $successors = [];
        /** @var list<list<int>> $predecessors */
        $predecessors = [];
        $accepted = [];
        $edges = 0;

        $startPlaces = [];
        foreach ($starts as $pair) {
            if (!isset($index[$pair])) {
                $this->admit($pair, $index, $pairs, $depths, $predecessors, 0);
            }

            $startPlaces[$index[$pair]] = true;
        }

        // Breadth first: once a depth holds an accepting pair, the deeper
        // ones are reached by no shortest witness.
        $found = null;
        for ($place = 0; $place < \count($pairs) && (null === $found || $depths[$place] < $found); $place++) {
            $byClass = [];
            foreach ($expand($pairs[$place]) as $class => $target) {
                $this->budget->updateStats(\count($pairs), $edges, 0);
                $this->budget->consume();
                $edges++;
                if (!isset($index[$target])) {
                    $this->admit($target, $index, $pairs, $depths, $predecessors, $depths[$place] + 1);
                    if ($accepting($target)) {
                        $accepted[] = $index[$target];
                        $found ??= $depths[$place] + 1;
                    }
                }

                $byClass[$class][] = $index[$target];
                $predecessors[$index[$target]][] = $place;
            }

            ksort($byClass, \SORT_NUMERIC);
            $successors[$place] = $byClass;
        }

        if ([] === $accepted) {
            return null;
        }

        // The distance of each place to an accepting one, read backward.
        $distance = array_fill_keys($accepted, 0);
        $queue = $accepted;
        for ($at = 0; $at < \count($queue); $at++) {
            $place = $queue[$at];
            foreach ($predecessors[$place] as $predecessor) {
                if (!isset($distance[$predecessor])) {
                    $distance[$predecessor] = $distance[$place] + 1;
                    $queue[] = $predecessor;
                }
            }
        }

        $length = null;
        foreach ($startPlaces as $place => $_) {
            if (isset($distance[$place]) && (null === $length || $distance[$place] < $length)) {
                $length = $distance[$place];
            }
        }

        if (null === $length) {
            return null;
        }

        // Spelled from the start: the least class that keeps a pair on a
        // shortest path, and every pair it keeps.
        $current = [];
        foreach ($startPlaces as $place => $_) {
            if (($distance[$place] ?? null) === $length) {
                $current[$place] = true;
            }
        }

        $classes = [];
        for ($left = $length - 1; $left >= 0; $left--) {
            $best = null;
            $next = [];
            foreach ($current as $place => $_) {
                foreach ($successors[$place] as $class => $targets) {
                    if (null !== $best && $class > $best) {
                        break;
                    }

                    foreach ($targets as $target) {
                        if (($distance[$target] ?? null) !== $left) {
                            continue;
                        }

                        if (null === $best || $class < $best) {
                            $best = $class;
                            $next = [];
                        }

                        $next[$target] = true;
                    }
                }
            }

            if (null === $best) {
                // Every pair at distance d > 0 leads to one at d - 1.
                throw new \LogicException('A shortest path to an accepting pair was lost.');
            }

            $classes[] = $best;
            $current = $next;
        }

        return $classes;
    }

    /**
     * Keeps a pair newly reached, under the pair limit.
     *
     * @param array<int, int> $index
     * @param list<int>       $pairs
     * @param list<int>       $depths
     * @param list<list<int>> $predecessors
     *
     * @throws ComplexityException
     */
    private function admit(int $pair, array &$index, array &$pairs, array &$depths, array &$predecessors, int $depth): void
    {
        if (\count($pairs) >= $this->options->maxDfaStates) {
            throw new ComplexityException(\sprintf('The comparison explored more than %d pairs of DFA states.', $this->options->maxDfaStates), diagnostic: ['phase' => 'comparison', 'limit' => $this->options->maxDfaStates]);
        }

        $index[$pair] = \count($pairs);
        $pairs[] = $pair;
        $depths[] = $depth;
        $predecessors[] = [];
    }

    /**
     * @param list<int> $states
     */
    private static function acceptsAny(PackedNfa $nfa, array $states): bool
    {
        foreach ($states as $state) {
            if ($nfa->isAccepting($state)) {
                return true;
            }
        }

        return false;
    }
}

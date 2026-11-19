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
 * reached are walked forward, the distance of each to an accepting pair
 * read backward, and the witness spelled from the start, one class at a
 * time, keeping the pairs that still lie on a shortest path: the walk stays
 * within the pairs, polynomial in the size of the product.
 *
 * @internal
 */
final class ProductSearch
{
    /**
     * Two NFA or DFA state numbers to one pair number.
     */
    private const STRIDE = 1 << 31;

    private WorkBudget $budget;

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
        $starts = [];
        foreach ($left->closure($left->start) as $l) {
            foreach ($right->closure($right->start) as $r) {
                $starts[] = $l * self::STRIDE + $r;
            }
        }

        return $this->search(
            $starts,
            static function (int $pair) use ($left, $right): array {
                $l = intdiv($pair, self::STRIDE);
                $r = $pair % self::STRIDE;
                $successors = [];
                foreach ($left->classesFrom($l) as $class) {
                    $rightTargets = $right->step($r, $class);
                    if ([] === $rightTargets) {
                        continue;
                    }

                    foreach ($left->step($l, $class) as $leftTarget => $_) {
                        foreach ($rightTargets as $rightTarget => $__) {
                            $successors[$class][] = $leftTarget * self::STRIDE + $rightTarget;
                        }
                    }
                }

                return $successors;
            },
            static fn (int $pair): bool => $left->isAccepting(intdiv($pair, self::STRIDE)) && $right->isAccepting($pair % self::STRIDE),
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
        $starts = [];
        foreach ($left->closure($left->start) as $l) {
            $starts[] = $l * self::STRIDE + $right->start;
        }

        return $this->search(
            $starts,
            static function (int $pair) use ($left, $right): array {
                $l = intdiv($pair, self::STRIDE);
                $r = $pair % self::STRIDE;
                $successors = [];
                foreach ($left->classesFrom($l) as $class) {
                    $next = $right->next($r, $class);
                    foreach ($left->step($l, $class) as $leftTarget => $_) {
                        $successors[$class][] = $leftTarget * self::STRIDE + $next;
                    }
                }

                return $successors;
            },
            static fn (int $pair): bool => $left->isAccepting(intdiv($pair, self::STRIDE)) && !$right->isAccepting($pair % self::STRIDE),
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
     * @param list<int>                                $starts
     * @param callable(int): array<int, list<int>>     $expand    the pairs each class leads to
     * @param callable(int): bool                      $accepting
     *
     * @throws ComplexityException
     *
     * @return list<int>|null
     */
    private function search(array $starts, callable $expand, callable $accepting): ?array
    {
        /** @var array<int, int> $index each pair's place, by pair */
        $index = [];
        /** @var list<int> $pairs */
        $pairs = [];
        /** @var list<array<int, list<int>>> $successors the places each class leads to, by place */
        $successors = [];
        /** @var list<list<int>> $predecessors */
        $predecessors = [];
        $accepted = [];
        $edges = 0;

        $startPlaces = [];
        foreach ($starts as $pair) {
            if (!isset($index[$pair])) {
                $index[$pair] = \count($pairs);
                $pairs[] = $pair;
                $predecessors[] = [];
            }

            $startPlaces[$index[$pair]] = true;
            if ($accepting($pair)) {
                return [];
            }
        }

        for ($place = 0; $place < \count($pairs); $place++) {
            $pair = $pairs[$place];
            if ($accepting($pair)) {
                $accepted[] = $place;
            }

            $byClass = [];
            foreach ($expand($pair) as $class => $targets) {
                foreach ($targets as $target) {
                    $this->budget->updateStats(\count($pairs), $edges, 0);
                    $this->budget->consume();
                    $edges++;
                    if (!isset($index[$target])) {
                        if (\count($pairs) >= $this->options->maxDfaStates) {
                            throw new ComplexityException(\sprintf('The comparison explored more than %d pairs of DFA states.', $this->options->maxDfaStates), diagnostic: ['phase' => 'comparison', 'limit' => $this->options->maxDfaStates]);
                        }

                        $index[$target] = \count($pairs);
                        $pairs[] = $target;
                        $predecessors[] = [];
                    }

                    $byClass[$class][] = $index[$target];
                    $predecessors[$index[$target]][] = $place;
                }
            }

            $successors[] = $byClass;
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
}

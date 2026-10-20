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

/**
 * The strongly connected components of a graph, by Tarjan's algorithm
 * without recursion: the components in topological order, a component
 * before the ones it reaches, each with its members in the order the walk
 * closes them, and the component of each node. Each move of the walk takes
 * a step of the budget, one along each edge and one to close each node.
 *
 * @internal
 */
final class StronglyConnectedComponents
{
    /**
     * @param list<list<int>> $components
     * @param array<int, int> $componentOf
     */
    private function __construct(public readonly array $components, public readonly array $componentOf) {}

    /**
     * @param array<int, list<int>> $graph every node a key, its successors among the keys
     *
     * @throws ModelLimit
     */
    public static function of(array $graph, Budget $budget): self
    {
        /** @var array<int, int> $index */
        $index = [];
        /** @var array<int, int> $low */
        $low = [];
        /** @var array<int, true> $onStack */
        $onStack = [];
        /** @var list<int> $stack */
        $stack = [];
        $counter = 0;
        /** @var list<list<int>> $closed */
        $closed = [];

        foreach (array_keys($graph) as $root) {
            if (isset($index[$root])) {
                continue;
            }

            // The path of the depth-first walk, each node with the next
            // successor it visits.
            $work = [[$root, 0]];
            $index[$root] = $low[$root] = $counter++;
            $stack[] = $root;
            $onStack[$root] = true;

            while ([] !== $work) {
                $budget->step();
                $top = \count($work) - 1;
                [$node, $position] = $work[$top];
                $successors = $graph[$node];
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
                        $member = array_pop($stack);
                        unset($onStack[$member]);
                        $members[] = $member;
                        if ($member === $node) {
                            break;
                        }
                    }

                    $closed[] = $members;
                }
            }
        }

        // Tarjan closes a component after every component it reaches.
        $components = array_reverse($closed);
        $componentOf = [];
        foreach ($components as $component => $members) {
            foreach ($members as $member) {
                $componentOf[$member] = $component;
            }
        }

        return new self($components, $componentOf);
    }
}

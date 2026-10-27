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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Redos\Backtrack\Budget;
use PHPRegex\Redos\Backtrack\StronglyConnectedComponents;
use PHPRegex\Redos\Exception\ModelLimitException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The strongly connected components of a graph, the one walk the ambiguity
 * search and the search-cost proof share: each component with its members
 * in the order the walk closes them, the components in topological order,
 * and the component of each node.
 */
final class StronglyConnectedComponentsTest extends TestCase
{
    /**
     * @param array<int, list<int>> $graph
     * @param list<list<int>>       $components
     */
    #[Test]
    #[DataProvider('provideGraphs')]
    public function test_components_in_topological_order(array $graph, array $components): void
    {
        $found = StronglyConnectedComponents::of($graph, new Budget(1_000, 1_000));

        $this->assertSame($components, $found->components);
        foreach ($components as $index => $members) {
            foreach ($members as $member) {
                $this->assertSame($index, $found->componentOf[$member]);
            }
        }

        $this->assertCount(\count($graph), $found->componentOf);
    }

    /**
     * @return iterable<string, array{graph: array<int, list<int>>, components: list<list<int>>}>
     */
    public static function provideGraphs(): iterable
    {
        yield 'no node' => ['graph' => [], 'components' => []];
        yield 'one node without a loop' => ['graph' => [4 => []], 'components' => [[4]]];
        yield 'one node with a loop' => ['graph' => [4 => [4]], 'components' => [[4]]];
        yield 'a cycle then a node it reaches' => ['graph' => [0 => [1], 1 => [2], 2 => [0, 3], 3 => []], 'components' => [[2, 1, 0], [3]]];
        yield 'a chain, the first node first' => ['graph' => [0 => [1], 1 => [2], 2 => []], 'components' => [[0], [1], [2]]];
        yield 'two separate nodes, the later root first' => ['graph' => [5 => [5], 6 => []], 'components' => [[6], [5]]];
        yield 'two cycles joined one way' => ['graph' => [0 => [1], 1 => [0, 2], 2 => [3], 3 => [2]], 'components' => [[1, 0], [3, 2]]];
        yield 'a node reached back from a later root' => ['graph' => [0 => [], 1 => [0]], 'components' => [[1], [0]]];
    }

    #[Test]
    public function test_each_move_of_the_walk_takes_a_step_of_the_budget(): void
    {
        // Three nodes in a chain: one step along each of its two edges, one
        // to close each node.
        $graph = [0 => [1], 1 => [2], 2 => []];

        $this->assertSame([[0], [1], [2]], StronglyConnectedComponents::of($graph, new Budget(5, 100))->components);

        $this->expectException(ModelLimitException::class);
        StronglyConnectedComponents::of($graph, new Budget(4, 100));
    }
}

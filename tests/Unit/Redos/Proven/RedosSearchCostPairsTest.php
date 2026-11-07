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

use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Backtrack\Budget;
use PHPRegex\Redos\Backtrack\ItemAutomaton;
use PHPRegex\Redos\Backtrack\PnfaBuilder;
use PHPRegex\Redos\Backtrack\SearchCostProver;
use PHPRegex\Redos\Exception\ModelLimitException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedosSearchCostPairsTest extends TestCase
{
    #[Test]
    public function test_pairs_keep_the_original_priority_and_order(): void
    {
        $automaton = self::automaton();
        $automaton->representatives = [33, 97, 10, 98, 32];
        $classes = [4, 2, 0, 3, 1];
        $ranked = [[], [], []];
        $contexts = [3, 1, 2, 1, 3];
        foreach (range(0, 4) as $first) {
            foreach (range(0, 4) as $second) {
                if ($first === $second) {
                    continue;
                }
                $rank = $contexts[$first] === $contexts[$second] ? 2 : (1 === $contexts[$first] ? 0 : 1);
                $ranked[$rank][] = [$first, $second];
            }
        }

        $pairs = new \ReflectionMethod(SearchCostProver::class, 'pairsOf');
        $this->assertSame(\array_slice(array_merge(...$ranked), 0, 16), $pairs->invoke(null, $automaton, $classes, new Budget(100, 1)));
    }

    #[Test]
    public function test_pair_generation_spends_the_shared_budget(): void
    {
        $automaton = self::automaton();
        $pairs = new \ReflectionMethod(SearchCostProver::class, 'pairsOf');
        $this->expectException(ModelLimitException::class);
        $pairs->invoke(null, $automaton, array_keys($automaton->representatives), new Budget(0, 1));
    }

    #[Test]
    public function test_many_classes_need_only_linear_work_and_keep_sixteen_pairs(): void
    {
        $automaton = self::automaton();
        // All the same context: there are no higher-priority pairs.
        $automaton->representatives = range(0x1000, 0x13ff);
        $pairs = new \ReflectionMethod(SearchCostProver::class, 'pairsOf');
        $actual = $pairs->invoke(null, $automaton, range(0, 1023), new Budget(10_000, 1));

        $this->assertSame(array_map(static fn (int $second): array => [0, $second], range(1, 16)), $actual);
    }

    private static function automaton(): ItemAutomaton
    {
        $regex = RegexParser::create()->parse('/^\\b[a-z]+$/m');
        $budget = new Budget(250_000, 2_000);
        $pnfa = (new PnfaBuilder($regex, $budget, 16))->build()[0];

        return new ItemAutomaton($pnfa, $budget, [ItemAutomaton::CONTEXT_START]);
    }
}

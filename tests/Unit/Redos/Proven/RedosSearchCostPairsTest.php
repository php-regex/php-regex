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
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Tests\Benchmark\Support\PeakMemory;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedosSearchCostPairsTest extends TestCase
{
    use PcreGates;

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
        $automaton->representatives = range(0x1000, 0x13FF);
        $pairs = new \ReflectionMethod(SearchCostProver::class, 'pairsOf');
        $actual = $pairs->invoke(null, $automaton, range(0, 1023), new Budget(10_000, 1));

        $this->assertSame(array_map(static fn (int $second): array => [0, $second], range(1, 16)), $actual);
    }

    /**
     * Six hundred letters after the run split its class into some six
     * hundred alphabet classes, all read on the loop: every ordered pair of
     * them used to be built before sixteen were kept, a peak growth of
     * 105 MB in 1.1 s (PHP 8.4.27, PCRE2 10.49), where the analysis now takes
     * 13 MB. The witness stays, and the engine fails on the subject it
     * builds: preg_match() returns 0.
     */
    #[Test]
    public function test_many_looped_classes_keep_the_witness_within_the_memory_bound(): void
    {
        $this->requiresPhp('8.2', 'The peak memory of an analysis', 'has no memory_reset_peak_usage()');

        $letters = '';
        for ($i = 0; $i < 600; $i++) {
            $letters .= \sprintf('\x{%X}', 0x100 + 2 * $i);
        }
        $pattern = '/\b[\x{100}-\x{FFF}]+x'.$letters.'/u';
        $this->assertSame(0, preg_match($pattern, ''));

        PeakMemory::reset();
        $before = memory_get_peak_usage();
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;
        $growth = memory_get_peak_usage() - $before;

        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertSame(2, $cost->degree);
        $this->assertSame(0, preg_match($pattern, $cost->build(100)));
        $this->assertLessThan(48 * 1024 * 1024, $growth, \sprintf('peak memory grew by %.1f MB', $growth / 1048576));
    }

    /**
     * The pairs are chosen on the analysis budget, the one every step of the
     * proof spends. At the smallest budget that still holds the witness the
     * engine fails on the subject it builds; one step fewer and the search
     * cost is given up while one attempt stays proven linear: a budget run
     * out in the pair choice drops the witness, it never fails the analysis.
     * The smallest budget depends on the PCRE2 release the parser targets
     * (13,936 steps on 10.44 and 10.49, 13,708 on 10.40), so it is searched.
     */
    #[Test]
    public function test_a_budget_one_step_short_of_the_pairs_gives_the_search_cost_up(): void
    {
        $letters = '';
        for ($i = 0; $i < 20; $i++) {
            $letters .= \sprintf('\x{%X}', 0x100 + 2 * $i);
        }
        $pattern = '/\b[\x{100}-\x{FFF}]+x'.$letters.'/u';
        $this->assertSame(0, preg_match($pattern, ''));

        $low = 1;
        $high = 250_000;
        $this->assertInstanceOf(RedosSearchCost::class, self::analyzeWithin($pattern, $high)->searchCost);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (null === self::analyzeWithin($pattern, $middle)->searchCost) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $cost = self::analyzeWithin($pattern, $low)->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertSame(2, $cost->degree);
        $this->assertSame(0, preg_match($pattern, $cost->build(100)));

        $short = self::analyzeWithin($pattern, $low - 1);
        $this->assertNull($short->searchCost);
        $this->assertSame(RedosProof::Proven, $short->proof);
        $this->assertSame(RedosComplexity::Linear, $short->complexity);
    }

    private static function analyzeWithin(string $pattern, int $steps): RedosAnalysis
    {
        return (new RedosAnalyzer(options: new RedosOptions(maxSteps: $steps)))->analyze($pattern);
    }

    private static function automaton(): ItemAutomaton
    {
        $regex = RegexParser::create()->parse('/^\\b[a-z]+$/m');
        $budget = new Budget(250_000, 2_000);
        $pnfa = (new PnfaBuilder($regex, $budget, 16))->build()[0];

        return new ItemAutomaton($pnfa, $budget, [ItemAutomaton::CONTEXT_START]);
    }
}

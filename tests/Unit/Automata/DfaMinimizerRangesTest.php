<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Automata;

use PhpRegex\Automata\Minimization\DfaMinimizer;
use PhpRegex\Automata\Minimization\HopcroftWorklist;
use PhpRegex\Automata\Minimization\MinimizationAlgorithmInterface;
use PhpRegex\Automata\Minimization\MoorePartitionRefinement;
use PhpRegex\Automata\Model\Dfa;
use PhpRegex\Automata\Model\DfaState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests DFA minimization when states use range-based transitions
 * rather than individual character transitions.
 */
final class DfaMinimizerRangesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAlgorithms')]
    public function test_minimize_with_range_transitions(MinimizationAlgorithmInterface $algorithm): void
    {
        $alphabetRanges = [[48, 57], [65, 90], [97, 122]];

        $states = [
            0 => new DfaState(0, [], false, [[48, 57, 1], [65, 90, 2], [97, 122, 2]]),
            1 => new DfaState(1, [], true, [[48, 57, 1], [65, 90, 1], [97, 122, 1]]),
            2 => new DfaState(2, [], true, [[48, 57, 2], [65, 90, 2], [97, 122, 2]]),
        ];

        $dfa = new Dfa(0, $states, $alphabetRanges);
        $minimized = (new DfaMinimizer($algorithm))->minimize($dfa);

        $this->assertCount(2, $minimized->states);

        $this->assertFalse($minimized->states[$minimized->startState]->isAccepting);
    }

    #[Test]
    #[DataProvider('provideAlgorithms')]
    public function test_minimize_preserves_ranges_in_output(MinimizationAlgorithmInterface $algorithm): void
    {
        $alphabetRanges = [[48, 57], [65, 90]];

        $states = [
            0 => new DfaState(0, [], false, [[48, 57, 1], [65, 90, 1]]),
            1 => new DfaState(1, [], true, [[48, 57, 1], [65, 90, 1]]),
        ];

        $dfa = new Dfa(0, $states, $alphabetRanges);
        $minimized = (new DfaMinimizer($algorithm))->minimize($dfa);

        $this->assertCount(2, $minimized->states);

        foreach ($minimized->states as $state) {
            $this->assertNotEmpty($state->ranges, 'Minimized state should preserve ranges.');
        }
    }

    #[Test]
    #[DataProvider('provideAlgorithms')]
    public function test_minimize_single_state_returns_unchanged(MinimizationAlgorithmInterface $algorithm): void
    {
        $dfa = new Dfa(0, [
            0 => new DfaState(0, [97 => 0], true),
        ]);

        $minimized = (new DfaMinimizer($algorithm))->minimize($dfa);

        $this->assertCount(1, $minimized->states);
        $this->assertSame(0, $minimized->startState);
    }

    #[Test]
    #[DataProvider('provideAlgorithms')]
    public function test_minimize_all_accepting_merges_to_one_group(MinimizationAlgorithmInterface $algorithm): void
    {
        $states = [
            0 => new DfaState(0, [97 => 1], true),
            1 => new DfaState(1, [97 => 0], true),
        ];

        $dfa = new Dfa(0, $states);
        $minimized = (new DfaMinimizer($algorithm))->minimize($dfa);

        $this->assertCount(1, $minimized->states);
        $this->assertTrue($minimized->states[$minimized->startState]->isAccepting);
    }

    /**
     * @return \Generator<string, array{\PhpRegex\Automata\Minimization\MinimizationAlgorithmInterface}>
     */
    public static function provideAlgorithms(): \Generator
    {
        yield 'moore' => [new MoorePartitionRefinement()];
        yield 'hopcroft' => [new HopcroftWorklist()];
    }
}

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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Minimization\DfaMinimizer;
use PHPRegex\Automata\Minimization\HopcroftWorklist;
use PHPRegex\Automata\Minimization\MinimizationAlgorithmInterface;
use PHPRegex\Automata\Minimization\MoorePartitionRefinement;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DfaMinimizerTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAlgorithms')]
    public function test_minimize_merges_equivalent_states(MinimizationAlgorithmInterface $algorithm): void
    {
        $alphabet = [97, 98, 99];
        $states = [
            0 => new DfaState(0, $this->transitions($alphabet, 3, [97 => 1, 98 => 2]), false),
            1 => new DfaState(1, $this->transitions($alphabet, 3), true),
            2 => new DfaState(2, $this->transitions($alphabet, 3), true),
            3 => new DfaState(3, $this->transitions($alphabet, 3), false),
        ];

        $dfa = new Dfa(0, $states);
        $minimized = (new DfaMinimizer($algorithm))->minimize($dfa);

        $this->assertCount(3, $minimized->states);

        foreach (['', 'a', 'b', 'ab', 'ba', 'aa', 'bb', 'c'] as $input) {
            $this->assertSame($this->accepts($dfa, $input), $this->accepts($minimized, $input));
        }
    }

    #[Test]
    #[DataProvider('provideAlgorithms')]
    public function test_minimize_merges_dead_states(MinimizationAlgorithmInterface $algorithm): void
    {
        $alphabet = [97, 98];
        $states = [
            0 => new DfaState(0, $this->transitions($alphabet, 1, [97 => 1, 98 => 2]), true),
            1 => new DfaState(1, $this->transitions($alphabet, 1), false),
            2 => new DfaState(2, $this->transitions($alphabet, 2), false),
        ];

        $dfa = new Dfa(0, $states);
        $minimized = (new DfaMinimizer($algorithm))->minimize($dfa);

        $this->assertCount(2, $minimized->states);
    }

    #[Test]
    #[DataProvider('provideAlgorithms')]
    public function test_minimize_handles_large_codepoints(MinimizationAlgorithmInterface $algorithm): void
    {
        $snowman = 0x2603;
        $alphabet = [97, $snowman];
        $states = [
            0 => new DfaState(0, $this->transitions($alphabet, 1, [$snowman => 2]), false),
            1 => new DfaState(1, $this->transitions($alphabet, 1), true),
            2 => new DfaState(2, $this->transitions($alphabet, 2), true),
        ];

        $dfa = new Dfa(0, $states);
        $minimized = (new DfaMinimizer($algorithm))->minimize($dfa);

        $this->assertCount(2, $minimized->states);
        $this->assertArrayHasKey($snowman, $minimized->states[$minimized->startState]->transitions);
    }

    public static function provideAlgorithms(): \Generator
    {
        yield 'moore' => [new MoorePartitionRefinement()];
        yield 'hopcroft' => [new HopcroftWorklist()];
    }

    private function accepts(Dfa $dfa, string $input): bool
    {
        $stateId = $dfa->startState;
        $length = \strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $stateId = $dfa->getState($stateId)->transitions[\ord($input[$index])];
        }

        return $dfa->getState($stateId)->isAccepting;
    }

    /**
     * @param array<int>      $alphabet
     * @param array<int, int> $overrides
     *
     * @return array<int, int>
     */
    private function transitions(array $alphabet, int $defaultTarget, array $overrides = []): array
    {
        $transitions = [];

        foreach ($alphabet as $symbol) {
            $transitions[$symbol] = $defaultTarget;
        }

        foreach ($overrides as $symbol => $target) {
            $transitions[$symbol] = $target;
        }

        return $transitions;
    }
}

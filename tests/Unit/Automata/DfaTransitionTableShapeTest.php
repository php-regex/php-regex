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

use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The table a determinized state carries follows its alphabet: a byte
 * DFA holds one entry per byte 0-255, so any byte is one lookup, while a
 * code-point DFA holds one entry per partition range and resolves the
 * rest through its range table — a million code points cannot become a
 * million entries. Both determinization strategies keep the same shape.
 */
final class DfaTransitionTableShapeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_a_byte_dfa_carries_one_entry_per_byte(DeterminizationAlgorithm $algorithm): void
    {
        $dfa = $this->compile('/a/', $algorithm);

        $state = $dfa->getState($dfa->startState);

        $this->assertSame(\range(0, 255), \array_keys($state->transitions));
        $this->assertTrue($dfa->getState($state->transitions[\ord('a')])->isAccepting);
        $this->assertFalse($dfa->getState($state->transitions[\ord('b')])->isAccepting);
    }

    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_a_code_point_dfa_carries_one_entry_per_range(DeterminizationAlgorithm $algorithm): void
    {
        $dfa = $this->compile('/a/u', $algorithm);

        $state = $dfa->getState($dfa->startState);

        $this->assertSame([0, 97, 98], \array_keys($state->transitions));
        $this->assertSame([[0, 96], [97, 97], [98, 0x10FFFF]], $dfa->alphabetRanges);
    }

    /**
     * @return iterable<string, array{algorithm: DeterminizationAlgorithm}>
     */
    public static function provideDeterminizationAlgorithms(): iterable
    {
        yield 'subset' => ['algorithm' => DeterminizationAlgorithm::Subset];
        yield 'subset-indexed' => ['algorithm' => DeterminizationAlgorithm::SubsetIndexed];
    }

    private function compile(string $pattern, DeterminizationAlgorithm $algorithm): Dfa
    {
        return (new LanguageSolver())->compile($pattern, new SolverOptions(
            minimizeDfa: false,
            determinizationAlgorithm: $algorithm,
        ));
    }
}

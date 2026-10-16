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
 * A determinized state reads its moves as ranges, each its first code point,
 * its last and the state it leads to, held as three ints in one flat list: a
 * Unicode class splits the alphabet into hundreds of ranges a state, and an
 * array per range held four to six times what the ints do. A byte DFA also
 * keeps one entry per byte 0-255, so any byte is one lookup. Both
 * determinization strategies keep the same shape.
 */
final class DfaTransitionTableShapeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_a_byte_dfa_reads_every_byte(DeterminizationAlgorithm $algorithm): void
    {
        $dfa = $this->compile('/a/', $algorithm);

        $state = $dfa->getState($dfa->startState);
        $matched = $state->transitionFor(\ord('a'));
        $other = $state->transitionFor(\ord('b'));
        $this->assertNotNull($matched);
        $this->assertNotNull($other);

        $this->assertTrue($dfa->getState($matched)->isAccepting);
        $this->assertFalse($dfa->getState($other)->isAccepting);
        $this->assertSame([[0, 96, $other], [97, 97, $matched], [98, 255, $other]], $state->ranges());
        $this->assertSame(3, $state->rangeCount());
        foreach (\range(0, 255) as $byte) {
            $this->assertSame(97 === $byte ? $matched : $other, $state->transitionFor($byte));
        }
    }

    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_a_code_point_dfa_reads_one_range_per_partition(DeterminizationAlgorithm $algorithm): void
    {
        $dfa = $this->compile('/a/u', $algorithm);

        $state = $dfa->getState($dfa->startState);
        $matched = $state->transitionFor(97);
        $other = $state->transitionFor(0x10FFFF);
        $this->assertNotNull($matched);
        $this->assertNotNull($other);

        $this->assertSame([[0, 96, $other], [97, 97, $matched], [98, 0x10FFFF, $other]], $state->ranges());
        $this->assertSame(3, $state->rangeCount());
        $this->assertSame($other, $state->transitionFor(0x4E00));
        $this->assertSame([[0, 96], [97, 97], [98, 0x10FFFF]], $dfa->alphabetRanges);
    }

    /**
     * [\pL0-9] splits the alphabet into some fourteen hundred ranges, each
     * written in every one of the 129 states: about 177,000 ranges, which
     * held 58 MB, some 330 bytes a range, as an array per range and an
     * index of their starts. Three ints a range in one list hold about 23 MB,
     * the room a PHP list keeps to grow included.
     */
    #[Test]
    public function test_a_unicode_dfa_holds_its_ranges_as_ints(): void
    {
        $solver = new LanguageSolver();
        $solver->compile('/a/u');
        gc_collect_cycles();

        $before = memory_get_usage();
        $dfa = $solver->compile('/[\pL0-9]*0[0-9]{6}$/um', new SolverOptions(minimizeDfa: false));
        gc_collect_cycles();
        $held = memory_get_usage() - $before;

        $ranges = 0;
        foreach ($dfa->states as $state) {
            $ranges += $state->rangeCount();
        }

        $this->assertGreaterThan(150_000, $ranges);
        $this->assertLessThan(165, $held / $ranges, \sprintf('%d bytes held for %d ranges.', $held, $ranges));
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

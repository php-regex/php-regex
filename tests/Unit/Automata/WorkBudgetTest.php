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
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Minimization\DfaMinimizer;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkBudgetTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_comparison_bounds_the_product_of_individually_small_dfas(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(maxDfaStates: 40, maxTransitionsProcessed: null);
        $solver->compile('/(?:a{11})+/', $options);
        $solver->compile('/(?:a{13})+/', $options);

        // PCRE matches the witness whole with both, and no shorter run of "a".
        $witness = str_repeat('a', 143);
        $this->assertSame([1, 1], [preg_match('/^(?:a{11})+$/D', $witness), preg_match('/^(?:a{13})+$/D', $witness)]);
        $this->assertSame([], array_filter(range(1, 142), static fn (int $length): bool => 1 === preg_match('/^(?:a{11})+$/D', str_repeat('a', $length)) && 1 === preg_match('/^(?:a{13})+$/D', str_repeat('a', $length))));

        $result = $solver->intersection('/(?:a{11})+/', '/(?:a{13})+/', new SolverOptions(maxDfaStates: 200));
        $this->assertSame($witness, $result->example);

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('The comparison explored more than 40 pairs of DFA states.');
        $solver->intersection('/(?:a{11})+/', '/(?:a{13})+/', $options);
    }

    #[Test]
    #[DataProvider('provideComparisons')]
    public function test_comparison_charges_even_transitions_to_visited_pairs(string $method): void
    {
        // One pair is retained, but every alphabet range still takes work.
        $dfa = new Dfa(0, [0 => new DfaState(0, [97 => 0, 98 => 0], false)], [[97, 97], [98, 98]], 97, 98);
        $cache = new class($dfa) implements DfaCacheInterface {
            public function __construct(private readonly Dfa $dfa) {}

            public function get(string $key): Dfa
            {
                return $this->dfa;
            }

            public function set(string $key, Dfa $dfa): void {}
        };
        $solver = new LanguageSolver(dfaCache: $cache);

        try {
            $solver->$method('/a/', '/b/', new SolverOptions(maxTransitionsProcessed: 1));
            $this->fail('Expected comparison work to exceed its budget.');
        } catch (ComplexityException $exception) {
            $this->assertSame('comparison', $exception->getDiagnostic()['phase'] ?? null);
            $this->assertSame(2, $exception->getDiagnostic()['consumed'] ?? null);
        }
    }

    /**
     * @return iterable<string, array{method: string}>
     */
    public static function provideComparisons(): iterable
    {
        foreach (['intersection', 'subsetOf', 'equivalent'] as $method) {
            yield $method => ['method' => $method];
        }
    }

    #[Test]
    public function test_determinize_budget_is_enforced(): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(maxTransitionsProcessed: 0);

        try {
            $solver->intersection('/a/', '/a/', $options);
            $this->fail('Expected a ComplexityException to be thrown.');
        } catch (ComplexityException $exception) {
            $diagnostic = $exception->getDiagnostic();
            $this->assertNotNull($diagnostic);
            $this->assertSame('determinize', $diagnostic['phase'] ?? null);
            $this->assertSame(0, $diagnostic['limit'] ?? null);
        }
    }

    /**
     * Each state of the DFA writes a transition per range of the alphabet,
     * and \pL splits the Unicode alphabet into hundreds of ranges: the
     * table, not the moves of the NFA, is where the work and the memory go.
     */
    #[Test]
    #[DataProvider('provideDeterminizationAlgorithms')]
    public function test_determinize_budget_counts_every_transition_it_writes(DeterminizationAlgorithm $algorithm): void
    {
        $pattern = '/\pL\pL\pL/u';
        $dfa = (new LanguageSolver())->compile($pattern, new SolverOptions(minimizeDfa: false, maxTransitionsProcessed: null, determinizationAlgorithm: $algorithm));
        $written = 0;
        foreach ($dfa->states as $state) {
            $written += $state->rangeCount();
        }
        $this->assertGreaterThan(1_000, $written);

        try {
            (new LanguageSolver())->compile($pattern, new SolverOptions(minimizeDfa: false, maxTransitionsProcessed: $written - 1, determinizationAlgorithm: $algorithm));
            $this->fail('Expected a ComplexityException to be thrown.');
        } catch (ComplexityException $exception) {
            $diagnostic = $exception->getDiagnostic();
            $this->assertNotNull($diagnostic);
            $this->assertSame('determinize', $diagnostic['phase'] ?? null);
            $this->assertSame($written - 1, $diagnostic['limit'] ?? null);
        }
    }

    /**
     * @return iterable<string, array{algorithm: DeterminizationAlgorithm}>
     */
    public static function provideDeterminizationAlgorithms(): iterable
    {
        foreach (DeterminizationAlgorithm::cases() as $algorithm) {
            yield $algorithm->name => ['algorithm' => $algorithm];
        }
    }

    /**
     * Under the default budget, a large Unicode pattern is refused while
     * its DFA is built: the full table, over a thousand states by some two
     * thousand ranges, is never held to be minimized afterwards.
     */
    #[Test]
    public function test_determinize_budget_stops_a_large_alphabet_before_its_table_is_built(): void
    {
        /** @var string $pattern */
        $pattern = require \dirname(__DIR__, 2).'/Fixtures/Automata/url_validator_pattern.php';

        try {
            (new LanguageSolver())->compile($pattern);
            $this->fail('Expected a ComplexityException to be thrown.');
        } catch (ComplexityException $exception) {
            $diagnostic = $exception->getDiagnostic();
            $this->assertNotNull($diagnostic);
            $this->assertSame('determinize', $diagnostic['phase'] ?? null);
            $this->assertSame(1_000_000, $diagnostic['limit'] ?? null);
        }
    }

    #[Test]
    public function test_minimize_budget_is_enforced(): void
    {
        $options = new SolverOptions(maxTransitionsProcessed: 0);
        $minimizer = new DfaMinimizer();

        $states = [
            0 => new DfaState(0, [97 => 1], false, [97, 97, 1]),
            1 => new DfaState(1, [97 => 1], true, [97, 97, 1]),
        ];
        $dfa = new Dfa(0, $states, [[97, 97]], 97, 97);

        try {
            $minimizer->minimize($dfa, $options);
            $this->fail('Expected a ComplexityException to be thrown.');
        } catch (ComplexityException $exception) {
            $diagnostic = $exception->getDiagnostic();
            $this->assertNotNull($diagnostic);
            $this->assertSame('minimize', $diagnostic['phase'] ?? null);
            $this->assertSame(0, $diagnostic['limit'] ?? null);
        }
    }
}

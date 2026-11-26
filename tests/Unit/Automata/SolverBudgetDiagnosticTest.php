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
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Options\SolverStrategy;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A solver that gave up on a budget says so in its diagnostic, with the
 * limit it reached: the caller can tell "too costly to answer" from "outside
 * what the automata read", which carries no limit. isLimitReached(), $phase
 * and $limit say it typed.
 */
final class SolverBudgetDiagnosticTest extends TestCase
{
    /**
     * @param \Closure(LanguageSolver): mixed $question
     */
    #[Test]
    #[DataProvider('provideBudgets')]
    public function test_a_budget_reached_names_its_limit(\Closure $question, string $phase, int $limit): void
    {
        try {
            $question(new LanguageSolver());
            $this->fail('The budget was not reached.');
        } catch (ComplexityException $e) {
            $this->assertSame($limit, $e->getDiagnostic()['limit'] ?? null, $e->getMessage());
            $this->assertSame($phase, $e->getDiagnostic()['phase'] ?? null, $e->getMessage());

            $this->assertTrue($e->isLimitReached(), $e->getMessage());
            $this->assertSame($limit, $e->limit, $e->getMessage());
            $this->assertSame($phase, $e->phase, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{question: \Closure(LanguageSolver): mixed, phase: string, limit: int}>
     */
    public static function provideBudgets(): iterable
    {
        yield 'DFA states' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->equivalent('/^(a|b)*a(a|b){6}$/', '/^[ab]*a[ab]{6}$/', new SolverOptions(maxDfaStates: 20, determinizationAlgorithm: DeterminizationAlgorithm::SubsetIndexed)),
            'phase' => 'determinization',
            'limit' => 20,
        ];
        yield 'DFA states, plain subset construction' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->equivalent('/^(a|b)*a(a|b){6}$/', '/^[ab]*a[ab]{6}$/', new SolverOptions(maxDfaStates: 20, determinizationAlgorithm: DeterminizationAlgorithm::Subset)),
            'phase' => 'determinization',
            'limit' => 20,
        ];
        yield 'NFA states' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->equivalent('/a{50}/', '/a{50}/', new SolverOptions(maxNfaStates: 5)),
            'phase' => 'nfa construction',
            'limit' => 5,
        ];
        yield 'match configurations' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->matchEquivalent('/(a|b)+c/', '/(?:a|b)+c/', new SolverOptions(maxDfaStates: 2)),
            'phase' => 'match exploration',
            'limit' => 2,
        ];
        yield 'match states' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->matchEquivalent('/a{50}/', '/a{50}/', new SolverOptions(maxNfaStates: 5)),
            'phase' => 'match automaton',
            'limit' => 5,
        ];
        yield 'transitions processed' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->equivalent('/^(a|b)*a(a|b){6}$/', '/^[ab]*a[ab]{6}$/', new SolverOptions(maxTransitionsProcessed: 40, strategy: SolverStrategy::Eager)),
            'phase' => 'determinize',
            'limit' => 40,
        ];
        // Two cycles of 32 and 3,125 states pair every state of the one with
        // every state of the other: one pair past the 100,000 a product holds.
        yield 'product states' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->language('/^(?:a{32})*$/', new SolverOptions(maxNfaStates: 20_000))->intersect($solver->language('/^(?:a{3125})*$/', new SolverOptions(maxNfaStates: 20_000))),
            'phase' => 'product',
            'limit' => 100_000,
        ];
        // From "b" to 400 "a", every length between holds no string: the
        // search for the second string runs past a million steps.
        yield 'enumeration work' => [
            'question' => static function (LanguageSolver $solver): mixed {
                $strings = $solver->language('/^(?:b|a{400})$/')->strings();
                $strings->current();
                $strings->next();

                return $strings->current();
            },
            'phase' => 'enumeration',
            'limit' => 1_000_000,
        ];
        // The first string is sixteen "z"; before it, the search walks every
        // string of sixteen "a" and "b", which the language holds none of,
        // past the 50,000 states and lengths it remembers.
        yield 'enumeration memory' => [
            'question' => static function (LanguageSolver $solver): mixed {
                $options = new SolverOptions(maxDfaStates: 100_000, maxNfaStates: 100_000, maxTransitionsProcessed: 100_000_000);
                $language = $solver->language('/^[ab]{17,}$/', $options)
                    ->intersect($solver->language('/^[ab]*a[ab]{13}$/', $options))
                    ->union($solver->language('/^z{16}$/', $options));

                return $language->strings()->current();
            },
            'phase' => 'enumeration',
            'limit' => 50_000,
        ];
        // The lookbehind sends the check to the in-place proof, whose
        // alignment pairs some twelve hundred parts on each side.
        yield 'rewrite alignment' => [
            'question' => static function (LanguageSolver $solver): mixed {
                $original = '/(?<!x)'.str_repeat('\\d', 4).str_repeat('b?c?', 600).str_repeat('[e-g]', 4).'/';
                $rewrite = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($original, new OptimizerOptions(verifyWithAutomata: false))->optimized;

                return $solver->rewritesAlikeInPlace($original, $rewrite, new SolverOptions(maxDfaStates: 1_000, maxTransitionsProcessed: 200_000));
            },
            'phase' => 'rewrite alignment',
            'limit' => 65_536,
        ];
    }

    #[Test]
    public function test_a_construct_the_automata_do_not_read_names_no_limit(): void
    {
        try {
            (new LanguageSolver())->matchEquivalent('/(a)\1/', '/(a)a/');
            $this->fail('A backreference was read.');
        } catch (ComplexityException $e) {
            $this->assertArrayNotHasKey('limit', $e->getDiagnostic() ?? []);
            $this->assertFalse($e->isLimitReached());
            $this->assertNull($e->limit);
            $this->assertNull($e->phase);
        }
    }

    /**
     * The typed fields read the diagnostic: a phase without a limit is no
     * limit reached, a limit of 0 is one, and a value of the other type is
     * none.
     *
     * @param array<string, int|string>|null $diagnostic
     */
    #[Test]
    #[DataProvider('provideDiagnostics')]
    public function test_the_typed_fields_read_the_diagnostic(?array $diagnostic, ?string $phase, ?int $limit): void
    {
        $e = new ComplexityException('A message.', diagnostic: $diagnostic);

        $this->assertSame($phase, $e->phase);
        $this->assertSame($limit, $e->limit);
        $this->assertSame(null !== $limit, $e->isLimitReached());
        $this->assertSame($diagnostic, $e->getDiagnostic(), 'The diagnostic is what was given.');
    }

    /**
     * @return iterable<string, array{diagnostic: array<string, int|string>|null, phase: string|null, limit: int|null}>
     */
    public static function provideDiagnostics(): iterable
    {
        yield 'none' => ['diagnostic' => null, 'phase' => null, 'limit' => null];
        yield 'empty' => ['diagnostic' => [], 'phase' => null, 'limit' => null];
        yield 'a phase alone' => ['diagnostic' => ['phase' => 'comparison'], 'phase' => 'comparison', 'limit' => null];
        yield 'a phase and a limit' => ['diagnostic' => ['phase' => 'product', 'limit' => 100_000], 'phase' => 'product', 'limit' => 100_000];
        yield 'a limit of 0' => ['diagnostic' => ['limit' => 0], 'phase' => null, 'limit' => 0];
        yield 'values of the other type' => ['diagnostic' => ['phase' => 3, 'limit' => '40'], 'phase' => null, 'limit' => null];
    }
}

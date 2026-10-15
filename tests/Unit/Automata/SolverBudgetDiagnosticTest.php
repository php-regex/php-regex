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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A solver that gave up on a budget says so in its diagnostic, with the
 * limit it reached: the caller can tell "too costly to answer" from "outside
 * what the automata read", which carries no limit.
 */
final class SolverBudgetDiagnosticTest extends TestCase
{
    /**
     * @param \Closure(LanguageSolver): mixed $question
     */
    #[Test]
    #[DataProvider('provideBudgets')]
    public function test_a_budget_reached_names_its_limit(\Closure $question, int $limit): void
    {
        try {
            $question(new LanguageSolver());
            $this->fail('The budget was not reached.');
        } catch (ComplexityException $e) {
            $this->assertSame($limit, $e->getDiagnostic()['limit'] ?? null, $e->getMessage());
            $this->assertIsString($e->getDiagnostic()['phase'] ?? null, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{question: \Closure(LanguageSolver): mixed, limit: int}>
     */
    public static function provideBudgets(): iterable
    {
        yield 'DFA states' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->equivalent('/^(a|b)*a(a|b){6}$/', '/^[ab]*a[ab]{6}$/', new SolverOptions(maxDfaStates: 20, determinizationAlgorithm: DeterminizationAlgorithm::SubsetIndexed)),
            'limit' => 20,
        ];
        yield 'DFA states, plain subset construction' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->equivalent('/^(a|b)*a(a|b){6}$/', '/^[ab]*a[ab]{6}$/', new SolverOptions(maxDfaStates: 20, determinizationAlgorithm: DeterminizationAlgorithm::Subset)),
            'limit' => 20,
        ];
        yield 'NFA states' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->equivalent('/a{50}/', '/a{50}/', new SolverOptions(maxNfaStates: 5)),
            'limit' => 5,
        ];
        yield 'match configurations' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->matchEquivalent('/(a|b)+c/', '/(?:a|b)+c/', new SolverOptions(maxDfaStates: 2)),
            'limit' => 2,
        ];
        yield 'match states' => [
            'question' => static fn (LanguageSolver $solver): mixed => $solver->matchEquivalent('/a{50}/', '/a{50}/', new SolverOptions(maxNfaStates: 5)),
            'limit' => 5,
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
        }
    }
}

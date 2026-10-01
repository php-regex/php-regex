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

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Minimization\DfaMinimizer;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkBudgetTest extends TestCase
{
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

    #[Test]
    public function test_minimize_budget_is_enforced(): void
    {
        $options = new SolverOptions(maxTransitionsProcessed: 0);
        $minimizer = new DfaMinimizer();

        $states = [
            0 => new DfaState(0, [97 => 1], false, [[97, 97, 1]]),
            1 => new DfaState(1, [97 => 1], true, [[97, 97, 1]]),
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

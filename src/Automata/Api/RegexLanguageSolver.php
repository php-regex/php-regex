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

namespace RegexParser\Automata\Api;

use RegexParser\Automata\Builder\DfaBuilder;
use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Automata\Solver\DfaCacheInterface;
use RegexParser\Automata\Solver\EquivalenceResult;
use RegexParser\Automata\Solver\IntersectionResult;
use RegexParser\Automata\Solver\RegexSolver;
use RegexParser\Automata\Solver\RegexSolverCompilerInterface;
use RegexParser\Automata\Solver\RegexSolverInterface;
use RegexParser\Automata\Solver\SubsetResult;
use RegexParser\Automata\Transform\RegularSubsetValidator;
use RegexParser\Exception\ComplexityException;
use RegexParser\RegexParser;

/**
 * Stable facade for language-level regex comparisons.
 */
final readonly class RegexLanguageSolver
{
    public function __construct(private RegexSolverInterface $solver = new RegexSolver()) {}

    public static function forRegex(
        RegexParser $regex,
        ?RegularSubsetValidator $validator = null,
        ?DfaBuilder $dfaBuilder = null,
        ?DfaCacheInterface $dfaCache = null,
    ): self {
        return new self(new RegexSolver($regex, $validator, $dfaBuilder, $dfaCache));
    }

    /**
     * Compile and cache a DFA for the provided pattern.
     *
     * @throws ComplexityException
     */
    public function prepare(string $pattern, ?SolverOptions $options = null): void
    {
        if ($this->solver instanceof RegexSolverCompilerInterface) {
            $this->solver->compile($pattern, $options);

            return;
        }

        $this->solver->intersection($pattern, $pattern, $options);
    }

    /**
     * @throws ComplexityException
     */
    public function intersectionEmpty(string $patternA, string $patternB, ?SolverOptions $options = null): IntersectionResult
    {
        return $this->solver->intersection($patternA, $patternB, $options);
    }

    /**
     * @throws ComplexityException
     */
    public function subsetOf(string $patternA, string $patternB, ?SolverOptions $options = null): SubsetResult
    {
        return $this->solver->subsetOf($patternA, $patternB, $options);
    }

    /**
     * @throws ComplexityException
     */
    public function equivalent(string $patternA, string $patternB, ?SolverOptions $options = null): EquivalenceResult
    {
        return $this->solver->equivalent($patternA, $patternB, $options);
    }
}

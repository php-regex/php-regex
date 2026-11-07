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

namespace PHPRegex\Infection\Judge;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;

/**
 * Reads the ReDoS verdict the one mutant dropped without being proven alike
 * rests on: a mutant whose backtracking grows exponentially where the
 * original's does not. Infection would wait out its timeout on it
 * and count it detected, though no test caught anything. A polynomial rating,
 * high included, drops nothing: on the short subjects a test passes, such a
 * mutant answers at once. Each analysis takes a unit of the budget and runs
 * under the analyzer's own caps.
 *
 * @internal
 */
final class RedosGuard
{
    public function __construct(private readonly RedosAnalyzer $analyzer = new RedosAnalyzer()) {}

    /**
     * Whether the pattern backtracks exponentially; null with no unit left or
     * no verdict, which never drops a mutant. An analysis that could not
     * finish, a pattern it could not read or a limit it reached, is no
     * verdict: read as "not catastrophic" for the original, it would drop
     * a mutant no worse than an original nobody judged.
     */
    public function catastrophic(string $pattern, Budget $budget): ?bool
    {
        if (!$budget->spend($pattern)) {
            return null;
        }

        // The analysis reports its own failures, a pattern it cannot read
        // among them, in the verdict rather than by throwing.
        $analysis = $this->analyzer->analyze($pattern);
        if (null !== $analysis->error || RedosComplexity::Unknown === $analysis->complexity) {
            return null;
        }

        return RedosComplexity::Exponential === $analysis->complexity;
    }
}

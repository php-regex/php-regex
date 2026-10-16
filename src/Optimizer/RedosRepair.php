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

namespace PHPRegex\Optimizer;

use PHPRegex\Redos\RedosComplexity;

/**
 * A rewrite of a pattern open to catastrophic backtracking, with what was
 * proven about it.
 */
final class RedosRepair
{
    /**
     * @internal built by RedosRepairer::repair()
     *
     * @param bool|null $sameSubjects whether the automata prove it matches the subjects the pattern matches; null when they cannot judge
     * @param bool|null $sameMatches  whether preg_match() also writes the same $matches; null when the match solver cannot judge
     */
    public function __construct(
        public readonly string $pattern,
        public readonly ?bool $sameSubjects,
        public readonly ?bool $sameMatches,
        public readonly RedosComplexity $complexity,
    ) {}

    /**
     * Whether both proofs hold: the same subjects, and attempts proven
     * linear.
     */
    public function isCertified(): bool
    {
        return true === $this->sameSubjects && RedosComplexity::Linear === $this->complexity;
    }
}

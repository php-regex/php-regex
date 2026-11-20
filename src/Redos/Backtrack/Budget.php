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

namespace PHPRegex\Redos\Backtrack;

use PHPRegex\Redos\Exception\ModelLimitException;

/**
 * The analysis budget, counted in steps and states, never in time: the same
 * pattern stops at the same point on every machine. A budget may draw its
 * steps from a pool too, which several proofs share: a step past the pool
 * stops the proof as a step past its own does.
 *
 * @internal
 */
final class Budget
{
    private int $steps = 0;

    private int $states = 0;

    public function __construct(
        private readonly int $maxSteps,
        private readonly int $maxStates,
        private readonly ?self $pool = null
    ) {}

    /**
     * @throws ModelLimitException
     */
    public function step(int $count = 1): void
    {
        $this->steps += $count;
        if ($this->steps > $this->maxSteps) {
            throw ModelLimitException::budget('Steps');
        }

        $this->pool?->step($count);
    }

    /**
     * @throws ModelLimitException
     */
    public function state(): void
    {
        $this->states++;
        if ($this->states > $this->maxStates) {
            throw ModelLimitException::budget('States');
        }

        $this->step();
    }
}

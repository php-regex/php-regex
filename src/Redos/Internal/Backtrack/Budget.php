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

namespace PHPRegex\Redos\Internal\Backtrack;

/**
 * The analysis budget, counted in steps and states, never in time: the same
 * pattern stops at the same point on every machine.
 *
 * @internal
 */
final class Budget
{
    private int $steps = 0;

    private int $states = 0;

    public function __construct(private readonly int $maxSteps, private readonly int $maxStates) {}

    /**
     * @throws ModelLimit
     */
    public function step(int $count = 1): void
    {
        $this->steps += $count;
        if ($this->steps > $this->maxSteps) {
            throw ModelLimit::budget('Steps');
        }
    }

    /**
     * @throws ModelLimit
     */
    public function state(): void
    {
        $this->states++;
        if ($this->states > $this->maxStates) {
            throw ModelLimit::budget('States');
        }

        $this->step();
    }
}

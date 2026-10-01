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

namespace PHPRegex\Redos;

/**
 * The budget of the backtracking model, counted in states and steps, never
 * in time: the same pattern gets the same verdict on every machine.
 *
 * @api
 */
final readonly class RedosOptions
{
    /**
     * @param int $maxStates           the states the pattern's automata may hold
     * @param int $maxSteps            the states created and product pairs visited the analysis may take
     * @param int $boundedRepeatCutoff the largest maximum of a bounded repeat unrolled; past it, {m,n} is analysed as {m,}
     */
    public function __construct(
        public int $maxStates = 2000,
        public int $maxSteps = 250_000,
        public int $boundedRepeatCutoff = 16,
    ) {}
}

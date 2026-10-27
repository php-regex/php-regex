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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;

/**
 * The budget of the backtracking model, counted in states and steps, never
 * in time: the same pattern gets the same verdict on every machine.
 *
 * @api
 */
final class RedosOptions
{
    /**
     * @param int $maxStates           the states the pattern's automata may hold
     * @param int $maxSteps            the states created and product pairs visited the analysis may take
     * @param int $boundedRepeatCutoff the largest maximum of a bounded repeat unrolled; past it, {m,n} is analysed as {m,}, an exact count {n} kept
     *
     * @throws InvalidRegexOptionException when a state or step cap is below 1, or the cutoff below 0
     */
    public function __construct(
        public readonly int $maxStates = 2000,
        public readonly int $maxSteps = 250_000,
        public readonly int $boundedRepeatCutoff = 16,
    ) {
        foreach (['maxStates' => [$maxStates, 1], 'maxSteps' => [$maxSteps, 1], 'boundedRepeatCutoff' => [$boundedRepeatCutoff, 0]] as $name => [$value, $floor]) {
            if ($value < $floor) {
                throw new InvalidRegexOptionException(\sprintf('"%s" must be at least %d, %d given.', $name, $floor, $value));
            }
        }
    }
}

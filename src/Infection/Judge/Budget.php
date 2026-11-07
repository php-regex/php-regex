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

/**
 * The work one mutator may spend on one pattern, counted in checks: a
 * question to the automata, a ReDoS analysis, a draw of samples. Each check
 * runs under its own cap of states and transitions, so the units bound the
 * work without a clock, and the same pattern gets the same mutants on every
 * machine. Once spent, the remaining mutants are emitted unproven and
 * without a kill string.
 *
 * @internal
 */
final class Budget
{
    /**
     * The units a pattern gets from one mutator.
     */
    public const UNITS = 96;

    /**
     * The bytes of pattern a unit pays for: a question about longer patterns
     * builds more states before it answers or reaches its cap, a second or
     * two for a few kilobytes, so it takes more units.
     */
    public const BYTES_PER_UNIT = 256;

    public function __construct(private int $units = self::UNITS) {}

    /**
     * Takes the units a check of the patterns costs, one and one more per
     * BYTES_PER_UNIT of them; false when they are not left, and from then on
     * no check is paid for.
     *
     * @phpstan-impure
     */
    public function spend(string ...$patterns): bool
    {
        $cost = 1 + intdiv(array_sum(array_map(strlen(...), $patterns)), self::BYTES_PER_UNIT);
        if ($this->units < $cost) {
            $this->units = 0;

            return false;
        }

        $this->units -= $cost;

        return true;
    }

    /**
     * Whether no check is paid for any more.
     */
    public function spent(): bool
    {
        return $this->units <= 0;
    }
}

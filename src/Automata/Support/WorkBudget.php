<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Automata\Support;

use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Parser\ErrorCode;

/**
 * Tracks automata work and enforces a hard budget.
 *
 * @internal
 */
final class WorkBudget
{
    private int $consumed = 0;

    public function __construct(
        private readonly ?int $limit,
        private readonly string $phase,
        private int $states,
        private int $transitions,
        private int $alphabetSize,
    ) {}

    public function updateStats(int $states, int $transitions, int $alphabetSize): void
    {
        $this->states = $states;
        $this->transitions = $transitions;
        $this->alphabetSize = $alphabetSize;
    }

    public function consume(int $units = 1): void
    {
        if (null === $this->limit) {
            return;
        }

        $this->consumed += $units;

        if ($this->consumed <= $this->limit) {
            return;
        }

        throw new ComplexityException(
            \sprintf(
                'Automata work budget exceeded during %s (limit %d, consumed %d).',
                $this->phase,
                $this->limit,
                $this->consumed,
            ),
            null,
            null,
            null,
            ErrorCode::Complexity,
            [
                'phase' => $this->phase,
                'states' => $this->states,
                'transitions' => $this->transitions,
                'alphabet' => $this->alphabetSize,
                'consumed' => $this->consumed,
                'limit' => $this->limit,
            ],
        );
    }
}

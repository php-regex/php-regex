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

namespace PhpRegex\Automata\Model;

/**
 * Immutable DFA state, as `Dfa` hands it out: read, never built by a caller.
 */
final readonly class DfaState
{
    /**
     * @param array<int, int>                        $transitions
     * @param array<int, array{0:int, 1:int, 2:int}> $ranges
     */
    public function __construct(
        public int $id,
        public array $transitions,
        public bool $isAccepting,
        public array $ranges = [],
    ) {}

    public function transitionFor(int $codePoint): ?int
    {
        if (isset($this->transitions[$codePoint])) {
            return $this->transitions[$codePoint];
        }

        $low = 0;
        $high = count($this->ranges) - 1;

        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            [$start, $end, $target] = $this->ranges[$mid];

            if ($codePoint < $start) {
                $high = $mid - 1;
            } elseif ($codePoint > $end) {
                $low = $mid + 1;
            } else {
                return $target;
            }
        }

        return null;
    }
}

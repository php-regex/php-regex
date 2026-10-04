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

namespace PHPRegex\Automata\Match;

use PHPRegex\Parser\Hir\CharSet;

/**
 * A Thompson NFA that keeps the order PCRE tries its paths in: the targets of
 * a split are listed from the most preferred to the least, as a backtracking
 * matcher would take them. Capture boundaries are tags on the way.
 *
 * Slot 2k opens group k and slot 2k+1 closes it; slots 0 and 1 hold the
 * whole match, set by whoever runs the automaton.
 *
 * @internal
 */
final class PriorityNfa
{
    public const CHAR = 0;

    public const SPLIT = 1;

    public const TAG = 2;

    public const MATCH = 3;

    /**
     * The start of the subject: "^" without /m, "\A".
     */
    public const START = 4;

    /**
     * The end of the subject: "\z", "$" under /D.
     */
    public const END = 5;

    /**
     * The end of the subject or before a newline that ends it: "$", "\Z".
     */
    public const END_OR_FINAL_NEWLINE = 6;

    /**
     * @var list<int>
     */
    public array $kinds = [];

    /**
     * @var array<int, int>
     */
    public array $next = [];

    /**
     * @var array<int, list<int>>
     */
    public array $targets = [];

    /**
     * @var array<int, CharSet>
     */
    public array $sets = [];

    /**
     * @var array<int, int>
     */
    public array $slots = [];

    public int $start = 0;

    /**
     * @param int                $groupCount the number of capturing groups
     * @param array<int, string> $names      group number => name
     */
    public function __construct(
        public readonly bool $unicode,
        public int $groupCount = 0,
        public array $names = [],
    ) {}

    public function add(int $kind): int
    {
        $this->kinds[] = $kind;

        return \count($this->kinds) - 1;
    }

    /**
     * Everything the automaton does, positions in the pattern aside: two
     * automata with the same fingerprint run alike on every subject.
     */
    public function fingerprint(): string
    {
        $sets = array_map(static fn (CharSet $set): array => $set->ranges, $this->sets);
        ksort($sets);
        ksort($this->next);
        ksort($this->targets);
        ksort($this->slots);

        return serialize([$this->unicode, $this->groupCount, $this->names, $this->start, $this->kinds, $this->next, $this->targets, $this->slots, $sets]);
    }

    /**
     * Whether preg_match() writes $matches of the same keys for both: as many
     * groups, named alike.
     */
    public function hasTheShapeOf(self $other): bool
    {
        return $this->groupCount === $other->groupCount && $this->names === $other->names;
    }
}

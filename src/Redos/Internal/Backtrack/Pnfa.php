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

use PHPRegex\Parser\Node\NodeInterface;

/**
 * A prioritized NFA: the states of one search, their epsilon moves ordered
 * the way PCRE tries them. A state either reads one character of a set,
 * checks the next character without reading it, or moves on without
 * reading.
 *
 * @internal
 */
final class Pnfa
{
    /**
     * Moves to its targets, first one first.
     */
    public const EPSILON = 0;

    /**
     * Reads one character of its set.
     */
    public const CHAR = 1;

    /**
     * Goes on only when the next character is in its set, or when the
     * subject ends there and the end is allowed.
     */
    public const PEEK = 2;

    /**
     * Goes on only before the first character of the attempt.
     */
    public const START = 3;

    /**
     * Enters an iteration of an unbounded loop.
     */
    public const ENTER = 4;

    /**
     * Leaves an iteration: back to the loop, or out of it when the
     * iteration read nothing.
     */
    public const LEAVE = 5;

    /**
     * Passes the place of a sub-search (a lookaround), without a constraint.
     */
    public const MARK = 6;

    /**
     * The search succeeds.
     */
    public const FINAL = 7;

    /**
     * A word boundary, "\b", or its negation, "\B": decided from the word
     * class of the character read last and of the next one.
     */
    public const BOUNDARY = 8;

    /**
     * A start of line under /m: holds before the first character; later the
     * model does not decide it, and a success after it is not counted.
     */
    public const LINE_START = 9;

    /**
     * "\G": goes on only where an attempt starts.
     */
    public const CONTINUATION = 10;

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
     * @var array<int, bool>
     */
    public array $ends = [];

    /**
     * @var array<int, int>
     */
    public array $loops = [];

    /**
     * @var array<int, int>
     */
    public array $exits = [];

    /**
     * @var array<int, int>
     */
    public array $marks = [];

    /**
     * @var array<int, bool> whether a boundary state is "\B"
     */
    public array $negated = [];

    /**
     * @var array<int, true> the reading states inside an atomic body kept
     *                       with every way through it
     */
    public array $approximated = [];

    /**
     * The characters "\b" counts as word characters, set when the search
     * holds a boundary.
     */
    public ?CharSet $wordSet = null;

    /**
     * @var array<int, true> the loops the model made of bounded repeats
     */
    public array $abstractedLoops = [];

    public int $start = 0;

    public int $final;

    /**
     * @var array<int, int> where the pattern writes what a reading state reads
     */
    public array $offsets = [];

    /**
     * Whether the search holds "^" under /m.
     */
    public bool $hasLineStart = false;

    /**
     * Whether the search looks at the character before an attempt's first
     * one: "^", "\A", "\G", "^" under /m, "\b" or "\B".
     */
    public bool $looksBehindStart = false;

    /**
     * @param int|null $parent the search this one is a lookaround of, and the
     *                         mark where it stands there
     */
    public function __construct(
        public readonly bool $unicode,
        private readonly Budget $budget,
        public readonly NodeInterface $body,
        public readonly ?int $parent = null,
        public readonly ?int $mark = null,
    ) {
        $this->final = $this->add(self::FINAL);
    }

    /**
     * @param list<int> $targets
     */
    public function epsilon(array $targets = []): int
    {
        $state = $this->add(self::EPSILON);
        $this->targets[$state] = $targets;

        return $state;
    }

    /**
     * @param list<int> $targets
     */
    public function setTargets(int $state, array $targets): void
    {
        $this->targets[$state] = $targets;
    }

    public function char(CharSet $set, int $next, int $offset = 0): int
    {
        $this->budget->state();
        $state = $this->add(self::CHAR);
        $this->sets[$state] = $set;
        $this->next[$state] = $next;
        $this->offsets[$state] = $offset;

        return $state;
    }

    public function peek(CharSet $set, bool $end, int $next): int
    {
        $state = $this->add(self::PEEK);
        $this->sets[$state] = $set;
        $this->ends[$state] = $end;
        $this->next[$state] = $next;

        return $state;
    }

    public function start(int $next): int
    {
        $this->looksBehindStart = true;
        $state = $this->add(self::START);
        $this->next[$state] = $next;

        return $state;
    }

    public function enter(int $loop, int $next): int
    {
        $state = $this->add(self::ENTER);
        $this->loops[$state] = $loop;
        $this->next[$state] = $next;

        return $state;
    }

    public function leave(int $loop, int $back, int $exit): int
    {
        $state = $this->add(self::LEAVE);
        $this->loops[$state] = $loop;
        $this->next[$state] = $back;
        $this->exits[$state] = $exit;

        return $state;
    }

    public function boundary(bool $negated, CharSet $wordSet, int $next): int
    {
        $this->wordSet = $wordSet;
        $this->looksBehindStart = true;
        $state = $this->add(self::BOUNDARY);
        $this->negated[$state] = $negated;
        $this->next[$state] = $next;

        return $state;
    }

    public function continuation(int $next): int
    {
        $this->looksBehindStart = true;
        $state = $this->add(self::CONTINUATION);
        $this->next[$state] = $next;

        return $state;
    }

    public function lineStart(int $next): int
    {
        $this->hasLineStart = true;
        $this->looksBehindStart = true;
        $state = $this->add(self::LINE_START);
        $this->next[$state] = $next;

        return $state;
    }

    public function mark(int $search, int $next): int
    {
        $state = $this->add(self::MARK);
        $this->marks[$state] = $search;
        $this->next[$state] = $next;

        return $state;
    }

    private function add(int $kind): int
    {
        $this->budget->step();
        $state = \count($this->kinds);
        $this->kinds[] = $kind;

        return $state;
    }
}

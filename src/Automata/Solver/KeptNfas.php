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

namespace PHPRegex\Automata\Solver;

use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Parser\Cache\StaticCaches;

/**
 * The NFAs the lazy search read, kept beside the DFA cache a solver was
 * given, for as long as that cache lives. Who brings a cache asks many
 * questions about the same patterns, a route against every other route:
 * the lazy search compiles no DFA to keep, and building the NFA of a
 * pattern again for each question costs more than the search.
 *
 * They are bounded as the DFAs of InMemoryDfaCache are: as many automata as
 * the library's other caches keep entries, the older half going when full,
 * and 50,000 states in all at most, the oldest automata going first; an
 * automaton larger than that alone is not kept. They empty with the other
 * caches (RegexParser::clearCaches()).
 *
 * @internal
 */
final class KeptNfas implements \Countable
{
    /**
     * The states held at most. A state of a route's pattern takes some 600
     * bytes: the automata kept stay under some 30 MB.
     */
    private const MAX_STATES = 50_000;

    /**
     * The automata kept beside each cache alive in the process.
     *
     * @var \WeakMap<DfaCacheInterface, self>|null
     */
    private static ?\WeakMap $beside = null;

    /**
     * The automata kept, oldest first, each with its states.
     *
     * @var array<string, array{0: Nfa, 1: int}>
     */
    private array $nfas = [];

    private function __construct() {}

    /**
     * The NFAs kept beside the cache, none until one is set.
     */
    public static function beside(DfaCacheInterface $cache): self
    {
        if (null === self::$beside) {
            self::$beside = new \WeakMap();
            StaticCaches::register(self::class, static function (): void {
                foreach (self::$beside ?? [] as $kept) {
                    $kept->nfas = [];
                }
            });
        }

        return self::$beside[$cache] ??= new self();
    }

    public function get(string $key): ?Nfa
    {
        return $this->nfas[$key][0] ?? null;
    }

    /**
     * How many automata are kept.
     */
    public function count(): int
    {
        return \count($this->nfas);
    }

    public function set(string $key, Nfa $nfa): void
    {
        // A key set again counts once, as the newest.
        unset($this->nfas[$key]);

        $size = \count($nfa->states);
        if ($size > self::MAX_STATES) {
            return;
        }

        $this->nfas = StaticCaches::makeRoom($this->nfas);
        $held = array_sum(array_column($this->nfas, 1));

        // The oldest go first, until the new one fits.
        while ($held + $size > self::MAX_STATES && null !== $oldest = array_key_first($this->nfas)) {
            $held -= $this->nfas[$oldest][1];
            unset($this->nfas[$oldest]);
        }

        $this->nfas[$key] = [$nfa, $size];
    }
}

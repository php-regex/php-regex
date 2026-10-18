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

use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Parser\Cache\StaticCaches;

/**
 * In-memory cache for DFAs. It keeps as many automata as the library's other
 * caches keep entries, the older half going when it is full, and 150,000
 * transitions in all at most, the oldest automata going first; an automaton
 * larger than that alone is not kept. It empties with the other caches
 * (RegexParser::clearCaches()).
 */
final class InMemoryDfaCache implements DfaCacheInterface
{
    /**
     * The transitions the cache holds at most, each byte of a table over
     * bytes and each range counted. One takes from about 40 bytes, a byte
     * of a table, to about 130, a range of a Unicode class: the automata
     * kept stay under some 20 MB in a process that lives long, as the
     * language server or a lint over many files, where a thousand of them
     * could take hundreds of megabytes.
     */
    private const MAX_TRANSITIONS = 150_000;

    /**
     * The caches alive in the process, for clearCaches() to reach.
     *
     * @var \WeakMap<self, true>|null
     */
    private static ?\WeakMap $live = null;

    /**
     * The automata kept, oldest first, each with its transitions.
     *
     * @var array<string, array{0: Dfa, 1: int}>
     */
    private array $cache = [];

    public function __construct()
    {
        if (null === self::$live) {
            self::$live = new \WeakMap();
            StaticCaches::register(self::class, static function (): void {
                foreach (self::$live ?? [] as $cache => $_) {
                    $cache->cache = [];
                }
            });
        }

        self::$live[$this] = true;
    }

    public function get(string $key): ?Dfa
    {
        return $this->cache[$key][0] ?? null;
    }

    public function set(string $key, Dfa $dfa): void
    {
        // A key set again counts once, as the newest.
        unset($this->cache[$key]);

        $size = 0;
        foreach ($dfa->states as $state) {
            $size += \count($state->byteTransitions()) + $state->rangeCount();
        }

        if ($size > self::MAX_TRANSITIONS) {
            return;
        }

        $this->cache = StaticCaches::makeRoom($this->cache);
        $held = array_sum(array_column($this->cache, 1));

        // The oldest go first, until the new one fits.
        while ($held + $size > self::MAX_TRANSITIONS && null !== $oldest = array_key_first($this->cache)) {
            $held -= $this->cache[$oldest][1];
            unset($this->cache[$oldest]);
        }

        $this->cache[$key] = [$dfa, $size];
    }
}

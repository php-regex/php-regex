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
use PHPRegex\Parser\Internal\StaticCaches;

/**
 * In-memory cache for DFAs. It keeps as many automata as the library's other
 * caches keep entries, the older half going when it is full, and empties
 * with them (RegexParser::clearCaches()).
 */
final class InMemoryDfaCache implements DfaCacheInterface
{
    /**
     * The caches alive in the process, for clearCaches() to reach.
     *
     * @var \WeakMap<self, true>|null
     */
    private static ?\WeakMap $live = null;

    /**
     * @var array<string, Dfa>
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
        return $this->cache[$key] ?? null;
    }

    public function set(string $key, Dfa $dfa): void
    {
        if (!isset($this->cache[$key])) {
            $this->cache = StaticCaches::makeRoom($this->cache);
        }

        $this->cache[$key] = $dfa;
    }
}

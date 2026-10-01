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

/**
 * Simple in-memory cache for DFAs.
 */
final class InMemoryDfaCache implements DfaCacheInterface
{
    /**
     * @var array<string, Dfa>
     */
    private array $cache = [];

    public function get(string $key): ?Dfa
    {
        return $this->cache[$key] ?? null;
    }

    public function set(string $key, Dfa $dfa): void
    {
        $this->cache[$key] = $dfa;
    }
}

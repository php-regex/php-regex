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

namespace PHPRegex\Parser\Cache;

/**
 * A cache that can be emptied and counts its hits and misses.
 *
 * @phpstan-type CacheStats array{hits: int, misses: int}
 */
interface RemovableCacheInterface extends CacheInterface
{
    /**
     * Removes every tree the cache holds. A tree is stored under a seed the
     * parser builds, not under its regex, so the cache is emptied whole.
     */
    public function clear(): void;

    /**
     * @return CacheStats
     */
    public function getStats(): array;
}

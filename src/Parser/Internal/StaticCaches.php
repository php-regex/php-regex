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

namespace PHPRegex\Parser\Internal;

/**
 * The library's process-wide caches, emptied in one call. A class keeping
 * one registers what empties it when it fills it, so the call reaches the
 * caches of every package without the parser knowing them.
 *
 * @internal
 */
final class StaticCaches
{
    /**
     * How many entries a cache keyed by what a pattern holds keeps; the older
     * half goes when it is full.
     */
    public const MAX_ENTRIES = 1000;

    /**
     * What empties each class's caches, by class: as many as there are
     * classes.
     *
     * @var array<string, \Closure(): void>
     */
    private static array $clearers = [];

    /**
     * @param \Closure(): void $clear
     */
    public static function register(string $owner, \Closure $clear): void
    {
        self::$clearers[$owner] ??= $clear;
    }

    public static function clear(): void
    {
        foreach (self::$clearers as $clear) {
            $clear();
        }
    }

    /**
     * The cache with room for one more entry: its older half dropped when
     * it is full.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $cache
     *
     * @return array<TKey, TValue>
     */
    public static function makeRoom(array $cache): array
    {
        if (\count($cache) < self::MAX_ENTRIES) {
            return $cache;
        }

        return \array_slice($cache, -(int) (self::MAX_ENTRIES / 2), null, true);
    }
}

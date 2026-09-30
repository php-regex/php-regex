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

namespace RegexParser\Tests\TestUtils;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A PSR-6 pool that keeps values serialized, as a real store does: what
 * comes back is a copy, never the object that went in.
 */
final class InMemoryCachePool implements CacheItemPoolInterface
{
    /**
     * @var array<string, string>
     */
    public array $stored = [];

    public function getItem(string $key): CacheItemInterface
    {
        return isset($this->stored[$key])
            ? new InMemoryCacheItem($key, unserialize($this->stored[$key]), true)
            : new InMemoryCacheItem($key, null, false);
    }

    /**
     * @param array<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return isset($this->stored[$key]);
    }

    public function clear(): bool
    {
        $this->stored = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->stored[$key]);

        return true;
    }

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->stored[$item->getKey()] = serialize($item->get());

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}

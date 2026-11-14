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

use PHPRegex\Parser\Node\RegexNode;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps trees in a PSR-6 pool, as serialized strings the adapter reads back
 * itself: the pool never has to rebuild an object. A load that gives a tree
 * back is a hit, any other a miss, counted for this adapter alone.
 */
final class PsrCacheAdapter implements RemovableCacheInterface
{
    private int $hits = 0;

    private int $misses = 0;

    /**
     * The keys this adapter wrote, the ones clear() removes.
     *
     * @var array<string, true>
     */
    private array $written = [];
    /**
     */
    private CacheItemPoolInterface $pool;
    /**
     */
    private string $prefix = 'regex_';
    /**
     * @var \Closure(string): mixed|null
     *
     */
    private ?\Closure $keyFactory = null;

    /**
     * @param (\Closure(string): mixed)|null $keyFactory receives the seed generateKey() gets and
     *                                                   returns the key body: a string is prefixed as is,
     *                                                   anything else is serialized and sha256-hashed
     */
    public function __construct(CacheItemPoolInterface $pool, string $prefix = 'regex_', ?\Closure $keyFactory = null)
    {
        $this->pool = $pool;
        $this->prefix = $prefix;
        $this->keyFactory = $keyFactory;
    }

    public function generateKey(string $seed): string
    {
        if (null !== $this->keyFactory) {
            $custom = ($this->keyFactory)($seed);

            return $this->prefix.(\is_string($custom) ? $custom : hash('sha256', serialize($custom)));
        }

        return $this->prefix.hash('sha256', $seed);
    }

    public function write(string $key, RegexNode $ast): void
    {
        $this->pool->save($this->pool->getItem($key)->set(AstSerializer::serialize($ast)));
        $this->written[$key] = true;
    }

    public function load(string $key): ?RegexNode
    {
        $item = $this->pool->getItem($key);
        $data = $item->isHit() ? $item->get() : null;
        $ast = \is_string($data) ? AstSerializer::unserialize($data) : null;

        null === $ast ? $this->misses++ : $this->hits++;

        return $ast;
    }

    /**
     * Removes the trees this adapter wrote, never another entry: the pool
     * may be the application's. A tree another process wrote stays, as
     * valid as it was; clear a pool of the library's own to drop them too.
     */
    public function clear(): void
    {
        if ([] !== $this->written) {
            $this->pool->deleteItems(array_keys($this->written));
        }

        $this->written = [];
    }

    public function getStats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }
}

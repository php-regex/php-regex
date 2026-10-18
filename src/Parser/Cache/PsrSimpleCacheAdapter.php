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
use Psr\SimpleCache\CacheInterface;

/**
 * Keeps trees in a PSR-16 cache, as serialized strings the adapter reads
 * back itself: the store never has to rebuild an object. A load that gives
 * a tree back is a hit, any other a miss, counted for this adapter alone.
 */
final class PsrSimpleCacheAdapter implements RemovableCacheInterface
{
    private int $hits = 0;

    private int $misses = 0;

    /**
     * @param (\Closure(string): mixed)|null $keyFactory receives the seed generateKey() gets and
     *                                                   returns the key body: a string is prefixed as is,
     *                                                   anything else is serialized and sha256-hashed
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'regex_',
        private readonly ?\Closure $keyFactory = null
    ) {}

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
        $this->cache->set($key, AstSerializer::serialize($ast));
    }

    public function load(string $key): ?RegexNode
    {
        $data = $this->cache->get($key);
        $ast = \is_string($data) ? AstSerializer::unserialize($data) : null;

        null === $ast ? $this->misses++ : $this->hits++;

        return $ast;
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    public function getStats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }
}

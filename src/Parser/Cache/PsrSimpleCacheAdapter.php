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
 * back itself: the store never has to rebuild an object.
 */
final readonly class PsrSimpleCacheAdapter implements RemovableCacheInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'regex_',
        private ?\Closure $keyFactory = null
    ) {}

    public function generateKey(string $regex): string
    {
        if (null !== $this->keyFactory) {
            $custom = ($this->keyFactory)($regex);

            return $this->prefix.(\is_string($custom) ? $custom : hash('sha256', serialize($custom)));
        }

        return $this->prefix.hash('sha256', $regex);
    }

    public function write(string $key, RegexNode $ast): void
    {
        $this->cache->set($key, AstSerializer::serialize($ast));
    }

    public function load(string $key): ?RegexNode
    {
        $data = $this->cache->get($key);

        return \is_string($data) ? AstSerializer::unserialize($data) : null;
    }

    public function clear(?string $regex = null): void
    {
        if (null !== $regex) {
            $this->cache->delete($this->generateKey($regex));

            return;
        }

        $this->cache->clear();
    }

    public function getStats(): array
    {
        return ['hits' => 0, 'misses' => 0];
    }
}

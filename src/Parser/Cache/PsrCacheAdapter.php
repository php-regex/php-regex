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

namespace PhpRegex\Parser\Cache;

use PhpRegex\Parser\Node\RegexNode;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps trees in a PSR-6 pool, as serialized strings the adapter reads back
 * itself: the pool never has to rebuild an object.
 */
final readonly class PsrCacheAdapter implements RemovableCacheInterface
{
    public function __construct(
        private CacheItemPoolInterface $pool,
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
        $this->pool->save($this->pool->getItem($key)->set(AstSerializer::serialize($ast)));
    }

    public function load(string $key): ?RegexNode
    {
        $item = $this->pool->getItem($key);
        $data = $item->isHit() ? $item->get() : null;

        return \is_string($data) ? AstSerializer::unserialize($data) : null;
    }

    public function clear(?string $regex = null): void
    {
        if (null !== $regex) {
            $this->pool->deleteItem($this->generateKey($regex));

            return;
        }

        $this->pool->clear();
    }

    public function getStats(): array
    {
        return ['hits' => 0, 'misses' => 0];
    }
}

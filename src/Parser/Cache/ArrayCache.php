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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Node\RegexNode;

/**
 * Trees kept in memory for the life of the process: past the given number,
 * the one read or written longest ago is dropped. Trees are immutable, so
 * the same instance is handed out on every hit.
 *
 * @phpstan-import-type CacheStats from RemovableCacheInterface
 */
final class ArrayCache implements RemovableCacheInterface
{
    private int $hits = 0;

    private int $misses = 0;

    /**
     * @var array<string, RegexNode>
     */
    private array $trees = [];

    /**
     * @throws InvalidRegexOptionException when the cache would keep no tree: NullCache is the cache of none
     */
    public function __construct(private readonly int $maxEntries = 1024)
    {
        if ($maxEntries < 1) {
            throw new InvalidRegexOptionException(\sprintf('An ArrayCache keeps at least 1 tree, %d given; NullCache keeps none.', $maxEntries));
        }
    }

    #[\Override]
    public function generateKey(string $seed): string
    {
        return hash('sha256', $seed);
    }

    #[\Override]
    public function write(string $key, RegexNode $ast): void
    {
        unset($this->trees[$key]);
        $this->trees[$key] = $ast;

        while (\count($this->trees) > $this->maxEntries) {
            unset($this->trees[array_key_first($this->trees)]);
        }
    }

    #[\Override]
    public function load(string $key): ?RegexNode
    {
        if (!isset($this->trees[$key])) {
            $this->misses++;

            return null;
        }

        $this->hits++;
        // Read again, it is the latest.
        $tree = $this->trees[$key];
        unset($this->trees[$key]);

        return $this->trees[$key] = $tree;
    }

    #[\Override]
    public function clear(): void
    {
        $this->trees = [];
    }

    /**
     * @return CacheStats
     */
    #[\Override]
    public function getStats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Cache;

use PhpRegex\Parser\Node\RegexNode;

/**
 * Trees kept in memory for the life of the process, the latest ones first:
 * past the given number, the oldest is dropped. Trees are immutable, so the
 * same instance is handed out on every hit.
 */
final class ArrayCache implements RemovableCacheInterface
{
    private int $hits = 0;

    private int $misses = 0;

    /**
     * @var array<string, \PhpRegex\Parser\Node\RegexNode>
     */
    private array $trees = [];

    public function __construct(private readonly int $maxEntries = 1024) {}

    #[\Override]
    public function generateKey(string $regex): string
    {
        return hash('sha256', $regex);
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

        return $this->trees[$key];
    }

    #[\Override]
    public function clear(?string $regex = null): void
    {
        if (null !== $regex) {
            unset($this->trees[$this->generateKey($regex)]);

            return;
        }

        $this->trees = [];
    }

    /**
     * @return array{hits: int, misses: int}
     */
    #[\Override]
    public function getStats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }
}

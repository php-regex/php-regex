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

namespace RegexParser\Cache;

use RegexParser\Node\RegexNode;

final readonly class NullCache implements RemovableCacheInterface
{
    #[\Override]
    public function generateKey(string $regex): string
    {
        return hash('sha256', $regex);
    }

    #[\Override]
    public function write(string $key, RegexNode $ast): void {}

    #[\Override]
    public function load(string $key): ?RegexNode
    {
        return null;
    }

    #[\Override]
    public function clear(?string $regex = null): void {}

    #[\Override]
    public function getStats(): array
    {
        return ['hits' => 0, 'misses' => 0];
    }
}

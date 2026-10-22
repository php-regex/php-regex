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

final class NullCache implements RemovableCacheInterface
{
    #[\Override]
    public function generateKey(string $seed): string
    {
        return hash('sha256', $seed);
    }

    #[\Override]
    public function write(string $key, RegexNode $ast): void {}

    #[\Override]
    public function load(string $key): ?RegexNode
    {
        return null;
    }

    #[\Override]
    public function clear(): void {}

    #[\Override]
    public function getStats(): array
    {
        return ['hits' => 0, 'misses' => 0];
    }
}

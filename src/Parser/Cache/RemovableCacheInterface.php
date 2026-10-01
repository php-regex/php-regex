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

interface RemovableCacheInterface extends CacheInterface
{
    public function clear(?string $regex = null): void;

    /**
     * @return array{hits: int, misses: int}
     */
    public function getStats(): array;
}

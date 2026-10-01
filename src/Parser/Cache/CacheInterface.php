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

/**
 * Where parsed trees are kept between calls. Each implementation encodes
 * the tree its own way; none stores or runs code.
 */
interface CacheInterface
{
    public function generateKey(string $regex): string;

    public function write(string $key, RegexNode $ast): void;

    /**
     * The tree stored under the key, or null on a miss or on a value that
     * is not a tree.
     */
    public function load(string $key): ?RegexNode;
}

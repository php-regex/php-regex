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

namespace PhpRegex\Tests\Unit\Cache;

use PhpRegex\Parser\Cache\NullCache;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NullCacheTest extends TestCase
{
    #[Test]
    public function test_null_cache_no_ops(): void
    {
        $cache = new NullCache();

        $key = $cache->generateKey('/foo/');
        $this->assertSame(hash('sha256', '/foo/'), $key);

        $cache->write($key, Regex::create(['cache' => null])->parse('/foo/')); // should not throw
        $this->assertNull($cache->load($key));
        $this->assertSame(['hits' => 0, 'misses' => 0], $cache->getStats());

        $cache->clear();
        $cache->clear('/bar/'); // should not throw
    }
}

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

namespace PHPRegex\Tests\Unit\Cache;

use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Cache\PsrCacheAdapter;
use PHPRegex\Parser\Cache\PsrSimpleCacheAdapter;
use PHPRegex\Parser\Cache\RemovableCacheInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\TestUtils\InMemoryCachePool;
use PHPRegex\Tests\TestUtils\InMemorySimpleCache;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every cache stores a tree and gives back an equal one, whatever the
 * pattern holds: a comma, a quote, a NUL byte or a multibyte character.
 */
final class CacheContractTest extends TestCase
{
    private const PATTERNS = ['/abc/', '/a{2,3}/', '/a,b/', "/a\0b/", '/[,]{1,}/u', "/é,'\"\\\\/u"];

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regex-parser-contract-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new FilesystemCache($this->directory))->clear();
    }

    /**
     * @param \Closure(string):CacheInterface $cache
     */
    #[Test]
    #[DataProvider('provideCaches')]
    public function test_a_tree_comes_back_equal(\Closure $cache): void
    {
        $store = $cache($this->directory);
        $regex = Regex::create(['cache' => null]);

        foreach (self::PATTERNS as $pattern) {
            $tree = $regex->parse($pattern);
            $key = $store->generateKey($pattern);
            $store->write($key, $tree);

            $loaded = $store->load($key);
            $this->assertInstanceOf(RegexNode::class, $loaded, $pattern);
            $this->assertEquals($tree, $loaded, $pattern);
        }
    }

    /**
     * @param \Closure(string):CacheInterface $cache
     */
    #[Test]
    #[DataProvider('provideCaches')]
    public function test_an_unknown_key_is_a_miss(\Closure $cache): void
    {
        $store = $cache($this->directory);

        $this->assertNull($store->load($store->generateKey('/never written/')));
    }

    /**
     * @param \Closure(string):CacheInterface $cache
     */
    #[Test]
    #[DataProvider('provideCaches')]
    public function test_the_facade_parses_once_and_reads_the_tree_back(\Closure $cache): void
    {
        $store = $cache($this->directory);
        $regex = Regex::create(['cache' => $store]);

        foreach (self::PATTERNS as $pattern) {
            $first = $regex->parse($pattern);
            $key = $store->generateKey(RegexParser::cacheSeed($pattern, $regex->target(), Regex::DEFAULT_MAX_RECURSION_DEPTH));

            $this->assertEquals($first, $store->load($key), $pattern);
            $this->assertEquals($first, $regex->parse($pattern), $pattern);
        }
    }

    /**
     * @param \Closure():RemovableCacheInterface $make
     */
    #[DataProvider('provideStats')]
    #[Test]
    public function test_get_stats_returns_the_documented_shape(callable $make): void
    {
        $cache = $make();

        $stats = $cache->getStats();

        $this->assertSame(['hits', 'misses'], array_keys($stats));
        $this->assertIsInt($stats['hits']);
        $this->assertIsInt($stats['misses']);
    }

    /**
     * @return iterable<string, array{callable(): RemovableCacheInterface}>
     */
    public static function provideStats(): iterable
    {
        yield 'null' => [static fn (): RemovableCacheInterface => new NullCache()];
        yield 'PSR-6' => [static fn (): RemovableCacheInterface => new PsrCacheAdapter(new InMemoryCachePool())];
        yield 'PSR-16' => [static fn (): RemovableCacheInterface => new PsrSimpleCacheAdapter(new InMemorySimpleCache())];
    }

    /**
     * @return iterable<string, array{\Closure(string):CacheInterface}>
     */
    public static function provideCaches(): iterable
    {
        yield 'in memory' => [static fn (): CacheInterface => new ArrayCache()];
        yield 'on disk' => [static fn (string $directory): CacheInterface => new FilesystemCache($directory)];
        yield 'PSR-16' => [static fn (): CacheInterface => new PsrSimpleCacheAdapter(new InMemorySimpleCache())];
        yield 'PSR-6' => [static fn (): CacheInterface => new PsrCacheAdapter(new InMemoryCachePool())];
    }

    #[Test]
    public function test_the_hits_count_trees_given_back(): void
    {
        $cache = new ArrayCache();
        $regex = Regex::create(['cache' => $cache]);

        $regex->parse('/a{2,3}/');
        $regex->parse('/a{2,3}/');

        $this->assertSame(['hits' => 1, 'misses' => 1], $cache->getStats());
    }

    #[Test]
    public function test_the_memory_cache_keeps_the_latest_trees(): void
    {
        $cache = new ArrayCache(maxEntries: 2);
        $tree = Regex::create(['cache' => null])->parse('/a/');

        foreach (['one', 'two', 'three'] as $key) {
            $cache->write($key, $tree);
        }

        $this->assertNull($cache->load('one'));
        $this->assertEquals($tree, $cache->load('two'));
        $this->assertEquals($tree, $cache->load('three'));
    }

    /**
     * A shared store holds a serialized tree, never an object its own
     * unserialize() would build; and what is not a tree reads as a miss.
     */
    #[Test]
    public function test_a_shared_store_holds_data_and_refuses_anything_else(): void
    {
        $simple = new InMemorySimpleCache();
        $adapter = new PsrSimpleCacheAdapter($simple);
        $adapter->write('tree', Regex::create(['cache' => null])->parse('/a{2,3}/'));

        $this->assertIsString(unserialize($simple->stored['tree']));

        $simple->set('planted', serialize(new \ArrayObject([1])));
        $this->assertNull($adapter->load('planted'));

        $pool = new InMemoryCachePool();
        $pool->save($pool->getItem('planted')->set(serialize(new \ArrayObject([1]))));
        $this->assertNull((new PsrCacheAdapter($pool))->load('planted'));
    }
}

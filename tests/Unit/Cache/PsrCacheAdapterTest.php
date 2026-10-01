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

use PHPRegex\Parser\Cache\AstSerializer;
use PHPRegex\Parser\Cache\PsrCacheAdapter;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Tests\TestUtils\InMemoryCachePool;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PsrCacheAdapterTest extends TestCase
{
    #[Test]
    public function test_generate_key_uses_prefix_and_hash(): void
    {
        $pool = new InMemoryCachePool();
        $cache = new PsrCacheAdapter($pool, 'pfx_');

        $key = $cache->generateKey('/foo/');

        $this->assertSame('pfx_'.hash('sha256', '/foo/'), $key);
    }

    #[Test]
    public function test_custom_key_factory(): void
    {
        $pool = new InMemoryCachePool();
        $cache = new PsrCacheAdapter($pool, 'pfx_', static fn (string $regex): string => 'custom_'.$regex);

        $key = $cache->generateKey('bar');

        $this->assertSame('pfx_custom_bar', $key);
    }

    #[Test]
    public function test_write_stores_the_serialized_tree_and_load_reads_it_back(): void
    {
        $pool = new InMemoryCachePool();
        $cache = new PsrCacheAdapter($pool);

        $ast = new RegexNode(new LiteralNode('', 0, 0), '', '/', 0, 0);

        $key = $cache->generateKey('foo');
        $cache->write($key, $ast);

        $this->assertSame(AstSerializer::serialize($ast), unserialize($pool->stored[$key]));

        $loaded = $cache->load($key);

        $this->assertInstanceOf(RegexNode::class, $loaded);
        $this->assertInstanceOf(LiteralNode::class, $loaded->pattern);
        $this->assertEquals($ast, $loaded);
    }

    #[Test]
    public function test_clear(): void
    {
        $pool = new InMemoryCachePool();
        $cache = new PsrCacheAdapter($pool);
        $tree = Regex::create(['cache' => null])->parse('/x/');

        $key = $cache->generateKey('foo');
        $cache->write($key, $tree);
        $this->assertEquals($tree, $cache->load($key));

        $cache->clear('foo');
        $this->assertNull($cache->load($key));

        $cache->write($key, Regex::create(['cache' => null])->parse('/y/'));
        $cache->clear();
        $this->assertNull($cache->load($key));
    }

    #[Test]
    public function test_clear_by_regex_keeps_the_other_entries(): void
    {
        $pool = new InMemoryCachePool();
        $cache = new PsrCacheAdapter($pool);
        $tree = Regex::create(['cache' => null])->parse('/b/');

        $cache->write($cache->generateKey('/a/'), Regex::create(['cache' => null])->parse('/a/'));
        $cache->write($cache->generateKey('/b/'), $tree);
        $cache->clear('/a/');

        $this->assertNull($cache->load($cache->generateKey('/a/')));
        $this->assertEquals($tree, $cache->load($cache->generateKey('/b/')));
    }

    /**
     * What the pool holds under a key is not trusted to be a tree: anything
     * else reads as a miss, never as the raw value.
     */
    #[Test]
    #[DataProvider('provideStoredValuesThatAreNotATree')]
    public function test_a_stored_value_that_is_not_a_tree_loads_as_null(mixed $stored): void
    {
        $pool = new InMemoryCachePool();
        $cache = new PsrCacheAdapter($pool);
        $key = $cache->generateKey('planted');

        $pool->save($pool->getItem($key)->set($stored));

        $this->assertNull($cache->load($key));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideStoredValuesThatAreNotATree(): iterable
    {
        yield 'a PHP script' => ["<?php return 'x';"];
        yield 'a serialized string' => [serialize('not-a-node')];
        yield 'a serialized array' => [serialize(['a' => 1])];
        yield 'a serialized object of another class' => [serialize(new \ArrayObject([1]))];
        yield 'a truncated serialized tree' => [substr(serialize(new RegexNode(new LiteralNode('a', 0, 1), '', '/', 0, 1)), 0, 40)];
        yield 'an empty string' => [''];
        yield 'a tree object instead of a string' => [new RegexNode(new LiteralNode('a', 0, 1), '', '/', 0, 1)];
        yield 'an integer' => [42];
    }

    #[Test]
    public function test_get_stats_returns_zero_stats(): void
    {
        $cache = new PsrCacheAdapter(new InMemoryCachePool());

        $stats = $cache->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
    }

    #[Test]
    public function test_get_stats_returns_zero_stats_after_cache_operations(): void
    {
        $pool = new InMemoryCachePool();
        $cache = new PsrCacheAdapter($pool);

        $key = $cache->generateKey('test');
        $cache->write($key, Regex::create(['cache' => null])->parse('/test/'));
        $cache->load($key);
        $cache->load($cache->generateKey('nonexistent'));

        $stats = $cache->getStats();

        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
    }
}

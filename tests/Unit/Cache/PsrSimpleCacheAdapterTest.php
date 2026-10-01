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

namespace PhpRegex\Tests\Unit\Cache;

use PhpRegex\Parser\Cache\AstSerializer;
use PhpRegex\Parser\Cache\PsrSimpleCacheAdapter;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Tests\TestUtils\InMemorySimpleCache;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PsrSimpleCacheAdapterTest extends TestCase
{
    #[Test]
    public function test_stores_and_loads_ast_payload(): void
    {
        $cache = new InMemorySimpleCache();
        $adapter = new PsrSimpleCacheAdapter($cache, prefix: 'simple_');
        $tree = $this->tree('/foo/');

        $key = $adapter->generateKey('/foo/');
        $adapter->write($key, $tree);

        $this->assertSame('simple_'.hash('sha256', '/foo/'), $key);
        $this->assertSame(AstSerializer::serialize($tree), unserialize($cache->stored[$key]));
        $this->assertEquals($tree, $adapter->load($key));
    }

    #[Test]
    public function test_clear_by_regex_removes_entry(): void
    {
        $cache = new InMemorySimpleCache();
        $adapter = new PsrSimpleCacheAdapter($cache, prefix: 'simple_');
        $other = $this->tree('/baz/');

        $key = $adapter->generateKey('/bar/');
        $otherKey = $adapter->generateKey('/baz/');
        $adapter->write($key, $this->tree('/bar/'));
        $adapter->write($otherKey, $other);
        $adapter->clear('/bar/');

        $this->assertNull($adapter->load($key));
        $this->assertEquals($other, $adapter->load($otherKey));
    }

    #[Test]
    public function test_clear_without_regex_clears_all(): void
    {
        $cache = new InMemorySimpleCache();
        $adapter = new PsrSimpleCacheAdapter($cache, prefix: 'test_');

        $key1 = $adapter->generateKey('/foo/');
        $key2 = $adapter->generateKey('/bar/');
        $adapter->write($key1, $this->tree('/foo/'));
        $adapter->write($key2, $this->tree('/bar/'));

        $adapter->clear();

        $this->assertNull($adapter->load($key1));
        $this->assertNull($adapter->load($key2));
    }

    #[Test]
    public function test_custom_key_factory(): void
    {
        $cache = new InMemorySimpleCache();
        $keyFactory = static fn (string $regex) => 'custom_'.md5($regex);
        $adapter = new PsrSimpleCacheAdapter($cache, prefix: 'test_', keyFactory: $keyFactory);

        $key = $adapter->generateKey('/test/');
        $this->assertSame('test_custom_'.md5('/test/'), $key);
    }

    #[Test]
    public function test_the_facade_stores_a_tree_it_reads_back(): void
    {
        $cache = new InMemorySimpleCache();
        $adapter = new PsrSimpleCacheAdapter($cache, prefix: 'test_');

        $regex = Regex::create(['cache' => $adapter]);
        $ast = $regex->parse('/test/');

        $key = $adapter->generateKey(Regex::cacheSeed('/test/', $regex->target(), Regex::DEFAULT_MAX_RECURSION_DEPTH));
        $loaded = $adapter->load($key);

        $this->assertInstanceOf(RegexNode::class, $loaded);
        $this->assertEquals($ast, $loaded);
    }

    /**
     * What the store holds under a key is not trusted to be a tree: anything
     * else reads as a miss, never as the raw value.
     */
    #[Test]
    #[DataProvider('provideStoredValuesThatAreNotATree')]
    public function test_a_stored_value_that_is_not_a_tree_loads_as_null(mixed $stored): void
    {
        $cache = new InMemorySimpleCache();
        $adapter = new PsrSimpleCacheAdapter($cache);
        $key = $adapter->generateKey('/planted/');

        $cache->set($key, $stored);

        $this->assertNull($adapter->load($key));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideStoredValuesThatAreNotATree(): iterable
    {
        yield 'a PHP script' => ["<?php return unserialize('".serialize('plain')."', ['allowed_classes' => true]);"];
        yield 'a serialized string' => [serialize('plain')];
        yield 'a serialized array' => [serialize(['a' => 1])];
        yield 'a serialized object of another class' => [serialize(new \ArrayObject([1]))];
        yield 'garbage' => ['not serialized at all'];
        yield 'an empty string' => [''];
        yield 'a tree object instead of a string' => [new RegexNode(new LiteralNode('a', 0, 1), '', '/', 0, 1)];
        yield 'an integer' => [42];
    }

    #[Test]
    public function test_get_stats_returns_zero_stats(): void
    {
        $adapter = new PsrSimpleCacheAdapter(new InMemorySimpleCache());

        $stats = $adapter->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
    }

    #[Test]
    public function test_get_stats_returns_zero_stats_after_cache_operations(): void
    {
        $cache = new InMemorySimpleCache();
        $adapter = new PsrSimpleCacheAdapter($cache);

        $key = $adapter->generateKey('test');
        $adapter->write($key, $this->tree('/test/'));
        $adapter->load($key);
        $adapter->load($adapter->generateKey('nonexistent'));

        $stats = $adapter->getStats();

        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
    }

    private function tree(string $pattern): RegexNode
    {
        return Regex::create(['cache' => null])->parse($pattern);
    }
}

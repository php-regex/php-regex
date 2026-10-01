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

use PhpRegex\Parser\Cache\ArrayCache;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArrayCacheTest extends TestCase
{
    #[Test]
    public function test_parse_hit_returns_cached_ast(): void
    {
        $cache = new ArrayCache();
        $regex = Regex::create(['cache' => $cache]);

        $first = $regex->parse('/(a|b)+c/');
        $second = $regex->parse('/(a|b)+c/');
        $third = $regex->parse('/(a|b)+c/');

        // The decoded AST must be served from the cache, not re-parsed:
        // hits return the same stored instance, structurally equal to a fresh parse.
        $this->assertInstanceOf(RegexNode::class, $second);
        $this->assertSame($second, $third);
        $this->assertEquals($first, $second);
        $this->assertSame(['hits' => 2, 'misses' => 1], $cache->getStats());
    }

    #[Test]
    public function test_generate_key_returns_hash(): void
    {
        $cache = new ArrayCache();
        $key = $cache->generateKey('/foo/');

        $this->assertSame(hash('sha256', '/foo/'), $key);
    }

    #[Test]
    public function test_write_and_load_cache_entry(): void
    {
        $cache = new ArrayCache();
        $key = $cache->generateKey('/test/');
        $tree = $this->tree('/test/');

        $cache->write($key, $tree);

        // Trees are immutable: the stored instance itself is handed back.
        $this->assertSame($tree, $cache->load($key));
    }

    #[Test]
    public function test_load_increments_hits_on_cache_hit(): void
    {
        $cache = new ArrayCache();
        $key = $cache->generateKey('/hit/');

        $cache->write($key, $this->tree('/hit/'));
        $cache->load($key);
        $cache->load($key);

        $stats = $cache->getStats();
        $this->assertSame(2, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
    }

    #[Test]
    public function test_load_increments_misses_on_cache_miss(): void
    {
        $cache = new ArrayCache();
        $key = $cache->generateKey('/miss/');

        $cache->load($key);
        $cache->load($key);

        $stats = $cache->getStats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(2, $stats['misses']);
    }

    #[Test]
    public function test_load_returns_null_for_nonexistent_key(): void
    {
        $cache = new ArrayCache();
        $key = $cache->generateKey('/nonexistent/');

        $this->assertNull($cache->load($key));
    }

    #[Test]
    public function test_clear_removes_all_entries(): void
    {
        $cache = new ArrayCache();
        $key1 = $cache->generateKey('/abc/');
        $key2 = $cache->generateKey('/def/');

        $cache->write($key1, $this->tree('/abc/'));
        $cache->write($key2, $this->tree('/def/'));
        $cache->clear();

        $this->assertNull($cache->load($key1));
        $this->assertNull($cache->load($key2));
        $this->assertSame(['hits' => 0, 'misses' => 2], $cache->getStats());
    }

    #[Test]
    public function test_clear_with_specific_regex_removes_only_that_entry(): void
    {
        $cache = new ArrayCache();
        $key1 = $cache->generateKey('/abc/');
        $key2 = $cache->generateKey('/def/');
        $tree2 = $this->tree('/def/');

        $cache->write($key1, $this->tree('/abc/'));
        $cache->write($key2, $tree2);
        $cache->clear('/abc/');

        $this->assertNull($cache->load($key1));
        $this->assertSame($tree2, $cache->load($key2));
    }

    #[Test]
    public function test_clear_nonexistent_regex_does_not_affect_other_entries(): void
    {
        $cache = new ArrayCache();
        $key1 = $cache->generateKey('/existing/');
        $tree = $this->tree('/existing/');

        $cache->write($key1, $tree);

        $cache->clear('/nonexistent/');

        $this->assertSame($tree, $cache->load($key1));
    }

    #[Test]
    public function test_get_stats_returns_current_stats(): void
    {
        $cache = new ArrayCache();
        $key = $cache->generateKey('/stats/');

        $stats = $cache->getStats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);

        $cache->write($key, $this->tree('/stats/'));
        $cache->load($key);

        $stats = $cache->getStats();
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(0, $stats['misses']);

        $cache->load($cache->generateKey('/nonexistent/'));

        $stats = $cache->getStats();
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
    }

    #[Test]
    public function test_write_replaces_the_stored_tree(): void
    {
        $cache = new ArrayCache();
        $key = $cache->generateKey('/replace/');
        $second = $this->tree('/second/');

        $cache->write($key, $this->tree('/first/'));
        $cache->write($key, $second);

        $this->assertSame($second, $cache->load($key));
    }

    #[Test]
    public function test_write_makes_a_rewritten_entry_the_newest(): void
    {
        $cache = new ArrayCache(maxEntries: 2);
        $a = $this->tree('/a/');
        $c = $this->tree('/c/');

        $cache->write('a', $a);
        $cache->write('b', $this->tree('/b/'));
        $cache->write('a', $a);
        $cache->write('c', $c);

        // Rewriting "a" moved it after "b", so "b" was the oldest when "c"
        // pushed the cache past its size.
        $this->assertNull($cache->load('b'));
        $this->assertSame($a, $cache->load('a'));
        $this->assertSame($c, $cache->load('c'));
    }

    #[Test]
    public function test_the_default_size_keeps_1024_entries(): void
    {
        $cache = new ArrayCache();
        $tree = $this->tree('/a/');

        for ($i = 0; $i <= 1024; $i++) {
            $cache->write('key'.$i, $tree);
        }

        $this->assertNull($cache->load('key0'));
        $this->assertSame($tree, $cache->load('key1'));
        $this->assertSame($tree, $cache->load('key1024'));
    }

    #[Test]
    public function test_clear_resets_stats(): void
    {
        $cache = new ArrayCache();
        $key = $cache->generateKey('/reset/');

        $cache->write($key, $this->tree('/reset/'));
        $cache->load($key);
        $cache->load($cache->generateKey('/nonexistent/'));

        $stats = $cache->getStats();
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(1, $stats['misses']);

        $cache->clear();

        $stats = $cache->getStats();
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
    }

    #[Test]
    public function test_multiple_entries(): void
    {
        $cache = new ArrayCache();
        $entries = [];
        foreach (['/pattern1/', '/pattern2/', '/pattern3/'] as $pattern) {
            $entries[$pattern] = $this->tree($pattern);
        }

        foreach ($entries as $pattern => $tree) {
            $cache->write($cache->generateKey($pattern), $tree);
        }

        foreach ($entries as $pattern => $tree) {
            $this->assertSame($tree, $cache->load($cache->generateKey($pattern)));
        }

        $stats = $cache->getStats();
        $this->assertSame(3, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
    }

    private function tree(string $pattern): RegexNode
    {
        return Regex::create(['cache' => null])->parse($pattern);
    }
}

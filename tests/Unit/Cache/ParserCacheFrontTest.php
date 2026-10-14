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

use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\PsrSimpleCacheAdapter;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\TestUtils\InMemorySimpleCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

/**
 * A persistent cache (a PSR pool, a directory) answers a tree it has to
 * fetch and unserialize: the parser keeps the trees it read or wrote in
 * memory in front of it, so a pattern parsed again in the process costs
 * neither.
 */
final class ParserCacheFrontTest extends TestCase
{
    #[Test]
    public function test_a_pattern_parsed_again_does_not_reach_the_persistent_cache(): void
    {
        $pool = new CountingSimpleCache();
        $parser = RegexParser::create(['cache' => new PsrSimpleCacheAdapter($pool)]);

        $first = $parser->parse('/a+b/');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $parser->parse('/a+b/'));
        }

        $this->assertSame(1, $pool->gets, 'The first parse looks the tree up, the others find it in memory.');
        $this->assertCount(1, $pool->stored);
    }

    #[Test]
    public function test_a_tree_read_from_the_persistent_cache_is_unserialized_once(): void
    {
        $pool = new CountingSimpleCache();
        RegexParser::create(['cache' => new PsrSimpleCacheAdapter($pool)])->parse('/a+b/');

        $parser = RegexParser::create(['cache' => new PsrSimpleCacheAdapter($pool)]);
        $first = $parser->parse('/a+b/');
        $this->assertSame($first, $parser->parse('/a+b/'));
        $this->assertSame(2, $pool->gets, 'One lookup per parser: a new process reads the tree once.');
    }

    #[Test]
    public function test_clearing_the_caches_empties_the_memory_in_front(): void
    {
        $pool = new CountingSimpleCache();
        $parser = RegexParser::create(['cache' => new PsrSimpleCacheAdapter($pool)]);
        $parser->parse('/a+b/');

        $parser->clearCaches();
        $parser->parse('/a+b/');

        $this->assertSame(2, $pool->gets, 'After clearCaches() the tree is looked up in the persistent cache again.');
    }

    #[Test]
    public function test_another_target_does_not_share_the_tree(): void
    {
        $pool = new CountingSimpleCache();
        $old = RegexParser::create(['cache' => new PsrSimpleCacheAdapter($pool), 'pcre_version' => '10.42']);
        $new = RegexParser::create(['cache' => new PsrSimpleCacheAdapter($pool), 'pcre_version' => '10.43']);

        $old->parse('/a{,2}/');
        $new->parse('/a{,2}/');
        $old->parse('/a{,2}/');

        $this->assertCount(2, $pool->stored, 'One tree per target: "a{,2}" is text before PCRE2 10.43.');
        $this->assertSame(2, $pool->gets);
    }

    #[Test]
    public function test_a_directory_cache_gets_the_memory_in_front_too(): void
    {
        $directory = sys_get_temp_dir().'/regex-parser-front-'.bin2hex(random_bytes(6));
        $cache = new FilesystemCache($directory);

        try {
            $parser = RegexParser::create(['cache' => $cache]);
            $first = $parser->parse('/a+b/');
            $this->assertSame($first, $parser->parse('/a+b/'));
            $this->assertSame($cache, $parser->getCache(), 'getCache() still names the cache given.');
            $this->assertSame(['hits' => 1, 'misses' => 1], $parser->getCacheStats());
        } finally {
            $cache->clear();
            @rmdir($directory);
        }
    }

    #[Test]
    public function test_a_cache_of_the_application_is_asked_every_time(): void
    {
        $cache = new class implements CacheInterface {
            public int $loads = 0;

            public function generateKey(string $seed): string
            {
                return hash('sha256', $seed);
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): ?RegexNode
            {
                $this->loads++;

                return null;
            }
        };

        $parser = RegexParser::create(['cache' => $cache]);
        $parser->parse('/a+b/');
        $parser->parse('/a+b/');

        $this->assertSame(2, $cache->loads, 'A cache the application writes keeps its own policy: nothing is put in front of it.');
    }
}

/**
 * @internal
 */
final class CountingSimpleCache implements SimpleCacheInterface
{
    public int $gets = 0;

    /**
     * @var array<string, string>
     */
    public array $stored = [];

    private readonly InMemorySimpleCache $inner;

    public function __construct()
    {
        $this->inner = new InMemorySimpleCache();
        $this->stored = &$this->inner->stored;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->gets++;

        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->inner->getMultiple($keys, $default);
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }
}

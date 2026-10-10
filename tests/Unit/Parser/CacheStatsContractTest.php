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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Laravel\Facades\Regex as LaravelRegex;
use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\RemovableCacheInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The cache-statistics shape gets one name, CacheStats, and it lives on the
 * cache interface every implementation answers from: the implementations
 * and the parser front door import the name there instead of restating the
 * shape (the import sits at class level — a method docblock cannot resolve
 * an imported alias).
 *
 * The two mirror surfaces deliberately do not take the name: the facade
 * parity test refines the facade's bare `array` return through the @return
 * shape of the toolkit method it mirrors, and it reads shapes, not alias
 * names — so the mirror keeps spelling array{hits: int, misses: int} out
 * for as long as that refinement rule stands.
 */
final class CacheStatsContractTest extends TestCase
{
    #[Test]
    public function test_cache_interface_exports_the_cache_stats_alias(): void
    {
        $this->assertStringContainsString(
            '@phpstan-type CacheStats',
            (string) (new \ReflectionClass(RemovableCacheInterface::class))->getDocComment(),
            'RemovableCacheInterface is the home of CacheStats: every cache implementation and every stats reader imports it from there. The declaration is `@phpstan-type CacheStats` followed by the shape and nothing else on the line.',
        );
    }

    #[Test]
    #[DataProvider('provideStatsDocblocks')]
    public function test_stats_docblock_references_the_alias(string $class, string $method): void
    {
        $this->assertStringContainsString(
            'CacheStats',
            (string) (new \ReflectionMethod($class, $method))->getDocComment(),
            sprintf('%s::%s() must reference CacheStats instead of restating the shape: the shape is single-sourced on RemovableCacheInterface, and a docblock that drifts from it is a lie no runtime test catches.', $class, $method),
        );
    }

    #[Test]
    public function test_toolkit_mirror_keeps_the_inline_shape(): void
    {
        $docblock = (string) (new \ReflectionMethod(Regex::class, 'getCacheStats'))->getDocComment();

        $this->assertStringContainsString(
            'array{hits: int, misses: int}',
            $docblock,
            'Toolkit\Regex::getCacheStats() keeps the literal shape: the facade parity test refines the facade line through this @return, and it reads array shapes, not alias names.',
        );
        $this->assertStringNotContainsString(
            'CacheStats',
            $docblock,
            'Toolkit\Regex::getCacheStats() naming CacheStats would stop the facade parity refinement (a bare `array` return no longer sharpened by a shape) and break the parity test the facade is pinned by.',
        );
    }

    #[Test]
    public function test_facade_mirror_keeps_the_inline_shape(): void
    {
        $docblock = (string) (new \ReflectionClass(LaravelRegex::class))->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@method[^\n]*getCacheStats\([^\n]*/', $docblock, $line),
            'The facade must document getCacheStats() with an @method line.',
        );
        $this->assertStringContainsString(
            'array{hits: int, misses: int}',
            $line[0],
            'The facade getCacheStats() line keeps the literal shape — the facade mirror decision: the parity test cannot resolve alias names, so the shape stays inline here.',
        );
        // The return type is everything before the method name — the name itself
        // contains "CacheStats", so the docblock as a whole always would.
        $this->assertStringNotContainsString(
            'CacheStats',
            (string) strstr($line[0], 'getCacheStats', true),
            'The facade line must not name CacheStats as a type: nothing on the facade side resolves the alias, and the parity test compares the line against the reflected signature as written.',
        );
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function provideStatsDocblocks(): iterable
    {
        yield 'the interface itself' => [RemovableCacheInterface::class, 'getStats'];

        yield 'ArrayCache' => [ArrayCache::class, 'getStats'];

        yield 'FilesystemCache' => [FilesystemCache::class, 'getStats'];

        yield 'the parser front door' => [RegexParser::class, 'getCacheStats'];
    }
}

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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regex::captureShape() is the one-line entry point to the capture shape: the
 * analyzer's answer on the tree the facade parses, through its cache.
 */
final class RegexCaptureShapeTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_regex_facade_returns_the_capture_shape(string $pattern, string $expected): void
    {
        $shape = Regex::create(['cache' => new NullCache()])->captureShape($pattern);

        $this->assertEquals((new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern)), $shape);
        $this->assertSame($expected, $shape->matchShape());
    }

    #[Test]
    public function test_regex_facade_capture_shape_reads_the_tree_through_its_cache(): void
    {
        $cached = RegexParser::create()->parse('/(x)(y)/');
        $cache = new class($cached) implements CacheInterface {
            public int $loads = 0;

            public function __construct(private readonly RegexNode $tree) {}

            public function generateKey(string $regex): string
            {
                return 'key_'.$regex;
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): RegexNode
            {
                $this->loads++;

                return $this->tree;
            }
        };

        $shape = Regex::create(['cache' => $cache])->captureShape('/(a)?b/');

        // The tree the cache served, not a fresh parse of the pattern.
        $this->assertSame(1, $cache->loads);
        $this->assertSame("array{0: 'xy', 1: 'x', 2: 'y'}", $shape->matchShape());
    }

    #[Test]
    #[DataProvider('provideInvalidPatterns')]
    public function test_regex_facade_capture_shape_throws_what_parse_throws(string $pattern): void
    {
        $regex = Regex::create(['cache' => new NullCache()]);

        try {
            $regex->parse($pattern);
            $this->fail(\sprintf('parse() was expected to refuse %s.', $pattern));
        } catch (ParserException $expected) {
        }

        $this->expectException($expected::class);
        $this->expectExceptionMessage($expected->getMessage());

        $regex->captureShape($pattern);
    }

    /**
     * preg_match('/(a)?b/', 'b') -> ["b"]; on 'ab' -> ["ab","a"] (PHP 8.4.26).
     *
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'trailing optional group' => ['pattern' => '/(a)?b/', 'expected' => "array{0: 'b'|'ab', 1?: 'a'}"];
        yield 'no group' => ['pattern' => '/a/', 'expected' => "array{0: 'a'}"];
        yield 'named group' => ['pattern' => '/(?<x>a)/', 'expected' => "array{0: 'a', x: 'a', 1: 'a'}"];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideInvalidPatterns(): iterable
    {
        yield 'unclosed group' => ['pattern' => '/(a/'];
        yield 'no closing delimiter' => ['pattern' => '/a'];
    }
}

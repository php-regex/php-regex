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

namespace RegexParser\Tests\Functional;

use PHPUnit\Framework\TestCase;
use RegexParser\Cache\CacheInterface;
use RegexParser\Cache\FilesystemCache;
use RegexParser\Cache\RemovableCacheInterface;
use RegexParser\Regex;
use RegexParser\ValidationErrorCategory;

final class RegexApiTest extends TestCase
{
    public function test_create_and_parse(): void
    {
        $regex = Regex::create();

        $ast = $regex->parse('/abc/');
        $this->assertSame(0, $ast->startPosition);
        $this->assertSame(3, $ast->endPosition);
    }

    public function test_validate(): void
    {
        $regex = Regex::create();

        $valid = $regex->validate('/abc/');
        $this->assertTrue($valid->isValid);

        $invalid = $regex->validate('/(abc/'); // Unclosed parenthesis
        $this->assertFalse($invalid->isValid);
        $this->assertNotNull($invalid->error);
    }

    public function test_runtime_validation_disabled_by_default(): void
    {
        $regex = Regex::create();

        // "[[:<:]]" compiles to a word boundary and a lookahead, repeated
        // here past what PCRE compiles; the static checks do not measure it.
        $result = $regex->validate('/[[:<:]]{65535}/');

        $this->assertTrue($result->isValid);
    }

    public function test_runtime_validation_can_be_enabled(): void
    {
        $regex = Regex::create(['runtime_pcre_validation' => true]);

        $result = $regex->validate('/[[:<:]]{65535}/');

        $this->assertFalse($result->isValid);
        $this->assertSame(ValidationErrorCategory::PCRE_RUNTIME, $result->category);
        $this->assertSame('regex.pcre.runtime', $result->errorCode);
        $this->assertStringContainsString('PCRE runtime error', (string) $result->error);
        // PCRE2 10.48 reports "regular expression is too large" at offset 0,
        // the releases before at the end of the pattern.
        $this->assertContains($result->offset, [0, 14]);
    }

    public function test_optimize(): void
    {
        $regex = Regex::create();
        // Should optimize [0-9] to \d
        $optimized = $regex->optimize('/[0-9]/');

        // Note: the CompilerNodeVisitor adds the \ before d
        $this->assertSame('/\d/', $optimized->optimized);
    }

    public function test_generate(): void
    {
        $regex = Regex::create();
        $sample = $regex->generate('/\d{3}/');
        $this->assertMatchesRegularExpression('/\d{3}/', $sample);
    }

    public function test_parse_uses_cache_on_second_call(): void
    {
        $cacheDir = sys_get_temp_dir().'/regex-parser-cache-'.uniqid('', true);
        $cache = new class(new FilesystemCache($cacheDir)) implements RemovableCacheInterface {
            public int $writeCount = 0;

            public int $loadCount = 0;

            public function __construct(private readonly CacheInterface $cache) {}

            public function write(string $key, string $content): void
            {
                $this->writeCount++;
                $this->cache->write($key, $content);
            }

            public function load(string $key): mixed
            {
                $this->loadCount++;

                return $this->cache->load($key);
            }

            public function generateKey(string $regex): string
            {
                return $this->cache->generateKey($regex);
            }

            public function getTimestamp(string $key): int
            {
                return $this->cache->getTimestamp($key);
            }

            public function clear(?string $regex = null): void
            {
                if ($this->cache instanceof RemovableCacheInterface) {
                    $this->cache->clear($regex);
                }
            }

            public function getStats(): array
            {
                if ($this->cache instanceof RemovableCacheInterface) {
                    return $this->cache->getStats();
                }

                return ['hits' => 0, 'misses' => 0];
            }
        };

        try {
            $regex = Regex::create(['cache' => $cache]);
            $pattern = '/[a-z]{3}/';

            $firstAst = $regex->parse($pattern);
            $secondAst = $regex->parse($pattern);

            // The second call finds the AST on disk: it reads the cache
            // again, but it does not parse and store the pattern twice.
            $this->assertSame(1, $cache->writeCount);
            $this->assertSame(2, $cache->loadCount);
            $this->assertEquals($firstAst, $secondAst);
        } finally {
            $cache->clear();
        }
    }

    public function test_parse_ignores_leading_whitespace(): void
    {
        $regex = Regex::create();

        // Test that leading whitespace is ignored before delimiter detection
        $patternWithWhitespace = "\n    /^[a-z]+$/";
        $ast = $regex->parse($patternWithWhitespace);

        // Should successfully parse and recognize '/' as delimiter
        $this->assertSame('/', $ast->delimiter);

        // The pattern should be equivalent to the trimmed version
        $trimmedPattern = ltrim($patternWithWhitespace);
        $astTrimmed = $regex->parse($trimmedPattern);
        $this->assertEquals($ast, $astTrimmed);
    }

    public function test_parse_pattern_constructs_full_regex(): void
    {
        $regex = Regex::create();

        // Test basic pattern
        $ast1 = $regex->parsePattern('abc', '', '/');
        $ast2 = $regex->parse('/abc/');
        $this->assertEquals($ast1, $ast2);

        // Test with flags
        $ast3 = $regex->parsePattern('abc', 'i', '/');
        $ast4 = $regex->parse('/abc/i');
        $this->assertEquals($ast3, $ast4);

        // Test with different delimiter
        $ast5 = $regex->parsePattern('abc', '', '#');
        $ast6 = $regex->parse('#abc#');
        $this->assertEquals($ast5, $ast6);

        // Test with flags and different delimiter
        $ast7 = $regex->parsePattern('abc', 'i', '#');
        $ast8 = $regex->parse('#abc#i');
        $this->assertEquals($ast7, $ast8);
    }
}

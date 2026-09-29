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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RegexParser\Cache\CacheInterface;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Regex;

final class RegexEdgeCasesTest extends TestCase
{
    public function test_a_failing_cache_does_not_fail_the_analysis(): void
    {
        $cache = new class implements CacheInterface {
            private int $loadCalls = 0;

            public function generateKey(string $regex): string
            {
                return 'key';
            }

            public function write(string $key, string $content): void {}

            public function load(string $key): mixed
            {
                $this->loadCalls++;
                if ($this->loadCalls > 1) {
                    throw new \RuntimeException('cache load failed');
                }

                return null;
            }

            public function getTimestamp(string $key): int
            {
                return 0;
            }
        };

        $regex = Regex::create(['cache' => $cache]);

        $report = $regex->analyze('/abc/');

        // A cache that throws is a broken cache, not a broken pattern.
        $this->assertTrue($report->isValid);
        $this->assertSame([], $report->errors);
    }

    public function test_cache_seed_includes_the_target(): void
    {
        $regex = Regex::create(['php_version' => 80000]);
        $ref = new \ReflectionClass($regex);

        $method = $ref->getMethod('getCacheSeed');
        $seed = $method->invoke($regex, '/abc/');

        $this->assertIsString($seed);
        $this->assertStringContainsString('#target=php8.0/pcre10.40', (string) $seed);
    }

    public function test_store_in_cache_swallows_write_errors(): void
    {
        $cache = new class implements CacheInterface {
            public function generateKey(string $regex): string
            {
                return 'key';
            }

            public function write(string $key, string $content): void
            {
                throw new \RuntimeException('cache write failed');
            }

            public function load(string $key): mixed
            {
                return null;
            }

            public function getTimestamp(string $key): int
            {
                return 0;
            }
        };
        $regex = Regex::create(['cache' => $cache]);

        $ref = new \ReflectionClass($regex);

        $method = $ref->getMethod('storeInCache');
        $ast = new RegexNode(new SequenceNode([new LiteralNode('a', 0, 0)], 0, 0), '', '/', 0, 1);

        $result = $method->invoke($regex, 'key', $ast);
        $this->assertNull($result);
    }

    public function test_build_fallback_ast_uses_full_pattern_when_no_error_position(): void
    {
        $regex = Regex::create();
        $ref = new \ReflectionClass($regex);
        $method = $ref->getMethod('buildFallbackAst');

        $fallback = $method->invoke($regex, 'abc', '', '/', 3, null);

        $this->assertInstanceOf(RegexNode::class, $fallback);
        $this->assertInstanceOf(SequenceNode::class, $fallback->pattern);
        $this->assertInstanceOf(LiteralNode::class, $fallback->pattern->children[0]);
        $this->assertSame('abc', $fallback->pattern->children[0]->value);
    }
}

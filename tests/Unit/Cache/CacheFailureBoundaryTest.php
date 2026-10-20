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
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A cache that fails with an exception is a cache miss, or a write that
 * did not happen: the pattern is parsed again. An \Error is a bug, in the
 * cache or in the library, and is not hidden as a miss.
 */
final class CacheFailureBoundaryTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideFailingSteps')]
    public function test_an_exception_from_the_cache_leaves_the_parse_to_parse(string $step): void
    {
        $parser = RegexParser::create(['cache' => self::cacheFailingOn($step, new \RuntimeException('The cache is down.'))]);

        $ast = $parser->parse('/abc/');
        $parser->remember('/abc/', $ast);

        $this->assertSame('/', $ast->delimiter);
    }

    #[Test]
    #[DataProvider('provideFailingSteps')]
    public function test_an_error_from_the_cache_surfaces(string $step): void
    {
        $parser = RegexParser::create(['cache' => self::cacheFailingOn($step, new \TypeError('A bug in the cache.'))]);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageIsOrContains('A bug in the cache.');

        $parser->remember('/abc/', $parser->parse('/abc/'));
    }

    /**
     * @return iterable<string, array{step: string}>
     */
    public static function provideFailingSteps(): iterable
    {
        yield 'the key' => ['step' => 'key'];
        yield 'a load' => ['step' => 'load'];
        yield 'a write after a parse' => ['step' => 'write'];
        yield 'a write the caller asks for' => ['step' => 'remember'];
    }

    private static function cacheFailingOn(string $step, \Throwable $failure): CacheInterface
    {
        return new class($step, $failure) implements CacheInterface {
            private int $writes = 0;

            public function __construct(private readonly string $step, private readonly \Throwable $failure) {}

            public function generateKey(string $seed): string
            {
                if ('key' === $this->step) {
                    throw $this->failure;
                }

                return 'key';
            }

            public function write(string $key, RegexNode $ast): void
            {
                // The parse writes first; remember() writes second.
                $this->writes++;
                if (('write' === $this->step && 1 === $this->writes) || ('remember' === $this->step && 2 === $this->writes)) {
                    throw $this->failure;
                }
            }

            public function load(string $key): ?RegexNode
            {
                if ('load' === $this->step) {
                    throw $this->failure;
                }

                return null;
            }
        };
    }
}

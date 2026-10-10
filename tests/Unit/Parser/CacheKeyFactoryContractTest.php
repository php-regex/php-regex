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

use PHPRegex\Parser\Cache\PsrCacheAdapter;
use PHPRegex\Parser\Cache\PsrSimpleCacheAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The keyFactory both PSR adapters inject: a closure that receives the regex
 * as written and returns the key body — a string is prefixed as is, anything
 * else is serialized and hashed (generateKey() checks). The bare ?Closure
 * the constructors say today tells a caller nothing: with the signature
 * written out — null|(\Closure(string): mixed) — a closure with the wrong
 * arity is a static error where it is passed, not a TypeError on the first
 * cache write. This file sits beside CacheStatsContractTest, one cache
 * docblock contract per file: that one owns the stats shape, this one the
 * key factory callable.
 */
final class CacheKeyFactoryContractTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('provideAdapters')]
    public function test_key_factory_param_declares_the_closure_signature(string $class): void
    {
        $docComment = (string) (new \ReflectionMethod($class, '__construct'))->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@param\s+([^\n]+?)\s+\$keyFactory\b/', $docComment, $param),
            sprintf(
                '%s::__construct() must document its $keyFactory: it is a callable with one job — string in, key'
                .' body out — and a caller reading bare ?Closure cannot know that.',
                $class,
            ),
        );

        $type = $param[1];
        $this->assertStringContainsString(
            'Closure(string): mixed',
            $type,
            'The keyFactory receives the regex as written and returns mixed: string is the key body, anything else is hashed.',
        );

        $members = explode('|', strtr($type, ['(' => '', ')' => '']));
        if (str_starts_with($type, '?')) {
            $members[] = 'null';
        }
        $this->assertContains(
            'null',
            $members,
            'The factory is optional: null means generateKey() hashes the regex itself.',
        );
    }

    /**
     * @return iterable<string, array{class: class-string}>
     */
    public static function provideAdapters(): iterable
    {
        yield 'PSR-6' => ['class' => PsrCacheAdapter::class];

        yield 'PSR-16' => ['class' => PsrSimpleCacheAdapter::class];
    }
}

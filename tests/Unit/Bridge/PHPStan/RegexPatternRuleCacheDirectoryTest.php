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

namespace PHPRegex\Tests\Unit\Bridge\PHPStan;

use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Hir\ClassSetStore;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule keeps what the engine answered about Unicode classes in the
 * directory PHPStan gives it, its own temporary directory, for the next
 * run and the other workers to read; given none, nothing is kept.
 */
final class RegexPatternRuleCacheDirectoryTest extends TestCase
{
    protected function tearDown(): void
    {
        ClassSetProvider::persistIn(null);
    }

    #[Test]
    public function test_the_rule_keeps_the_class_scans_in_the_directory_it_is_given(): void
    {
        $directory = sys_get_temp_dir().'/phpstan/php-regex';
        $rule = new RegexPatternRule(cacheDirectory: $directory);

        $this->assertSame($directory, (new \ReflectionProperty(RegexPatternRule::class, 'cacheDirectory'))->getValue($rule));
        $this->assertSame((new ClassSetStore($directory))->directory(), self::store()?->directory());

        new RegexPatternRule();
        $this->assertNull(self::store());
    }

    private static function store(): ?ClassSetStore
    {
        $store = (new \ReflectionClass(ClassSetProvider::class))->getStaticPropertyValue('store');
        self::assertTrue(null === $store || $store instanceof ClassSetStore);

        return $store;
    }
}

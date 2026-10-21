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
use PHPStan\DependencyInjection\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the engine answers about Unicode classes is kept under PHPStan's
 * own temporary directory, for the next run and the other workers to read:
 * the extension's bootstrap file, which PHPStan runs once in each process,
 * says where. Building a rule leaves that process-wide setting alone.
 */
final class ExtensionBootstrapTest extends TestCase
{
    private const BOOTSTRAP = __DIR__.'/../../../../src/PHPStan/bootstrap.php';

    protected function tearDown(): void
    {
        ClassSetProvider::persistIn(null);
    }

    #[Test]
    public function test_the_bootstrap_keeps_the_class_scans_under_phpstan_temporary_directory(): void
    {
        $directory = sys_get_temp_dir().'/phpstan';
        self::bootstrapWith($this->containerWith($directory));

        $this->assertSame((new ClassSetStore($directory.'/php-regex'))->directory(), self::store()?->directory());
    }

    #[Test]
    public function test_the_bootstrap_without_a_container_keeps_nothing(): void
    {
        ClassSetProvider::persistIn(sys_get_temp_dir().'/elsewhere');
        self::bootstrapWith(null);

        $this->assertNull(self::store());
    }

    #[Test]
    public function test_building_a_rule_leaves_the_class_scans_where_they_are_kept(): void
    {
        $directory = sys_get_temp_dir().'/phpstan/php-regex';
        ClassSetProvider::persistIn($directory);

        new RegexPatternRule();
        $this->assertSame((new ClassSetStore($directory))->directory(), self::store()?->directory());

        ClassSetProvider::persistIn(null);
        new RegexPatternRule();
        $this->assertNull(self::store());
    }

    private function containerWith(string $tmpDir): Container
    {
        $container = $this->createStub(Container::class);
        $container->method('getParameter')->willReturnMap([['tmpDir', $tmpDir]]);

        return $container;
    }

    /**
     * Runs the bootstrap file as PHPStan runs it, with its container in
     * scope.
     */
    private static function bootstrapWith(?Container $container): void
    {
        (static function () use ($container): void {
            require self::BOOTSTRAP;
        })();
    }

    private static function store(): ?ClassSetStore
    {
        $store = (new \ReflectionClass(ClassSetProvider::class))->getStaticPropertyValue('store');
        self::assertTrue(null === $store || $store instanceof ClassSetStore);

        return $store;
    }
}

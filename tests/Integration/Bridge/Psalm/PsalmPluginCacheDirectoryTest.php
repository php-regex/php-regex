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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Hir\ClassSetStore;
use PHPRegex\Psalm\Plugin;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Config;
use Psalm\Plugin\RegistrationInterface;

/**
 * The plugin keeps what the engine answered about Unicode classes in
 * Psalm's cache directory, which "--clear-cache" empties; under
 * "--no-cache" or noCache="true" Psalm has none, and nothing is kept.
 */
final class PsalmPluginCacheDirectoryTest extends TestCase
{
    use TemporaryProject;

    /**
     * PHPUnit's arguments, put back after the test.
     */
    private mixed $argv = null;

    /**
     * Psalm reads the global $argv as the paths of a CLI run: PHPUnit's
     * arguments would become files to look for, and one it cannot find
     * would make it exit.
     */
    protected function setUp(): void
    {
        $this->argv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['argv'] = $this->argv;
        ClassSetProvider::persistIn(null);
    }

    #[Test]
    public function test_the_plugin_keeps_the_class_scans_in_psalm_cache_directory(): void
    {
        $project = $this->makeProject(['a.php' => "<?php\n"]);
        $config = Config::loadFromXML($project, self::xml($project.'/cache'), $project);

        (new Plugin())($this->createStub(RegistrationInterface::class));

        $cacheDirectory = $config->getCacheDirectory();
        $this->assertIsString($cacheDirectory);
        $this->assertStringStartsWith($project.'/cache', $cacheDirectory);
        $this->assertSame((new ClassSetStore($cacheDirectory.\DIRECTORY_SEPARATOR.'php-regex'))->directory(), self::store()?->directory());
    }

    #[Test]
    public function test_the_plugin_keeps_nothing_without_a_cache_directory(): void
    {
        $project = $this->makeProject(['a.php' => "<?php\n"]);
        ClassSetProvider::persistIn($project.'/elsewhere');
        Config::loadFromXML($project, self::xml(null), $project);

        (new Plugin())($this->createStub(RegistrationInterface::class));

        $this->assertNull(self::store());
    }

    /**
     * A configuration caching in the directory, or with noCache, as
     * --no-cache leaves it.
     *
     * @return non-empty-string
     */
    private static function xml(?string $cacheDirectory): string
    {
        $cache = null === $cacheDirectory ? 'noCache="true"' : \sprintf('cacheDirectory="%s"', htmlspecialchars($cacheDirectory, \ENT_XML1 | \ENT_QUOTES));

        return "<?xml version=\"1.0\"?>\n<psalm errorLevel=\"1\" ".$cache.">\n    <projectFiles>\n        <file name=\"a.php\"/>\n    </projectFiles>\n</psalm>";
    }

    private static function store(): ?ClassSetStore
    {
        $store = (new \ReflectionClass(ClassSetProvider::class))->getStaticPropertyValue('store');
        self::assertTrue(null === $store || $store instanceof ClassSetStore);

        return $store;
    }
}

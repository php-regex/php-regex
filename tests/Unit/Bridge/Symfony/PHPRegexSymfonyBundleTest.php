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

namespace PHPRegex\Tests\Unit\Bridge\Symfony;

use PHPRegex\Symfony\PHPRegexSymfonyBundle;
use PHPRegex\Tests\Support\Paths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class PHPRegexSymfonyBundleTest extends TestCase
{
    #[Test]
    public function test_get_path_returns_bundle_directory(): void
    {
        $bundle = new PHPRegexSymfonyBundle();

        $this->assertTrue(Paths::same(\dirname(__DIR__, 4).'/src/Symfony', $bundle->getPath()), $bundle->getPath());
    }

    /**
     * Symfony Flex registers a "symfony-bundle" package without a recipe by
     * guessing its class from each PSR-4 prefix (symfony/flex 2.4,
     * SymfonyBundle::extractClassNames()): the last segment with "Bundle",
     * then each segment before it in front of that. A file of that name is
     * the bundle when it names Symfony's Bundle or AbstractBundle.
     */
    #[Test]
    public function test_symfony_flex_finds_the_bundle_from_the_package_autoload(): void
    {
        $root = \dirname(__DIR__, 4).'/src/Symfony';
        $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $this->assertSame('symfony-bundle', $manifest['type'] ?? null);
        $autoload = $manifest['autoload'] ?? null;
        $this->assertIsArray($autoload);
        $this->assertSame(['PHPRegex\\Symfony\\' => ''], $autoload['psr-4'] ?? null);

        // The guesses for "PHPRegex\Symfony\", each a file at the root.
        $found = [];
        foreach (['SymfonyBundle', 'PHPRegexSymfonyBundle'] as $name) {
            $class = 'PHPRegex\\Symfony\\'.$name;
            $file = $root.'/'.$name.'.php';
            if (is_file($file) && str_contains((string) file_get_contents($file), AbstractBundle::class)) {
                $found[] = $class;
            }
        }

        $this->assertSame([PHPRegexSymfonyBundle::class], $found);
    }
}

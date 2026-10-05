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

namespace PHPRegex\Tests\Unit\Packages;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Installed on its own, a package lives in vendor/php-regex/<package>/: a path
 * from __DIR__ into a sibling's directory exists in this repository only.
 * Reach a sibling's files through its classes, never through the tree.
 */
final class PackageBoundaryTest extends TestCase
{
    #[Test]
    #[DataProvider('providePackages')]
    public function test_no_package_reaches_into_a_sibling_directory(string $directory): void
    {
        $siblings = array_values(array_diff(self::packageDirectories(), [$directory]));
        $pattern = '~__DIR__\s*\.\s*([\'"])((?:/\.\.)+)/('.implode('|', array_map(static fn (string $sibling): string => preg_quote($sibling, '~'), $siblings)).')\b~';

        $reaches = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src/'.$directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $this->assertInstanceOf(\SplFileInfo::class, $file);
            if ('php' !== $file->getExtension() || str_contains($file->getPathname(), '/Tests/')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            if (preg_match_all($pattern, $code, $matches, \PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$text, $offset]) {
                    $reaches[] = \sprintf('%s:%d %s', substr($file->getPathname(), \strlen(self::root()) + 1), substr_count($code, "\n", 0, $offset) + 1, $text);
                }
            }
        }

        $this->assertSame([], $reaches, \sprintf('src/%s reaches into a sibling package through the tree, which a split install does not have: name the class instead.', $directory));
    }

    /**
     * @return iterable<string, array{directory: string}>
     */
    public static function providePackages(): iterable
    {
        foreach (self::packageDirectories() as $directory) {
            yield $directory => ['directory' => $directory];
        }
    }

    /**
     * @return list<string>
     */
    private static function packageDirectories(): array
    {
        $directories = [];
        foreach (glob(self::root().'/src/*/composer.json') ?: [] as $manifest) {
            $directories[] = basename(\dirname($manifest));
        }
        sort($directories);

        return $directories;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}

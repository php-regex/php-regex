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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The library's strings are Unicode: every package that cuts, measures or
 * checks one itself carries the platform need the code has, so its manifest
 * stands alone — and none declares the extension without calling it.
 */
final class MbstringRequirementTest extends TestCase
{
    private const CALLING = ['Automata', 'Cli', 'Explain', 'Generator', 'LanguageServer', 'Linter', 'Optimizer', 'Parser', 'Redos', 'Transpiler'];

    #[Test]
    public function test_every_package_calling_mbstring_requires_the_extension(): void
    {
        $root = \dirname(__DIR__, 3);

        $calling = [];
        foreach (glob($root.'/src/*', \GLOB_ONLYDIR) ?: [] as $directory) {
            $package = basename($directory);
            $manifest = self::decode($directory.'/composer.json');
            $require = $manifest['require'] ?? [];
            $requires = \is_array($require) && '*' === ($require['ext-mbstring'] ?? null);

            $calls = false;
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()
                    && preg_match('~(?<![\w$])mb_\w+\s*\(~', (string) file_get_contents($file->getPathname()))
                ) {
                    $calls = true;

                    break;
                }
            }

            if ($calls) {
                $calling[] = $package;
                $this->assertTrue($requires, 'src/'.$package.' calls mb_* functions.');
            } else {
                $this->assertFalse($requires, 'src/'.$package.' requires ext-mbstring without calling it.');
            }
        }

        $this->assertSame(self::CALLING, $calling);
    }

    #[Test]
    public function test_the_monorepo_package_requires_the_extension_too(): void
    {
        $manifest = self::decode(\dirname(__DIR__, 3).'/composer.json');
        $require = $manifest['require'] ?? [];

        $this->assertTrue(\is_array($require) && '*' === ($require['ext-mbstring'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data, $path);

        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}

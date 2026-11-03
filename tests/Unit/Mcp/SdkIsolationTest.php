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

namespace PHPRegex\Tests\Unit\Mcp;

use PHPRegex\Parser\Text\Path;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The SDK is experimental and breaks its API between minors: one file of
 * the package speaks to it, so that a change of SDK is a change of one
 * file. The tools, the catalog and the results never see an SDK type.
 */
final class SdkIsolationTest extends TestCase
{
    #[Test]
    public function test_exactly_one_file_of_the_package_imports_the_sdk(): void
    {
        $root = \dirname(__DIR__, 3).'/src/Mcp';
        $importing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            if (1 === preg_match('~(?<![\w\\\\])\\\\?Mcp\\\\[A-Z]~', (string) file_get_contents($file->getPathname()))) {
                $importing[] = Path::fromHost(substr($file->getPathname(), \strlen($root) + 1));
            }
        }

        $this->assertSame(['Sdk/SdkServer.php'], $importing);
    }
}

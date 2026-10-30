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

namespace PHPRegex\Tests\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('documentation')]
final class RedirectAliasesTest extends TestCase
{
    #[Test]
    public function test_legacy_redirect_aliases_never_shadow_a_real_directory(): void
    {
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../docs', \FilesystemIterator::SKIP_DOTS),
        );

        $directoryAliases = [];
        foreach ($directory as $file) {
            if (!$file instanceof \SplFileInfo || !\in_array($file->getExtension(), ['md', 'html'], true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/^redirect_from:\n((?:  - .+\n)+)---/m', $source, $matches);

            foreach ($matches[1] as $aliases) {
                foreach (explode("\n", $aliases) as $alias) {
                    if (preg_match('~^  - (?<alias>/[^?#]+)$~', trim($alias, "\r"), $match) && str_ends_with($match['alias'], '/')) {
                        $directoryAliases[] = $match['alias'].' ('.$file->getPathname().')';
                    }
                }
            }
        }

        sort($directoryAliases);

        $this->assertSame([], $directoryAliases, 'Directory aliases can replace the real page on case-insensitive filesystems.');
    }
}

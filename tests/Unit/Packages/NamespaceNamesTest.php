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
 * A namespace says what its classes do; @internal alone says who may use
 * them, so making a class public is removing the tag, never a move.
 */
final class NamespaceNamesTest extends TestCase
{
    #[Test]
    public function test_no_namespace_under_src_is_named_internal(): void
    {
        $root = \dirname(__DIR__, 3);
        $found = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $path = substr($entry->getPathname(), \strlen($root) + 1);
            if ($entry->isDir() && 'Internal' === $entry->getFilename()) {
                $found[] = $path.'/';
            } elseif ('php' === $entry->getExtension() && 1 === preg_match('~^namespace\s+[\w\\\\]*\\\\Internal\b~m', (string) file_get_contents($entry->getPathname()))) {
                $found[] = $path;
            }
        }
        sort($found);

        $this->assertSame([], $found, 'Name the namespace for what its classes do, and keep @internal on each class.');
    }
}

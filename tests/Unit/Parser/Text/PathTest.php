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

namespace PHPRegex\Tests\Unit\Parser\Text;

use PHPRegex\Parser\Text\Path;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two readings of a backslash in a path. A value a configuration or a
 * report spells is read the same on every host: every backslash is a
 * separator, written "/". A path of the filesystem is read as the host
 * reads it: a backslash is a separator on Windows only, and part of the
 * name elsewhere.
 */
final class PathTest extends TestCase
{
    /**
     * @return iterable<string, array{path: string, expected: string}>
     */
    public static function provideSpelledPaths(): iterable
    {
        yield 'slashes kept' => ['path' => 'src/Legacy/', 'expected' => 'src/Legacy/'];
        yield 'backslashes read as separators' => ['path' => 'src\\Legacy\\', 'expected' => 'src/Legacy/'];
        yield 'a drive path' => ['path' => 'C:\\project\\a.php', 'expected' => 'C:/project/a.php'];
        yield 'mixed separators' => ['path' => 'C:\\project/src\\a.php', 'expected' => 'C:/project/src/a.php'];
        yield 'empty' => ['path' => '', 'expected' => ''];
    }

    #[Test]
    #[DataProvider('provideSpelledPaths')]
    public function test_a_spelled_path_is_normalized_to_slashes_on_every_host(string $path, string $expected): void
    {
        $this->assertSame($expected, Path::normalize($path));
    }

    #[Test]
    public function test_a_path_of_the_host_reads_a_backslash_as_a_separator_on_windows_only(): void
    {
        $this->assertSame('src/a.php', Path::fromHost('src/a.php'));
        $this->assertSame(Path::onWindows() ? 'src/a/b.php' : 'src/a\\b.php', Path::fromHost('src/a\\b.php'));
    }

    #[Test]
    public function test_the_host_is_windows_where_a_backslash_separates(): void
    {
        $this->assertSame('\\' === \DIRECTORY_SEPARATOR, Path::onWindows());
    }
}

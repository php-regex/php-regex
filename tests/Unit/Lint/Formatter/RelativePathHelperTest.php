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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How the reports, the links and the lint banner name a file: from the
 * base path when the file is under it, whichever way the path is written
 * or reached, in full otherwise, with forward slashes.
 */
final class RelativePathHelperTest extends TestCase
{
    use TemporaryProject;

    /**
     * @return iterable<string, array{base: string, path: string, expected: string}>
     */
    public static function providePaths(): iterable
    {
        yield 'under the base' => ['base' => '/base', 'path' => '/base/src/file.php', 'expected' => 'src/file.php'];
        yield 'under a base written with a trailing slash' => ['base' => '/base/', 'path' => '/base/regex.json', 'expected' => 'regex.json'];
        yield 'outside the base' => ['base' => '/base', 'path' => '/other/file.php', 'expected' => '/other/file.php'];
        yield 'beside the base, sharing its prefix' => ['base' => '/base', 'path' => '/baseline/file.php', 'expected' => '/baseline/file.php'];
        yield 'relative to the base' => ['base' => '/base', 'path' => './src//x/../file.php', 'expected' => 'src/file.php'];
        yield 'up out of the base' => ['base' => '/base/app', 'path' => '../lib/file.php', 'expected' => '/base/lib/file.php'];
        yield 'under a base written with dots' => ['base' => '/base/./app/..', 'path' => '/base/src/file.php', 'expected' => 'src/file.php'];
        // A separator on Windows only: elsewhere a backslash is part of the name.
        yield 'a Windows path under a Windows base' => ['base' => 'C:\\root', 'path' => 'C:\\root\\src\\file.php', 'expected' => '\\' === \DIRECTORY_SEPARATOR ? 'src/file.php' : 'C:\\root\\src\\file.php'];
        yield 'a drive path under a drive base' => ['base' => 'C:/root', 'path' => 'C:/root/src/file.php', 'expected' => 'src/file.php'];
        yield 'an empty base' => ['base' => '', 'path' => '/root/file.php', 'expected' => '/root/file.php'];
        yield 'a stream URL' => ['base' => '/base', 'path' => 'phar:///base/a.phar/file.php', 'expected' => 'phar:///base/a.phar/file.php'];
    }

    #[DataProvider('providePaths')]
    public function test_a_file_is_named_from_the_base_when_it_is_under_it(string $base, string $path, string $expected): void
    {
        $this->assertSame($expected, (new RelativePathHelper($base))->getRelativePath($path));
    }

    /**
     * @return iterable<string, array{base: string, path: string, expected: string}>
     */
    public static function provideAbsolutePaths(): iterable
    {
        yield 'a relative path' => ['base' => '/base', 'path' => 'src', 'expected' => '/base/src'];
        yield 'a base written with a trailing slash' => ['base' => '/base/', 'path' => 'src/lib', 'expected' => '/base/src/lib'];
        yield 'a path written with dots, kept as written' => ['base' => '/base', 'path' => './src/../lib', 'expected' => '/base/./src/../lib'];
        yield 'an absolute path' => ['base' => '/base', 'path' => '/other/src', 'expected' => '/other/src'];
        yield 'a drive path' => ['base' => '/base', 'path' => 'C:/root/src', 'expected' => 'C:/root/src'];
        yield 'a Windows drive path' => ['base' => '/base', 'path' => 'C:\\root\\src', 'expected' => '\\' === \DIRECTORY_SEPARATOR ? 'C:\\root\\src' : '/base/C:\\root\\src'];
        yield 'a Windows rooted path' => ['base' => '/base', 'path' => '\\root\\src', 'expected' => '\\' === \DIRECTORY_SEPARATOR ? '\\root\\src' : '/base/\\root\\src'];
        yield 'a stream URL' => ['base' => '/base', 'path' => 'phar:///a.phar/src', 'expected' => 'phar:///a.phar/src'];
        yield 'an empty path' => ['base' => '/base', 'path' => '', 'expected' => ''];
        yield 'a drive letter alone' => ['base' => '/base', 'path' => 'C:', 'expected' => '/base/C:'];
    }

    #[DataProvider('provideAbsolutePaths')]
    public function test_a_relative_path_is_read_from_the_base(string $base, string $path, string $expected): void
    {
        $this->assertSame($expected, RelativePathHelper::absolutize($path, $base));
    }

    /**
     * A base named through a symbolic link, as $PWD may name the working
     * directory, holds the files of the directory it points to.
     */
    public function test_a_file_reached_through_a_link_is_named_from_the_base(): void
    {
        $directory = $this->makeProject(['src/a.php' => "<?php\n"]);
        $link = $this->makeProject().'/link';
        $this->assertTrue(symlink($directory, $link));

        $this->assertSame('src/a.php', (new RelativePathHelper($link))->getRelativePath($directory.'/src/a.php'));
        $this->assertSame('src/a.php', (new RelativePathHelper($directory))->getRelativePath($link.'/src/a.php'));
    }

    /**
     * Without a base, the working directory is the base.
     */
    public function test_the_working_directory_is_the_base_by_default(): void
    {
        $cwd = (string) getcwd();

        $this->assertSame('src/file.php', (new RelativePathHelper())->getRelativePath($cwd.'/src/file.php'));
        $this->assertSame(str_replace('\\', '/', $cwd), (new RelativePathHelper())->getBasePath());
    }
}

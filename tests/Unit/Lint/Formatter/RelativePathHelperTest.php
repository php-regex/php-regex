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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How the reports and the lint banner name a file: from the base path
 * when the file is under it, in full otherwise, with forward slashes.
 */
final class RelativePathHelperTest extends TestCase
{
    /**
     * @return iterable<string, array{base: string, path: string, expected: string}>
     */
    public static function providePaths(): iterable
    {
        yield 'under the base' => ['base' => '/base', 'path' => '/base/src/file.php', 'expected' => 'src/file.php'];
        yield 'under a base written with a trailing slash' => ['base' => '/base/', 'path' => '/base/regex.json', 'expected' => 'regex.json'];
        yield 'outside the base' => ['base' => '/base', 'path' => '/other/file.php', 'expected' => '/other/file.php'];
        yield 'beside the base, sharing its prefix' => ['base' => '/base', 'path' => '/baseline/file.php', 'expected' => '/baseline/file.php'];
        yield 'backslashes' => ['base' => '/base', 'path' => 'C:\\root\\file.php', 'expected' => 'C:/root/file.php'];
        yield 'a Windows path under a Windows base' => ['base' => 'C:\\root', 'path' => 'C:\\root\\src\\file.php', 'expected' => 'src/file.php'];
        yield 'an empty base' => ['base' => '', 'path' => '/root/file.php', 'expected' => '/root/file.php'];
    }

    #[DataProvider('providePaths')]
    public function test_a_file_is_named_from_the_base_when_it_is_under_it(string $base, string $path, string $expected): void
    {
        $this->assertSame($expected, (new RelativePathHelper($base))->getRelativePath($path));
    }
}

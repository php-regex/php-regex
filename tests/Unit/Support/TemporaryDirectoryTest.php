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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The temporary directories the suite writes in and throws away: made under
 * the system's temporary directory, removed with their content, on every
 * host the suite runs on.
 */
final class TemporaryDirectoryTest extends TestCase
{
    #[Test]
    public function test_a_scratch_directory_is_a_fresh_empty_one_under_the_temporary_directory(): void
    {
        $first = TemporaryDirectory::create('php-regex-temporary');
        $second = TemporaryDirectory::create('php-regex-temporary');

        try {
            $this->assertDirectoryExists($first);
            $this->assertDirectoryExists($second);
            $this->assertNotSame($first, $second);
            $this->assertStringStartsWith(Path::normalize((string) realpath(sys_get_temp_dir())).'/php-regex-temporary-', $first);
            $this->assertStringNotContainsString('\\', $first, 'Spelled with "/" on every host.');
            $this->assertSame([], array_diff(scandir($first) ?: [], ['.', '..']));
        } finally {
            TemporaryDirectory::remove($first, $second);
        }

        $this->assertDirectoryDoesNotExist($first);
        $this->assertDirectoryDoesNotExist($second);
    }

    #[Test]
    public function test_removing_takes_the_content_along_and_tolerates_what_is_already_gone(): void
    {
        $directory = TemporaryDirectory::create();
        mkdir($directory.'/a/b/c', 0o777, true);
        file_put_contents($directory.'/a/b/c/file.txt', 'x');
        file_put_contents($directory.'/top.txt', 'y');
        // Read-only, as git writes its objects: removed all the same.
        chmod($directory.'/a/b/c/file.txt', 0o444);
        $file = $directory.'/top.txt';

        TemporaryDirectory::remove($directory);
        $this->assertDirectoryDoesNotExist($directory);

        TemporaryDirectory::remove($directory, $file, sys_get_temp_dir().'/php-regex-never-made-'.bin2hex(random_bytes(4)));
        $this->assertDirectoryDoesNotExist($directory);
    }
}

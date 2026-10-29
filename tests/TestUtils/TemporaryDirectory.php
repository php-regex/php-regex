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

namespace PHPRegex\Tests\TestUtils;

use Symfony\Component\Filesystem\Filesystem;

/**
 * The temporary directories the suite writes in and throws away, on every
 * host it runs on: made under the system's temporary directory, removed
 * with their content, without a shell.
 */
final class TemporaryDirectory
{
    private function __construct() {}

    /**
     * A fresh, empty directory under the temporary directory, named after
     * the prefix and a random suffix.
     */
    public static function create(string $prefix = 'php-regex'): string
    {
        $directory = realpath(sys_get_temp_dir()).\DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($directory);

        return $directory;
    }

    /**
     * Removes files and directories with their content; a path already
     * gone is no error.
     */
    public static function remove(string ...$paths): void
    {
        (new Filesystem())->remove(array_filter($paths, static fn (string $path): bool => '' !== $path));
    }
}

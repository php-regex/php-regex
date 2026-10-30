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

use PHPRegex\Parser\Text\Path;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The temporary directories the suite writes in and throws away, on every
 * host it runs on: made under the system's temporary directory, spelled
 * with "/" as the library spells the paths it reports, removed with their
 * content.
 */
final class TemporaryDirectory
{
    private function __construct() {}

    /**
     * A fresh empty directory, named after the prefix.
     */
    public static function create(string $prefix = 'php-regex'): string
    {
        $directory = Path::fromHost((string) realpath(sys_get_temp_dir())).'/'.$prefix.'-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($directory);

        return $directory;
    }

    /**
     * Removes each path with its content; one already gone is no error.
     * A read-only file (git writes its objects so) goes with the rest.
     */
    public static function remove(string ...$paths): void
    {
        $filesystem = new Filesystem();
        $paths = array_filter($paths, static fn (string $path): bool => '' !== $path && file_exists($path));

        try {
            $filesystem->chmod($paths, 0o700, 0, true);
        } catch (IOException) {
            // A file whose mode cannot change is removed all the same, or
            // named by the removal.
        }

        $filesystem->remove($paths);
    }
}

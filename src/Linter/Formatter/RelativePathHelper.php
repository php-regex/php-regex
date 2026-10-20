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

namespace PHPRegex\Linter\Formatter;

use PHPRegex\Parser\Text\Ascii;

/**
 * How the reports, the links and the lint banner name a file: relative to
 * the base path, with "/" separators, when the file lies under it;
 * absolute otherwise.
 *
 * @internal
 */
final class RelativePathHelper
{
    /**
     * @param string|null $basePath the working directory when null
     */
    public function __construct(private readonly ?string $basePath = null) {}

    public function getRelativePath(string $path): string
    {
        return self::relativize($path, $this->getBasePath());
    }

    /**
     * The base path, with "/" separators on Windows.
     */
    public function getBasePath(): ?string
    {
        $basePath = $this->basePath;
        if (null === $basePath) {
            $cwd = getcwd();
            $basePath = false === $cwd ? null : $cwd;
        }

        return null === $basePath ? null : self::slashes($basePath);
    }

    /**
     * $file relative to $base, with "/" separators, when the file lies
     * under it; absolute otherwise. "./src", "src/", "../project/src" and
     * the absolute path all give the same value, and a base named through
     * a symbolic link holds the files of the directory it points to. A
     * name that is not a path (a stream URL, a route collection) is left as
     * it is, and so is every name when there is no base.
     */
    public static function relativize(string $file, ?string $base): string
    {
        if ('' === $file || null === $base || '' === $base || str_contains($file, '://')) {
            return $file;
        }

        $path = self::slashes($file);
        $base = self::collapse(self::slashes($base));
        $absolute = self::collapse('' !== self::root($path) ? $path : $base.'/'.$path);

        $relative = self::under($absolute, $base);
        if (null !== $relative) {
            return $relative;
        }

        // Through a symbolic link ($PWD may name the working directory by a
        // link, getcwd() never does): compare the resolved paths.
        $resolvedFile = realpath($file);
        $resolvedBase = realpath($base);
        if (false !== $resolvedFile && false !== $resolvedBase) {
            $relative = self::under(self::slashes($resolvedFile), self::slashes($resolvedBase));
            if (null !== $relative) {
                return $relative;
            }
        }

        return $absolute;
    }

    /**
     * The path with "/" separators on Windows; elsewhere a backslash is
     * part of a name.
     */
    private static function slashes(string $path): string
    {
        return '\\' === \DIRECTORY_SEPARATOR ? str_replace('\\', '/', $path) : $path;
    }

    /**
     * The root an absolute path starts with, "/" or a drive ("C:/"); empty
     * for a relative path.
     */
    private static function root(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return '/';
        }

        return \strlen($path) >= 3 && Ascii::isAlpha($path[0]) && ':/' === substr($path, 1, 2) ? substr($path, 0, 3) : '';
    }

    /**
     * "." and empty segments dropped, ".." resolved, without touching the
     * filesystem.
     */
    private static function collapse(string $path): string
    {
        $root = self::root($path);

        $segments = [];
        foreach (explode('/', substr($path, \strlen($root))) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $root.implode('/', $segments);
    }

    private static function under(string $path, string $base): ?string
    {
        $prefix = rtrim($base, '/').'/';

        return str_starts_with($path, $prefix) && \strlen($path) > \strlen($prefix) ? substr($path, \strlen($prefix)) : null;
    }
}

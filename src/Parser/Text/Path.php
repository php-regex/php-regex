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

namespace PHPRegex\Parser\Text;

/**
 * The two readings of a backslash in a path. A value a configuration or a
 * report spells (an exclude, a baseline entry, a file a report names) is
 * read the same on every host: every backslash is a separator, written
 * "/". A path of the filesystem is read as the host reads it: a backslash
 * is a separator on Windows only, and part of the name elsewhere.
 *
 * @internal
 */
final class Path
{
    private function __construct() {}

    /**
     * Whether the host is Windows, where a backslash separates a path.
     */
    public static function onWindows(): bool
    {
        return '\\' === \DIRECTORY_SEPARATOR;
    }

    /**
     * A spelled path with every backslash read as a separator and written
     * "/", whatever the host. The separators only: a "." or ".." segment
     * stays as written.
     */
    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * A path of the filesystem written with "/" separators: a backslash
     * is one on Windows only.
     */
    public static function fromHost(string $path): string
    {
        return self::onWindows() ? self::normalize($path) : $path;
    }
}

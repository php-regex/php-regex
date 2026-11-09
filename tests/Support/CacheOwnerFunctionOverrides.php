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

namespace PHPRegex\Tests\Support;

/**
 * Stands in for fileowner() in the AST file cache and the class-set store,
 * so a test can show them a directory another user owns, and for mkdir()
 * in the class-set store, so a test can have another process make its
 * directory first. Loaded by the bootstrap, before any test runs: PHP
 * keeps the function an unqualified call first resolved to.
 */
final class CacheOwnerFunctionOverrides
{
    public static bool $anotherOwner = false;

    /**
     * Whether another process makes the directory first: mkdir() then
     * finds it made and fails, as it does when it loses that race.
     */
    public static bool $madeFirst = false;

    public static function reset(): void
    {
        self::$anotherOwner = false;
        self::$madeFirst = false;
    }

    public static function mkdir(string $directory, int $permissions, bool $recursive): bool
    {
        if (self::$madeFirst) {
            mkdir($directory, $permissions, $recursive);

            return false;
        }

        return mkdir($directory, $permissions, $recursive);
    }

    public static function fileowner(string $filename): int|false
    {
        $owner = fileowner($filename);

        return self::$anotherOwner && false !== $owner ? $owner + 1 : $owner;
    }
}

namespace PHPRegex\Parser\Cache;

use PHPRegex\Tests\Support\CacheOwnerFunctionOverrides;

function fileowner(string $filename): int|false
{
    return CacheOwnerFunctionOverrides::fileowner($filename);
}

namespace PHPRegex\Parser\Hir;

use PHPRegex\Tests\Support\CacheOwnerFunctionOverrides;

function fileowner(string $filename): int|false
{
    return CacheOwnerFunctionOverrides::fileowner($filename);
}

function mkdir(string $directory, int $permissions = 0o777, bool $recursive = false): bool
{
    return CacheOwnerFunctionOverrides::mkdir($directory, $permissions, $recursive);
}

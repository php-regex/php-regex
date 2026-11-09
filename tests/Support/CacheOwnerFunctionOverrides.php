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
 * so a test can show them a directory another user owns. Loaded by the
 * bootstrap, before any test runs: PHP keeps the function an unqualified
 * call first resolved to.
 */
final class CacheOwnerFunctionOverrides
{
    public static bool $anotherOwner = false;

    public static function reset(): void
    {
        self::$anotherOwner = false;
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

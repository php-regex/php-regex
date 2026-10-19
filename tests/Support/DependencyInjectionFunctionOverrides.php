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
 * Stands in for class_exists() in the Symfony bundle's extension. Loaded by
 * the bootstrap, before any test runs: a function declared once the
 * extension has called class_exists() would never be reached, as PHP keeps
 * the function an unqualified call resolved to.
 */
final class DependencyInjectionFunctionOverrides
{
    public static ?bool $classExistsResult = null;

    public static function reset(): void
    {
        self::$classExistsResult = null;
    }
}

namespace PHPRegex\Symfony\DependencyInjection;

use PHPRegex\Tests\Support\DependencyInjectionFunctionOverrides;

function class_exists(string $class, bool $autoload = true): bool
{
    if (null !== DependencyInjectionFunctionOverrides::$classExistsResult) {
        return DependencyInjectionFunctionOverrides::$classExistsResult;
    }

    return \class_exists($class, $autoload);
}

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
 * Stands in for class_exists() in the Symfony bundle's security reader, so
 * a test can run it as if symfony/yaml were not installed. Loaded by the
 * bootstrap, before any test runs: PHP keeps the function an unqualified
 * call first resolved to.
 */
final class SecurityConfigFunctionOverrides
{
    /**
     * @var list<class-string>
     */
    public static array $missingClasses = [];

    public static function reset(): void
    {
        self::$missingClasses = [];
    }
}

namespace PHPRegex\Symfony\Security;

use PHPRegex\Tests\Support\SecurityConfigFunctionOverrides;

function class_exists(string $class, bool $autoload = true): bool
{
    if (\in_array($class, SecurityConfigFunctionOverrides::$missingClasses, true)) {
        return false;
    }

    return \class_exists($class, $autoload);
}

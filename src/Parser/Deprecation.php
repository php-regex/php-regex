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

namespace PHPRegex\Parser;

/**
 * Raises the runtime notice of a deprecated element, as
 * symfony/deprecation-contracts' trigger_deprecation() does: the same
 * "Since <package> <version>: " prefix and the same silenced
 * E_USER_DEPRECATED, so the deprecation handlers of Symfony and PHPUnit
 * read it, without the packages depending on that one.
 *
 * @internal
 */
final class Deprecation
{
    /**
     * @param string $package the package that deprecates, as "php-regex/regex-parser"
     * @param string $version the minor release that deprecates, as "2.1"
     * @param string $message the message, a sprintf() format when $args are given
     */
    public static function trigger(string $package, string $version, string $message, bool|float|int|string|null ...$args): void
    {
        $prefix = '' !== $package || '' !== $version ? 'Since '.$package.' '.$version.': ' : '';

        @trigger_error($prefix.([] !== $args ? vsprintf($message, $args) : $message), \E_USER_DEPRECATED);
    }
}

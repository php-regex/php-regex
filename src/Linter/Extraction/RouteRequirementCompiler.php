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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * The pattern Symfony's route compiler, which Laravel's router runs too,
 * matches a route requirement with. The compiler strips the requirement's
 * leading ^ or \A and its trailing $ or \z as Route::sanitizeRequirement()
 * does, inserts ?: after every ( no backslash escapes, puts it in a group
 * named after the parameter and matches the path with "{^...$}sD", the host
 * with "{^...$}sDi", plus u under the route's utf8 option.
 *
 * The pattern is the compiled one without the text of the path around the
 * parameter, so the engine and the lint read the requirement as the compiled
 * route reads it: a brace the compiled route's delimiters do not balance, or
 * a backslash left before the group's ), fails here as it fails there.
 *
 * @internal
 */
final class RouteRequirementCompiler
{
    /**
     * The requirement as Route::sanitizeRequirement() leaves it: a trailing
     * $ goes even escaped, and a \z only where it first stands.
     */
    public static function sanitize(string $requirement): string
    {
        if (str_starts_with($requirement, '^')) {
            $requirement = substr($requirement, 1);
        } elseif (str_starts_with($requirement, '\\A')) {
            $requirement = substr($requirement, 2);
        }

        if (str_ends_with($requirement, '$')) {
            return substr($requirement, 0, -1);
        }

        if (\strlen($requirement) - 2 === strpos($requirement, '\\z')) {
            return substr($requirement, 0, -2);
        }

        return $requirement;
    }

    /**
     * The pattern the compiler matches a sanitized requirement with.
     *
     * @param bool $utf8 whether the route has the utf8 option
     * @param bool $host whether the parameter is matched in the host
     */
    public static function compile(string $requirement, string $parameter, bool $utf8, bool $host): string
    {
        return '{^'.self::group($parameter, self::nonCapturing($requirement)).'$}sD'.($host ? 'i' : '').($utf8 ? 'u' : '');
    }

    /**
     * The requirement as RouteCompiler::transformCapturingGroupsToNonCapturings()
     * leaves it: ?: goes after every ( no backslash escapes and no * or ?
     * follows, whether it opens a group or stands in a class or a comment.
     */
    private static function nonCapturing(string $requirement): string
    {
        for ($i = 0; $i < \strlen($requirement); $i++) {
            if ('\\' === $requirement[$i]) {
                $i++;

                continue;
            }

            if ('(' !== $requirement[$i] || !isset($requirement[$i + 2])) {
                continue;
            }

            if ('*' === $requirement[++$i] || '?' === $requirement[$i]) {
                $i++;

                continue;
            }

            $requirement = substr_replace($requirement, '?:', $i, 0);
            $i++;
        }

        return $requirement;
    }

    /**
     * The group the compiler names after the parameter. A requirement on a
     * parameter the path never names is never compiled: its group goes
     * unnamed where the name is none PCRE takes.
     */
    private static function group(string $parameter, string $body): string
    {
        if (1 === LibraryPcre::match('/^[a-zA-Z_]\w{0,31}$/D', $parameter)) {
            return '(?P<'.$parameter.'>'.$body.')';
        }

        return '('.$body.')';
    }
}

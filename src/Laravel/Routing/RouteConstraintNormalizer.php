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

namespace PHPRegex\Laravel\Routing;

use Illuminate\Routing\Route;
use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * The pattern Laravel's router matches a route constraint with. A route
 * compiles through Symfony's RouteCompiler, always under the utf8 option:
 * the constraint loses a leading ^ or \A and a trailing $ or \z, as
 * Route::sanitizeRequirement() does, gets ?: after every ( no backslash
 * escapes, sits in a group named after the parameter, and the path is
 * matched with "{^...$}sDu", the domain with "{^...$}sDiu".
 *
 * The normalized pattern is the compiled one without the text of the path
 * around the parameter, so the engine and the lint read the constraint as
 * the compiled route reads it: a brace the compiled route's delimiters do
 * not balance, or a backslash left before the group's ), fails here as it
 * fails there.
 *
 * @internal
 */
final class RouteConstraintNormalizer
{
    public function normalize(Route $route, string $parameter, string $constraint): string
    {
        $flags = $this->isDomainParameter($route, $parameter) ? 'sDiu' : 'sDu';

        return '{^'.$this->group($parameter, $this->nonCapturing($this->sanitize($constraint))).'$}'.$flags;
    }

    /**
     * The constraint as Route::sanitizeRequirement() leaves it: a trailing
     * $ goes even escaped, and a \z only where it first stands.
     */
    private function sanitize(string $constraint): string
    {
        if (str_starts_with($constraint, '^')) {
            $constraint = substr($constraint, 1);
        } elseif (str_starts_with($constraint, '\\A')) {
            $constraint = substr($constraint, 2);
        }

        if (str_ends_with($constraint, '$')) {
            return substr($constraint, 0, -1);
        }

        if (\strlen($constraint) - 2 === strpos($constraint, '\\z')) {
            return substr($constraint, 0, -2);
        }

        return $constraint;
    }

    /**
     * The constraint as RouteCompiler::transformCapturingGroupsToNonCapturings()
     * leaves it: ?: goes after every ( no backslash escapes and no * or ?
     * follows, whether it opens a group or stands in a class or a comment.
     */
    private function nonCapturing(string $constraint): string
    {
        for ($i = 0; $i < \strlen($constraint); $i++) {
            if ('\\' === $constraint[$i]) {
                $i++;

                continue;
            }

            if ('(' !== $constraint[$i] || !isset($constraint[$i + 2])) {
                continue;
            }

            if ('*' === $constraint[++$i] || '?' === $constraint[$i]) {
                $i++;

                continue;
            }

            $constraint = substr_replace($constraint, '?:', $i, 0);
            $i++;
        }

        return $constraint;
    }

    /**
     * The group the compiler names after the parameter. A constraint on a
     * parameter the path never names is never compiled: its group goes
     * unnamed where the name is none PCRE takes.
     */
    private function group(string $parameter, string $body): string
    {
        if (1 === LibraryPcre::match('/^[a-zA-Z_]\w{0,31}$/D', $parameter)) {
            return '(?P<'.$parameter.'>'.$body.')';
        }

        return '('.$body.')';
    }

    /**
     * A parameter of the domain and not of the path is matched by the host
     * pattern, which the route compiler reads case-insensitively.
     */
    private function isDomainParameter(Route $route, string $parameter): bool
    {
        $domain = $route->getDomain() ?? '';

        return $this->namesParameter($domain, $parameter) && !$this->namesParameter($route->uri(), $parameter);
    }

    private function namesParameter(string $template, string $parameter): bool
    {
        return str_contains($template, '{'.$parameter.'}') || str_contains($template, '{'.$parameter.'?}');
    }
}

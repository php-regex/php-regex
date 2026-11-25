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
use PHPRegex\Linter\Extraction\RouteRequirementCompiler;

/**
 * The pattern Laravel's router matches a route constraint with. A route
 * compiles through Symfony's RouteCompiler, always under the utf8 option:
 * the constraint is sanitized and compiled as RouteRequirementCompiler
 * says, the path matched with "{^...$}sDu", the domain with "{^...$}sDiu".
 * The name of a group goes as symfony/routing drops it, 6.4.51, 7.4.24 and
 * 8.1.12 on: as the compiler the application's router runs does.
 *
 * @internal
 */
final class RouteConstraintNormalizer
{
    private static ?bool $keepsNamedGroups = null;

    public function normalize(Route $route, string $parameter, string $constraint): string
    {
        return RouteRequirementCompiler::compile(RouteRequirementCompiler::sanitize($constraint), $parameter, true, $this->isDomainParameter($route, $parameter), self::keepsNamedGroups());
    }

    /**
     * Whether the router compiles a constraint keeping the name of a group,
     * as symfony/routing does before 6.4.51, 7.4.24 and 8.1.12.
     */
    private static function keepsNamedGroups(): bool
    {
        return self::$keepsNamedGroups ??= str_contains((new Route(['GET'], '{p}', []))->where('p', '(?<n>a)')->toSymfonyRoute()->compile()->getRegex(), '(?<n>');
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

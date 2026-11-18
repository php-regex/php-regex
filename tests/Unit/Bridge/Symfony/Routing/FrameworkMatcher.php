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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Routing;

use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Matcher\RedirectableUrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * The matcher the framework runs: the compiled one, which redirects a GET
 * or HEAD request whose path differs from a route's by a trailing slash.
 */
final class FrameworkMatcher
{
    /**
     * The route a request reaches: the route of the redirect when the
     * matcher redirects, null when no route takes the request.
     *
     * @param array<mixed>|RouteCollection $routes the routes, or what the dumper compiled of them
     */
    public static function reached(array|RouteCollection $routes, string $method, string $path, string $scheme = 'http', string $host = 'localhost'): ?string
    {
        $compiled = $routes instanceof RouteCollection ? (new CompiledUrlMatcherDumper($routes))->getCompiledRoutes() : $routes;
        $matcher = new class($compiled, new RequestContext(method: $method, host: $host, scheme: $scheme)) extends CompiledUrlMatcher implements RedirectableUrlMatcherInterface {
            /**
             * @return array{_route: string}
             */
            public function redirect(string $path, string $route, ?string $scheme = null): array
            {
                return ['_route' => $route];
            }
        };

        try {
            $route = $matcher->match($path)['_route'] ?? null;
        } catch (RoutingException) {
            return null;
        }

        return \is_string($route) ? $route : null;
    }
}

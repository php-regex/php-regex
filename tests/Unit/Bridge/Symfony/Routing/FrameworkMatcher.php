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
        $route = self::match($routes, $method, $path, $scheme, $host)['_route'] ?? null;

        return \is_string($route) ? $route : null;
    }

    /**
     * How the matcher answers a request: "redirect" to a route, "match" of
     * one as is, or "404" when no route takes it.
     *
     * @param array<mixed>|RouteCollection $routes the routes, or what the dumper compiled of them
     */
    public static function answer(array|RouteCollection $routes, string $method, string $path, string $scheme = 'http'): string
    {
        $match = self::match($routes, $method, $path, $scheme, 'localhost');

        return null === $match ? '404' : (isset($match['_redirect']) ? 'redirect' : 'match');
    }

    /**
     * @param array<mixed>|RouteCollection $routes
     *
     * @return array<mixed>|null
     */
    private static function match(array|RouteCollection $routes, string $method, string $path, string $scheme, string $host): ?array
    {
        $compiled = $routes instanceof RouteCollection ? (new CompiledUrlMatcherDumper($routes))->getCompiledRoutes() : $routes;
        $matcher = new class($compiled, new RequestContext(method: $method, host: $host, scheme: $scheme)) extends CompiledUrlMatcher implements RedirectableUrlMatcherInterface {
            /**
             * @return array{_route: string, _redirect: string}
             */
            public function redirect(string $path, string $route, ?string $scheme = null): array
            {
                return ['_route' => $route, '_redirect' => $path];
            }
        };

        try {
            return $matcher->match($path);
        } catch (RoutingException) {
            return null;
        }
    }
}

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

namespace PHPRegex\Symfony\Routing;

/**
 * The requests each route keeps once the routes the matcher tries before
 * it have taken theirs: a route no request reaches is unreachable, by one
 * earlier route or only by several together.
 *
 * @phpstan-type RouteReach array{
 *     name: string,
 *     index: int,
 *     path: string,
 *     reachable: bool|null,
 *     example: array{method: string, scheme: string, host: string, path: string}|null,
 *     takenBy: list<array{name: string, index: int, path: string, example: array{method: string, scheme: string, host: string, path: string}}>,
 *     cumulative: bool,
 *     refused: string|null,
 * }
 *
 * @internal
 */
final class RouteReachabilityReport
{
    /**
     * @param list<RouteReach>   $routes every route read, in the order the matcher tries them
     * @param array<string, int> $stats
     */
    public function __construct(public readonly array $routes, public readonly array $stats) {}

    /**
     * @return list<RouteReach>
     */
    public function unreachable(): array
    {
        return array_values(array_filter($this->routes, static fn (array $route): bool => false === $route['reachable']));
    }

    /**
     * The routes no earlier route alone shadows, only several together.
     *
     * @return list<RouteReach>
     */
    public function cumulativelyShadowed(): array
    {
        return array_values(array_filter($this->routes, static fn (array $route): bool => $route['cumulative']));
    }
}

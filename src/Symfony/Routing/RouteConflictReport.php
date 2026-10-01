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
 * @internal
 *
 * @phpstan-type RouteDescriptor array{name: string, path: string, pathPattern: string, staticPrefix: string, staticSegments: array<int, string>, methods: array<int, string>, schemes: array<int, string>, hostPattern: ?string, hasHostRequirement: bool, hostUnsupported: bool, hasCondition: bool, index: int}
 * @phpstan-type RouteConflict array{
 *     route: RouteDescriptor,
 *     conflict: RouteDescriptor,
 *     type: string,
 *     example: ?string,
 *     equivalent: bool,
 *     methods: array<int, string>,
 *     schemes: array<int, string>,
 *     notes: array<int, string>,
 * }
 * @phpstan-type RouteSkip array{route: string, reason: string}
 */
final readonly class RouteConflictReport
{
    /**
     * @param array<RouteConflict> $conflicts
     * @param array<RouteSkip>     $skippedRoutes
     * @param array<string, int>   $stats
     * @param array<int, string>   $routesWithConditions
     * @param array<int, string>   $routesWithUnsupportedHosts
     */
    public function __construct(
        public array $conflicts,
        public array $skippedRoutes,
        public array $stats,
        public array $routesWithConditions,
        public array $routesWithUnsupportedHosts,
    ) {}
}

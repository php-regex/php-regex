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

use PHPRegex\Symfony\Routing\RouteConflictAnalyzer;
use PHPRegex\Symfony\Routing\RouteReachabilityReport;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The requests each route keeps once the routes the matcher tries before
 * it have taken theirs, read on the method, the scheme, the host and the
 * path at once, and checked against the framework's own matcher.
 *
 * @phpstan-import-type RouteReach from RouteReachabilityReport
 */
final class RouteReachabilityTest extends TestCase
{
    #[Test]
    public function test_two_routes_shadow_a_third_on_its_requirements(): void
    {
        $routes = self::collection([
            'digits' => new Route('/n/{x}', requirements: ['x' => '\d+']),
            'letters' => new Route('/n/{x}', requirements: ['x' => '[a-z]+']),
            'either' => new Route('/n/{x}', requirements: ['x' => '\d+|[a-z]+']),
        ]);

        $report = self::analyzer()->analyzeReachability($routes);
        $either = self::route($report->routes, 'either');

        $this->assertFalse($either['reachable']);
        $this->assertTrue($either['cumulative']);
        $this->assertSame(['digits', 'letters'], array_column($either['takenBy'], 'name'));
        $this->assertSame(1, $report->stats['cumulative']);
        foreach (['/n/1', '/n/a', '/n/123', '/n/zz'] as $path) {
            $this->assertNotSame('either', FrameworkMatcher::reached($routes, 'GET', $path));
        }

        $this->assertSame([], self::analyzer()->analyze($routes, true)->conflicts, 'No pair alone tells it.');
    }

    #[Test]
    public function test_two_routes_shadow_a_third_on_its_methods(): void
    {
        $routes = self::collection([
            'show' => new Route('/c/{id}', methods: ['GET']),
            'update' => new Route('/c/{id}', methods: ['POST']),
            'both' => new Route('/c/{id}', methods: ['GET', 'POST']),
            'put' => new Route('/c/{id}', methods: ['GET', 'PUT']),
        ]);

        $report = self::analyzer()->analyzeReachability($routes);

        $this->assertTrue(self::route($report->routes, 'both')['cumulative']);
        $put = self::route($report->routes, 'put');
        $this->assertTrue($put['reachable']);
        $this->assertNotNull($put['example']);
        $this->assertSame('PUT', $put['example']['method']);
        $this->assertSame('put', FrameworkMatcher::reached($routes, 'PUT', $put['example']['path']));
        foreach (['GET', 'POST', 'HEAD'] as $method) {
            $this->assertNotSame('both', FrameworkMatcher::reached($routes, $method, '/c/1'));
        }
    }

    #[Test]
    public function test_the_example_of_a_reachable_route_reaches_it(): void
    {
        $routes = self::collection([
            'digits' => new Route('/a/{x}', requirements: ['x' => '\d+']),
            'letters' => new Route('/a/{x}', requirements: ['x' => '[a-z]+']),
            'mixed' => new Route('/a/{x}', requirements: ['x' => '[a-z0-9]+']),
            'secure' => new Route('/s', schemes: ['https']),
            'plain' => new Route('/s', schemes: ['http']),
        ]);

        foreach (self::analyzer()->analyzeReachability($routes)->routes as $route) {
            $this->assertTrue($route['reachable'], $route['name']);
            $this->assertNotNull($route['example']);
            $example = $route['example'];
            $this->assertSame($route['name'], FrameworkMatcher::reached($routes, $example['method'], $example['path'], 'a' === $example['scheme'] ? 'http' : $example['scheme']), $route['name'].' takes '.json_encode($example));
        }
    }

    #[Test]
    public function test_a_route_the_redirect_and_a_route_shadow_together(): void
    {
        // "/b" redirects GET "/b/", and "/b/" for POST takes the rest.
        $routes = self::collection([
            'b' => new Route('/b'),
            'b_post' => new Route('/b/', methods: ['POST']),
            'b_slash' => new Route('/b/', methods: ['GET', 'POST']),
        ]);

        $both = self::route(self::analyzer()->analyzeReachability($routes)->routes, 'b_slash');

        $this->assertFalse($both['reachable']);
        $this->assertSame(['b', 'b_post'], array_column($both['takenBy'], 'name'));
        foreach (['GET', 'POST', 'HEAD'] as $method) {
            $this->assertNotSame('b_slash', FrameworkMatcher::reached($routes, $method, '/b/'));
        }
    }

    #[Test]
    public function test_a_route_with_a_condition_shadows_nothing(): void
    {
        $routes = self::collection([
            'digits' => new Route('/n/{x}', requirements: ['x' => '\d+'], condition: 'request.isXmlHttpRequest()'),
            'letters' => new Route('/n/{x}', requirements: ['x' => '[a-z]+']),
            'either' => new Route('/n/{x}', requirements: ['x' => '\d+|[a-z]+']),
        ]);

        $either = self::route(self::analyzer()->analyzeReachability($routes)->routes, 'either');

        $this->assertTrue($either['reachable']);
        $this->assertSame('/n/0', $either['example']['path'] ?? null);
    }

    #[Test]
    public function test_routes_looked_up_by_their_path_come_first(): void
    {
        $routes = self::collection([
            'any' => new Route('/{x}', requirements: ['x' => '\d+']),
            'about' => new Route('/about'),
        ]);

        $report = self::analyzer()->analyzeReachability($routes);

        $this->assertSame(['about', 'any'], array_column($report->routes, 'name'));
        $this->assertSame('about', FrameworkMatcher::reached($routes, 'GET', '/about'));
        $this->assertSame([], $report->unreachable());
    }

    #[Test]
    public function test_hosts_are_read_with_the_request(): void
    {
        $routes = self::collection([
            'api' => new Route('/u/{id}', host: 'api.example.com'),
            'www' => new Route('/u/{id}', host: 'www.example.com'),
            'any' => new Route('/u/{id}', host: '{sub}.example.com', requirements: ['sub' => 'api|www']),
            'other' => new Route('/u/{id}', host: '{sub}.example.com'),
        ]);

        $report = self::analyzer()->analyzeReachability($routes);

        $this->assertTrue(self::route($report->routes, 'any')['cumulative']);
        $other = self::route($report->routes, 'other');
        $this->assertTrue($other['reachable']);
        $this->assertNotNull($other['example']);
        $this->assertSame('other', FrameworkMatcher::reached($routes, 'GET', $other['example']['path'], 'http', $other['example']['host']));
    }

    #[Test]
    public function test_a_route_taking_no_method_is_left_out(): void
    {
        $routes = self::collection([
            'none' => (new Route('/x'))->setMethods(['']),
            'x' => new Route('/x'),
        ]);

        $report = self::analyzer()->analyzeReachability($routes);

        $this->assertSame(['x'], array_column($report->routes, 'name'));
        $this->assertTrue(self::route($report->routes, 'x')['reachable']);
    }

    #[Test]
    public function test_a_static_route_redirects_the_slash_of_a_catch_all_path(): void
    {
        // TODO.md: "/admin" before a catch-all takes GET "/admin/", which the
        // pairwise analysis read as a carve-out.
        $routes = self::collection([
            'admin' => new Route('/admin'),
            'catch_all' => new Route('/{all}', requirements: ['all' => '.*']),
        ]);

        $conflicts = self::analyzer()->analyze($routes, true)->conflicts;

        $this->assertCount(1, $conflicts);
        $this->assertSame('overlap', $conflicts[0]['type']);
        $this->assertSame('/admin/', $conflicts[0]['example']);
        $this->assertTrue($conflicts[0]['redirected']);
        $this->assertSame('redirect', FrameworkMatcher::answer($routes, 'GET', '/admin/'));
        $this->assertSame('admin', FrameworkMatcher::reached($routes, 'GET', '/admin/'));
        $this->assertSame('catch_all', FrameworkMatcher::reached($routes, 'POST', '/admin/'), 'Only GET and HEAD are redirected.');
    }

    #[Test]
    public function test_a_redirect_on_other_schemes_loses_a_path_past_the_shortest(): void
    {
        // TODO.md: the shortest path the earlier route redirects, "/a/",
        // lands on "/a", which the later route serves: the one it loses is
        // "/long/", read as (L ∩ swapped(E)) \ E \ swapped(L).
        $routes = self::collection([
            'early' => new Route('/{x}', requirements: ['x' => 'a|long'], schemes: ['https']),
            'late' => new Route('/{y}', requirements: ['y' => 'a|a/|long/'], schemes: ['http']),
        ]);

        $report = self::analyzer()->analyze($routes, true);

        $this->assertCount(1, $report->conflicts);
        $this->assertSame('/long/', $report->conflicts[0]['example']);
        $this->assertSame(0, $report->stats['skipped_pairs']);
        $this->assertSame('early', FrameworkMatcher::reached($routes, 'GET', '/long/'));
        $this->assertSame('late', FrameworkMatcher::reached($routes, 'GET', '/a'));
    }

    #[Test]
    public function test_a_hundred_routes_are_read_in_time(): void
    {
        $routes = [];
        for ($i = 0; $i < 100; $i++) {
            $routes['r'.$i] = new Route('/r'.$i.'/{id}', requirements: ['id' => '\d+']);
            $routes['s'.$i] = new Route('/r'.$i.'/{slug}', requirements: ['slug' => '[a-z-]+']);
        }

        $routes['both'] = new Route('/r7/{any}', requirements: ['any' => '\d+|[a-z-]+']);

        $start = hrtime(true);
        $report = self::analyzer()->analyzeReachability(self::collection($routes));
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertSame(['both'], array_column($report->cumulativelyShadowed(), 'name'));
        $this->assertLessThan(30.0, $elapsed);
    }

    private static function analyzer(): RouteConflictAnalyzer
    {
        return new RouteConflictAnalyzer(Regex::create());
    }

    /**
     * @param array<string, Route> $routes
     */
    private static function collection(array $routes): RouteCollection
    {
        $collection = new RouteCollection();
        foreach ($routes as $name => $route) {
            $collection->add($name, $route);
        }

        return $collection;
    }

    /**
     * @param list<RouteReach> $routes
     *
     * @return RouteReach
     */
    private static function route(array $routes, string $name): array
    {
        foreach ($routes as $route) {
            if ($name === $route['name']) {
                return $route;
            }
        }

        throw new \LogicException('No route '.$name.'.');
    }
}

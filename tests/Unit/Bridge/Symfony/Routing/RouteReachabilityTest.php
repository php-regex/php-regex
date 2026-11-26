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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * A route ending on a variable whose value may end on the slash takes
     * its path with one more slash too, the "/?" the dumper writes after
     * it: "/{p<admin/>}" keeps "/admin//" after a route that takes "/admin"
     * and redirects "/admin/".
     */
    #[Test]
    public function test_a_route_keeps_the_path_the_matcher_takes_with_one_more_slash(): void
    {
        $routes = self::collection([
            'admin' => new Route('/{x}', requirements: ['x' => 'admin'], methods: ['GET']),
            'slash' => new Route('/{p}', requirements: ['p' => 'admin/'], methods: ['GET']),
        ]);

        $this->assertSame('admin', FrameworkMatcher::reached($routes, 'GET', '/admin/'));
        $this->assertSame('slash', FrameworkMatcher::reached($routes, 'GET', '/admin//'));

        $report = self::analyzer()->analyzeReachability($routes);
        $slash = self::route($report->routes, 'slash');

        $this->assertTrue($slash['reachable']);
        $this->assertSame('/admin//', $slash['example']['path'] ?? null);
        $this->assertSame(['admin'], array_column($slash['takenBy'], 'name'));
        $this->assertSame([], $report->unreachable());
    }

    /**
     * Two routes that take the paths of a third together still leave it the
     * path the matcher takes with one more slash.
     */
    #[Test]
    public function test_two_routes_leave_a_third_the_path_the_matcher_takes_with_one_more_slash(): void
    {
        $routes = self::collection([
            'a' => new Route('/{x}', requirements: ['x' => 'a'], methods: ['GET']),
            'b' => new Route('/{x}', requirements: ['x' => 'b'], methods: ['GET']),
            'either' => new Route('/{p}', requirements: ['p' => 'a/|b/'], methods: ['GET']),
        ]);

        foreach (['/a/' => 'a', '/b/' => 'b', '/a//' => 'either', '/b//' => 'either'] as $path => $winner) {
            $this->assertSame($winner, FrameworkMatcher::reached($routes, 'GET', $path), $path);
        }

        $report = self::analyzer()->analyzeReachability($routes);
        $either = self::route($report->routes, 'either');

        $this->assertTrue($either['reachable']);
        $this->assertFalse($either['cumulative']);
        $this->assertSame('/a//', $either['example']['path'] ?? null);
        $this->assertSame(0, $report->stats['cumulative']);
    }

    /**
     * An earlier route that takes the path with one more slash too leaves
     * the later one nothing.
     */
    #[Test]
    public function test_a_route_loses_the_path_with_one_more_slash_to_a_route_that_takes_it(): void
    {
        $routes = self::collection([
            'both' => new Route('/{x}', requirements: ['x' => 'a|a/'], methods: ['GET']),
            'slash' => new Route('/{y}', requirements: ['y' => 'a/'], methods: ['GET']),
        ]);

        foreach (['/a', '/a/', '/a//'] as $path) {
            $this->assertSame('both', FrameworkMatcher::reached($routes, 'GET', $path), $path);
        }

        $slash = self::route(self::analyzer()->analyzeReachability($routes)->routes, 'slash');

        $this->assertFalse($slash['reachable']);
        $this->assertSame(['both'], array_column($slash['takenBy'], 'name'));
    }

    /**
     * A route ending on a variable that may end on the slash reads a path
     * of its own ending on the slash for the slash when PCRE captured the
     * value without it: it redirects a GET or HEAD request, when it takes
     * GET, and passes any other on to the routes after it. A later route
     * keeps the requests it may pass on.
     *
     * @param array{0: string, 1: list<string>}                 $earlier path, methods
     * @param array{0: string, 1: list<string>}                 $later   path, methods
     * @param list<array{0: string, 1: string, 2: string|null}> $probes  method, path, and the route the request reaches
     */
    #[Test]
    #[DataProvider('provideRoutesReadingAPathForTheSlash')]
    public function test_a_route_keeps_the_requests_an_earlier_one_may_pass_on_for_the_slash(array $earlier, array $later, array $probes, bool $reachable): void
    {
        $routes = self::collection([
            'earlier' => new Route($earlier[0], methods: $earlier[1]),
            'later' => new Route($later[0], methods: $later[1]),
        ]);

        $reached = [];
        foreach ($probes as [$method, $path, $winner]) {
            $reached[] = FrameworkMatcher::reached($routes, $method, $path);
            $this->assertSame($winner, end($reached), $method.' '.$path);
        }
        $this->assertSame($reachable, \in_array('later', $reached, true));

        $route = self::route(self::analyzer()->analyzeReachability($routes)->routes, 'later');

        $this->assertSame($reachable, $route['reachable']);
        if ($reachable) {
            $this->assertNotNull($route['example']);
            $this->assertSame('later', FrameworkMatcher::reached($routes, $route['example']['method'], $route['example']['path']), 'later takes '.json_encode($route['example']));
        }
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>}, later: array{0: string, 1: list<string>}, probes: list<array{0: string, 1: string, 2: string|null}>, reachable: bool}>
     */
    public static function provideRoutesReadingAPathForTheSlash(): iterable
    {
        yield 'a lazy catch-all for any method before a route for any method ending on a slash' => [
            'earlier' => ['/{x<.+?>}', []],
            'later' => ['/a/', []],
            'probes' => [['GET', '/a/', 'earlier'], ['HEAD', '/a/', 'earlier'], ['POST', '/a/', 'later']],
            'reachable' => true,
        ];
        yield 'a route for HEAD alone before a route for HEAD ending on a slash' => [
            'earlier' => ['/files/{p<a|a/>}', ['HEAD']],
            'later' => ['/files/{q<a>}/', ['HEAD']],
            'probes' => [['HEAD', '/files/a', 'earlier'], ['HEAD', '/files/a/', 'later']],
            'reachable' => true,
        ];
        yield 'a lazy catch-all for GET before a route for GET ending on a slash' => [
            'earlier' => ['/{x<.+?>}', ['GET']],
            'later' => ['/a/', ['GET']],
            'probes' => [['GET', '/a/', 'earlier'], ['HEAD', '/a/', 'earlier'], ['GET', '/a', 'earlier']],
            'reachable' => false,
        ];
        yield 'a route whose values end on no slash, for any method, before the same paths' => [
            'earlier' => ['/n/{x<\\d+>}', []],
            'later' => ['/n/{y<\\d+>}', []],
            'probes' => [['GET', '/n/1', 'earlier'], ['POST', '/n/1', 'earlier'], ['GET', '/n/1/', 'earlier'], ['POST', '/n/1/', null]],
            'reachable' => false,
        ];
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

    /**
     * Before symfony/routing 6.4.51, 7.4.24 and 8.1.12, and on 8.0, the
     * dumper may try "app-{sub}.example.com" before an earlier
     * "{sub}.example.com": the earlier route takes nothing from it there,
     * and every request of it since.
     */
    #[Test]
    public function test_a_route_the_dumper_may_try_first_on_a_host_variable_loses_nothing_to_the_earlier_one(): void
    {
        $routes = self::collection([
            'first' => new Route('/other/{id}', host: 'app-{sub}.example.com'),
            'sub' => new Route('/report/{id}', host: '{sub}.example.com'),
            'app' => new Route('/report/{id}', host: 'app-{sub}.example.com'),
        ]);
        $groups = FrameworkMatcher::groupsHostsOnAnyVariable();

        $this->assertSame($groups ? 'app' : 'sub', FrameworkMatcher::reached($routes, 'GET', '/report/1', 'http', 'app-x.example.com'));

        $app = self::route(self::analyzer()->analyzeReachability($routes)->routes, 'app');

        $this->assertSame($groups, $app['reachable']);
        $this->assertSame($groups ? [] : ['sub'], array_column($app['takenBy'], 'name'));
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

    /**
     * A route the solver gives up on has no verdict: it is neither
     * reachable nor unreachable, shadows nothing, and is counted refused.
     */
    #[Test]
    public function test_a_route_the_solver_gives_up_on_has_no_verdict(): void
    {
        $report = self::analyzer()->analyzeReachability(self::collection([
            'long' => new Route('/a/{x}', requirements: ['x' => '[a-z]{6000}']),
            'any' => new Route('/a/{y}'),
        ]));

        $long = self::route($report->routes, 'long');
        $this->assertNull($long['reachable']);
        $this->assertNotNull($long['refused']);
        $this->assertFalse($long['cumulative']);
        $this->assertTrue(self::route($report->routes, 'any')['reachable']);
        $this->assertSame([], $report->unreachable());
        $this->assertSame(0, $report->stats['unreachable']);
        $this->assertSame(1, $report->stats['refused']);
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

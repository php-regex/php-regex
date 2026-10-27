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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\CompiledRoute;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RouteConflictAnalyzerTest extends TestCase
{
    #[Test]
    public function test_the_resolvers_take_the_compiled_route_natively(): void
    {
        foreach (['resolveHostRegex', 'resolveStaticPrefix'] as $method) {
            $type = (new \ReflectionMethod(RouteConflictAnalyzer::class, $method))->getParameters()[0]->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type);

            $this->assertSame(CompiledRoute::class, $type->getName(), $method.' lost its native CompiledRoute type.');
        }
    }

    #[Test]
    public function test_detects_shadowed_route(): void
    {
        $collection = new RouteCollection();

        $generic = new Route('/users/{id}');
        $collection->add('api_user_show', $generic);

        $specific = new Route('/users/list');
        $collection->add('api_user_list', $specific);

        $analyzer = new RouteConflictAnalyzer(Regex::create());
        $report = $analyzer->analyze($collection, true);

        $this->assertSame(1, $report->stats['conflicts']);
        $this->assertSame('shadowed', $report->conflicts[0]['type']);
    }

    #[Test]
    public function test_ignores_disjoint_methods(): void
    {
        $collection = new RouteCollection();

        $getRoute = new Route('/users/{id}');
        $getRoute->setMethods(['GET']);
        $collection->add('api_user_get', $getRoute);

        $postRoute = new Route('/users/{id}');
        $postRoute->setMethods(['POST']);
        $collection->add('api_user_post', $postRoute);

        $analyzer = new RouteConflictAnalyzer(Regex::create());
        $report = $analyzer->analyze($collection, true);

        $this->assertSame(0, $report->stats['conflicts']);
    }

    #[Test]
    public function test_reports_overlap_when_enabled(): void
    {
        $collection = new RouteCollection();

        $generic = new Route('/users/{slug}');
        $generic->setRequirements(['slug' => '(?:admin-[a-m]+|user-[a-z]+)']);
        $collection->add('api_user_generic', $generic);

        $specific = new Route('/users/admin-{slug}');
        $specific->setRequirements(['slug' => '[a-z]+']);
        $collection->add('api_user_admin', $specific);

        $analyzer = new RouteConflictAnalyzer(Regex::create());
        $report = $analyzer->analyze($collection, true);

        $this->assertSame(1, $report->stats['conflicts']);
        $this->assertSame('overlap', $report->conflicts[0]['type']);
        $this->assertSame(1, $report->stats['overlaps']);
    }

    #[Test]
    public function test_filters_overlaps_when_disabled(): void
    {
        $collection = new RouteCollection();

        $generic = new Route('/users/{slug}');
        $generic->setRequirements(['slug' => '(?:admin-[a-m]+|user-[a-z]+)']);
        $collection->add('api_user_generic', $generic);

        $specific = new Route('/users/admin-{slug}');
        $specific->setRequirements(['slug' => '[a-z]+']);
        $collection->add('api_user_admin', $specific);

        $analyzer = new RouteConflictAnalyzer(Regex::create());
        $report = $analyzer->analyze($collection, false);

        $this->assertSame(0, $report->stats['conflicts']);
        $this->assertSame(1, $report->stats['overlaps']);
    }

    /**
     * The router matches in declaration order and stops at the first route
     * that matches: the matcher itself says which route wins each probe,
     * and the analyzer must report the pair the same way round.
     *
     * @param list<array{0: string, 1: string, 2: array<string, string>, 3: bool}> $routes
     * @param array<string, string>                                                $probes
     * @param list<array{0: string, 1: string, 2: string}>                         $conflicts
     */
    #[Test]
    #[DataProvider('provideDeclarationOrderCases')]
    public function test_conflicts_follow_the_declaration_order_the_matcher_uses(array $routes, array $probes, array $conflicts, int $shadowed, int $overlaps): void
    {
        $collection = new RouteCollection();
        foreach ($routes as [$name, $path, $requirements, $utf8]) {
            $collection->add($name, new Route($path, [], $requirements, $utf8 ? ['utf8' => true] : []));
        }

        $matcher = new UrlMatcher($collection, new RequestContext());
        foreach ($probes as $probe => $winner) {
            $this->assertSame($winner, $matcher->match($probe)['_route'], 'The matcher sends '.$probe.' elsewhere.');
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $found = array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        );
        $this->assertSame($conflicts, $found);
        $this->assertSame($shadowed, $report->stats['shadowed']);
        $this->assertSame($overlaps, $report->stats['overlaps']);
        $this->assertSame(\count($conflicts), $report->stats['conflicts']);
    }

    /**
     * @return iterable<string, array{routes: list<array{0: string, 1: string, 2: array<string, string>, 3: bool}>, probes: array<string, string>, conflicts: list<array{0: string, 1: string, 2: string}>, shadowed: int, overlaps: int}>
     */
    public static function provideDeclarationOrderCases(): iterable
    {
        yield 'a slug route declared before an id route that also takes "new"' => [
            'routes' => [
                ['blog_slug', '/blog/{slug}', ['slug' => '[a-z]+'], false],
                ['blog_id', '/blog/{id}', ['id' => '\d+|new'], false],
            ],
            'probes' => ['/blog/new' => 'blog_slug', '/blog/7' => 'blog_id', '/blog/post' => 'blog_slug'],
            'conflicts' => [['overlap', 'blog_slug', 'blog_id']],
            'shadowed' => 0,
            'overlaps' => 1,
        ];

        yield 'the same two routes, the id route declared first' => [
            'routes' => [
                ['blog_id', '/blog/{id}', ['id' => '\d+|new'], false],
                ['blog_slug', '/blog/{slug}', ['slug' => '[a-z]+'], false],
            ],
            'probes' => ['/blog/new' => 'blog_id', '/blog/7' => 'blog_id', '/blog/post' => 'blog_slug'],
            'conflicts' => [['overlap', 'blog_id', 'blog_slug']],
            'shadowed' => 0,
            'overlaps' => 1,
        ];

        yield 'a placeholder route declared before a static one' => [
            'routes' => [
                ['user_show', '/users/{id}', [], false],
                ['user_list', '/users/list', [], false],
            ],
            'probes' => ['/users/list' => 'user_show', '/users/7' => 'user_show'],
            'conflicts' => [['shadowed', 'user_show', 'user_list']],
            'shadowed' => 1,
            'overlaps' => 0,
        ];

        yield 'a static route declared before the placeholder route it carves out' => [
            'routes' => [
                ['user_list', '/users/list', [], false],
                ['user_show', '/users/{id}', [], false],
            ],
            'probes' => ['/users/list' => 'user_list', '/users/7' => 'user_show'],
            'conflicts' => [],
            'shadowed' => 0,
            'overlaps' => 0,
        ];

        yield 'two routes matching the same paths' => [
            'routes' => [
                ['first', '/pages/{name}', ['name' => '[a-z]+'], false],
                ['second', '/pages/{slug}', ['slug' => '[a-z]+'], false],
            ],
            'probes' => ['/pages/about' => 'first'],
            'conflicts' => [['shadowed', 'first', 'second']],
            'shadowed' => 1,
            'overlaps' => 0,
        ];

        yield 'ASCII digits inside a wider class declared first' => [
            'routes' => [
                ['blog_slug', '/blog/{slug}', ['slug' => '[0-9a-z]+'], false],
                ['blog_id', '/blog/{id}', ['id' => '\d+'], false],
            ],
            'probes' => ['/blog/7' => 'blog_slug', '/blog/post' => 'blog_slug'],
            'conflicts' => [['shadowed', 'blog_slug', 'blog_id']],
            'shadowed' => 1,
            'overlaps' => 0,
        ];

        // Under the utf8 option the route regex carries /u, which turns on
        // UCP: \d then matches the Arabic-Indic digits [0-9a-z] leaves out.
        yield 'Unicode digits the wider ASCII class leaves to the later route' => [
            'routes' => [
                ['blog_slug', '/blog/{slug}', ['slug' => '[0-9a-z]+'], true],
                ['blog_id', '/blog/{id}', ['id' => '\d+'], true],
            ],
            'probes' => ['/blog/7' => 'blog_slug', "/blog/\u{0660}" => 'blog_id'],
            'conflicts' => [['overlap', 'blog_slug', 'blog_id']],
            'shadowed' => 0,
            'overlaps' => 1,
        ];

        yield 'Unicode digits declared before the wider ASCII class' => [
            'routes' => [
                ['blog_id', '/blog/{id}', ['id' => '\d+'], true],
                ['blog_slug', '/blog/{slug}', ['slug' => '[0-9a-z]+'], true],
            ],
            'probes' => ['/blog/7' => 'blog_id', "/blog/\u{0660}" => 'blog_id', '/blog/post' => 'blog_slug'],
            'conflicts' => [['overlap', 'blog_id', 'blog_slug']],
            'shadowed' => 0,
            'overlaps' => 1,
        ];

        yield 'requirements that share no path' => [
            'routes' => [
                ['blog_id', '/blog/{id}', ['id' => '\\d+'], false],
                ['blog_slug', '/blog/{slug}', ['slug' => '[a-z]+'], false],
            ],
            'probes' => ['/blog/7' => 'blog_id', '/blog/post' => 'blog_slug'],
            'conflicts' => [],
            'shadowed' => 0,
            'overlaps' => 0,
        ];

        yield 'a disjoint pair before a conflict of the same earlier route' => [
            'routes' => [
                ['blog_id', '/blog/{id}', ['id' => '\\d+'], false],
                ['blog_slug', '/blog/{slug}', ['slug' => '[a-z]+'], false],
                ['blog_number', '/blog/{number}', ['number' => '[0-9]+'], false],
            ],
            'probes' => ['/blog/7' => 'blog_id', '/blog/post' => 'blog_slug'],
            'conflicts' => [['shadowed', 'blog_id', 'blog_number']],
            'shadowed' => 1,
            'overlaps' => 0,
        ];

        yield 'a carved-out pair before a conflict of the same earlier route' => [
            'routes' => [
                ['user_list', '/users/list', [], false],
                ['user_show', '/users/{id}', [], false],
                ['user_list_again', '/users/list', [], false],
            ],
            'probes' => ['/users/list' => 'user_list', '/users/7' => 'user_show'],
            'conflicts' => [['shadowed', 'user_list', 'user_list_again'], ['shadowed', 'user_show', 'user_list_again']],
            'shadowed' => 2,
            'overlaps' => 0,
        ];

        yield 'static prefixes that cannot share a path' => [
            'routes' => [
                ['blog', '/blog/{slug}', [], false],
                ['shop', '/shop/{slug}', [], false],
            ],
            'probes' => ['/blog/a' => 'blog', '/shop/a' => 'shop'],
            'conflicts' => [],
            'shadowed' => 0,
            'overlaps' => 0,
        ];
    }

    #[Test]
    public function test_disjoint_static_prefixes_are_rejected_before_the_solver_runs(): void
    {
        $collection = new RouteCollection();
        $collection->add('blog', new Route('/blog/{slug}'));
        $collection->add('shop', new Route('/shop/{slug}'));
        $collection->add('any', new Route('/{path}', [], ['path' => '.+']));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(3, $report->stats['pairs_total']);
        $this->assertSame(1, $report->stats['pairs_filtered_prefix']);
        $this->assertSame(2, $report->stats['path_intersections']);
    }

    #[Test]
    public function test_overlaps_left_out_of_the_list_are_still_counted(): void
    {
        $collection = new RouteCollection();
        $collection->add('blog_slug', new Route('/blog/{slug}', [], ['slug' => '[a-z]+']));
        $collection->add('blog_id', new Route('/blog/{id}', [], ['id' => '\d+|new']));
        $collection->add('user_show', new Route('/users/{id}'));
        $collection->add('user_list', new Route('/users/list'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, false);

        $this->assertSame([['shadowed', 'user_show', 'user_list']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
        $this->assertSame(1, $report->stats['overlaps']);
        $this->assertSame(1, $report->stats['shadowed']);
    }

    #[Test]
    public function test_a_pair_the_solver_cannot_read_is_counted_as_skipped(): void
    {
        $collection = new RouteCollection();
        // A backreference leaves the regular subset the solver reads.
        $collection->add('echo', new Route('/x/{a}', [], ['a' => '(b)\\2']));
        $collection->add('any', new Route('/x/{b}'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->conflicts);
        $this->assertSame(1, $report->stats['skipped_pairs']);
        $this->assertSame(0, $report->stats['path_subsets']);
    }

    #[Test]
    public function test_the_overlap_note_says_which_route_wins(): void
    {
        $collection = new RouteCollection();
        $collection->add('blog_slug', new Route('/blog/{slug}', [], ['slug' => '[a-z]+']));
        $collection->add('blog_id', new Route('/blog/{id}', [], ['id' => '\d+|new']));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(
            ['The earlier route wins every path both routes match.'],
            $report->conflicts[0]['notes'],
        );
        $this->assertSame('/blog/new', $report->conflicts[0]['example']);
    }

    #[Test]
    public function test_a_route_that_does_not_compile_is_skipped_with_the_reason(): void
    {
        $collection = new RouteCollection();
        $collection->add('broken', new Route('/x/{a}/{a}'));
        $collection->add('fine', new Route('/x/{b}'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount(1, $report->skippedRoutes);
        $this->assertSame('broken', $report->skippedRoutes[0]['route']);
        $this->assertStringStartsWith('Route compile failed: ', $report->skippedRoutes[0]['reason']);
        $this->assertSame(2, $report->stats['routes']);
    }

    /**
     * The router compiles every route to a delimited regex; a route class
     * of the application may hand over anything, and only that route is
     * then left out.
     */
    #[Test]
    public function test_a_compiled_regex_the_parser_refuses_skips_the_route(): void
    {
        $collection = new RouteCollection();
        $collection->add('odd', new CompiledAsRoute('/x', new CompiledRoute('/x', 'no delimiter', [], [])));
        $collection->add('fine', new Route('/x'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount(1, $report->skippedRoutes);
        $this->assertSame('odd', $report->skippedRoutes[0]['route']);
        $this->assertStringStartsWith('Route regex normalization failed: ', $report->skippedRoutes[0]['reason']);
    }

    #[Test]
    public function test_a_host_regex_the_parser_refuses_leaves_the_host_unchecked(): void
    {
        $collection = new RouteCollection();
        $route = new CompiledAsRoute('/x', new CompiledRoute('/x', '{^/x$}sD', [], [], 'no delimiter'));
        $route->setHost('example.com');
        $collection->add('odd_host', $route);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->skippedRoutes);
        $this->assertSame(['odd_host'], $report->routesWithUnsupportedHosts);
    }

    #[Test]
    public function test_the_counts_say_how_far_each_pair_was_compared(): void
    {
        $collection = new RouteCollection();
        $collection->add('first', new Route('/pages/{name}', [], ['name' => '[a-z]+']));
        $collection->add('second', new Route('/pages/{slug}', [], ['slug' => '[a-z]+']));
        $collection->add('number', new Route('/pages/{id}', [], ['id' => '\d+']));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(3, $report->stats['path_intersections']);
        $this->assertSame(1, $report->stats['path_subsets']);
        $this->assertSame(1, $report->stats['equivalent']);
        $this->assertTrue($report->conflicts[0]['equivalent']);
        $this->assertSame(['Equivalent route patterns.', 'Less specific route declared BEFORE more specific route.'], $report->conflicts[0]['notes']);
    }

    #[Test]
    public function test_an_overlap_is_not_marked_equivalent(): void
    {
        $collection = new RouteCollection();
        $collection->add('blog_slug', new Route('/blog/{slug}', [], ['slug' => '[a-z]+']));
        $collection->add('blog_id', new Route('/blog/{id}', [], ['id' => '\d+|new']));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertFalse($report->conflicts[0]['equivalent']);
        $this->assertSame(0, $report->stats['equivalent']);
    }

    /**
     * Two routes on schemes they do not share never answer the same
     * request: the pair is left before the solver runs. The matcher sends
     * each scheme to its own route.
     */
    #[Test]
    public function test_routes_on_disjoint_schemes_are_filtered_before_the_solver_runs(): void
    {
        $collection = new RouteCollection();
        $collection->add('secure', (new Route('/x'))->setSchemes(['https']));
        $collection->add('plain', (new Route('/x'))->setSchemes(['http']));

        $this->assertSame('secure', (new UrlMatcher($collection, new RequestContext(scheme: 'https')))->match('/x')['_route']);
        $this->assertSame('plain', (new UrlMatcher($collection, new RequestContext(scheme: 'http')))->match('/x')['_route']);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->conflicts);
        $this->assertSame(1, $report->stats['pairs_filtered_schemes']);
        $this->assertSame(0, $report->stats['pairs_filtered_methods']);
        $this->assertSame(0, $report->stats['path_intersections']);
    }

    /**
     * A conflict is scoped to the methods and the schemes both routes
     * take; a route that names none takes the other's.
     *
     * @param array{0: list<string>, 1: list<string>} $earlier
     * @param array{0: list<string>, 1: list<string>} $later
     * @param list<string>                            $methods
     * @param list<string>                            $schemes
     */
    #[Test]
    #[DataProvider('provideConflictScopes')]
    public function test_a_conflict_is_scoped_to_what_both_routes_take(array $earlier, array $later, array $methods, array $schemes): void
    {
        $collection = new RouteCollection();
        $collection->add('user_show', (new Route('/users/{id}'))->setMethods($earlier[0])->setSchemes($earlier[1]));
        $collection->add('user_list', (new Route('/users/list'))->setMethods($later[0])->setSchemes($later[1]));

        $context = new RequestContext(method: $methods[0] ?? 'GET', scheme: $schemes[0] ?? 'http');
        $this->assertSame('user_show', (new UrlMatcher($collection, $context))->match('/users/list')['_route']);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount(1, $report->conflicts);
        $this->assertSame('shadowed', $report->conflicts[0]['type']);
        $this->assertSame($methods, $report->conflicts[0]['methods']);
        $this->assertSame($schemes, $report->conflicts[0]['schemes']);
    }

    /**
     * @return iterable<string, array{earlier: array{0: list<string>, 1: list<string>}, later: array{0: list<string>, 1: list<string>}, methods: list<string>, schemes: list<string>}>
     */
    public static function provideConflictScopes(): iterable
    {
        yield 'neither route names any' => [
            'earlier' => [[], []],
            'later' => [[], []],
            'methods' => [],
            'schemes' => [],
        ];
        yield 'both name some, the scope is what they share' => [
            'earlier' => [['GET', 'post'], ['HTTPS', 'http']],
            'later' => [['get', 'PUT'], ['https']],
            'methods' => ['GET'],
            'schemes' => ['https'],
        ];
        yield 'only the later route names some' => [
            'earlier' => [[], []],
            'later' => [['PUT'], ['https']],
            'methods' => ['PUT'],
            'schemes' => ['https'],
        ];
        yield 'only the earlier route names some' => [
            'earlier' => [['PUT'], ['https']],
            'later' => [[], []],
            'methods' => ['PUT'],
            'schemes' => ['https'],
        ];
    }

    /**
     * Two routes whose hosts share no name are compared no further than
     * their hosts; two whose hosts share one are compared on their paths.
     * A route without a host is never held apart by one.
     *
     * @param array<string, string> $probes host => the route the matcher picks for "/x"
     */
    #[Test]
    #[DataProvider('provideHosts')]
    public function test_the_hosts_hold_apart_only_routes_no_host_name_reaches_both(?string $earlierHost, ?string $laterHost, array $probes, int $conflicts, int $hostChecks): void
    {
        $collection = new RouteCollection();
        $earlier = new Route('/x');
        $later = new Route('/x');
        if (null !== $earlierHost) {
            $earlier->setHost($earlierHost);
        }
        if (null !== $laterHost) {
            $later->setHost($laterHost);
        }
        $collection->add('earlier', $earlier);
        $collection->add('later', $later);

        foreach ($probes as $host => $winner) {
            $this->assertSame($winner, (new UrlMatcher($collection, new RequestContext(host: $host)))->match('/x')['_route'], 'The matcher sends '.$host.' elsewhere.');
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount($conflicts, $report->conflicts);
        $this->assertSame($hostChecks, $report->stats['host_checks']);
        $this->assertSame($hostChecks, $report->stats['host_intersections']);
        $this->assertSame(0, $report->stats['host_skipped']);
    }

    /**
     * @return iterable<string, array{earlierHost: string|null, laterHost: string|null, probes: array<string, string>, conflicts: int, hostChecks: int}>
     */
    public static function provideHosts(): iterable
    {
        yield 'two hosts sharing no name' => [
            'earlierHost' => 'a.example.com',
            'laterHost' => 'b.example.com',
            'probes' => ['a.example.com' => 'earlier', 'b.example.com' => 'later'],
            'conflicts' => 0,
            'hostChecks' => 1,
        ];
        yield 'a placeholder host reaching the later one' => [
            'earlierHost' => '{sub}.example.com',
            'laterHost' => 'api.example.com',
            'probes' => ['api.example.com' => 'earlier'],
            'conflicts' => 1,
            'hostChecks' => 1,
        ];
        yield 'only the later route has a host' => [
            'earlierHost' => null,
            'laterHost' => 'api.example.com',
            'probes' => ['api.example.com' => 'earlier'],
            'conflicts' => 1,
            'hostChecks' => 0,
        ];
    }

    /**
     * A host the solver cannot read leaves the pair compared on its paths,
     * counted as a skipped host check.
     */
    #[Test]
    public function test_a_host_the_solver_cannot_read_leaves_the_pair_compared_on_its_paths(): void
    {
        $collection = new RouteCollection();
        // A backreference leaves the regular subset the solver reads.
        $collection->add('echo', (new Route('/x'))->setHost('{sub}.example.com')->setRequirement('sub', '(b)\\2'));
        $collection->add('any', (new Route('/x'))->setHost('{name}.example.com'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(1, $report->stats['host_checks']);
        $this->assertSame(1, $report->stats['host_skipped']);
        $this->assertSame(1, $report->stats['skipped_pairs']);
        $this->assertSame(1, $report->stats['path_intersections']);
        $this->assertCount(1, $report->conflicts);
    }

    /**
     * A host the analysis cannot read is never compared: the pair is
     * compared on its paths, and the conflict says the host was not
     * evaluated. A route can hold a host requirement its compiled route
     * gives no regex for, or a host regex under a flag the solver does
     * not read.
     */
    #[Test]
    #[DataProvider('provideUnreadableHosts')]
    public function test_a_host_the_analysis_cannot_read_is_named_on_the_conflict(?string $hostRegex): void
    {
        $collection = new RouteCollection();
        $collection->add('odd_host', (new CompiledAsRoute('/x', new CompiledRoute('/x', '{^/x$}sD', [], [], $hostRegex)))->setHost('example.com'));
        $collection->add('plain', (new Route('/x'))->setHost('example.com'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(['odd_host'], $report->routesWithUnsupportedHosts);
        $this->assertSame(1, $report->stats['host_checks']);
        $this->assertSame(1, $report->stats['host_skipped']);
        $this->assertSame(0, $report->stats['host_intersections']);
        $this->assertCount(1, $report->conflicts);
        $this->assertSame('Host requirements were not evaluated.', $report->conflicts[0]['notes'][0]);
    }

    /**
     * @return iterable<string, array{hostRegex: string|null}>
     */
    public static function provideUnreadableHosts(): iterable
    {
        yield 'no host regex' => ['hostRegex' => null];
        yield 'an empty host regex' => ['hostRegex' => ''];
        yield 'a host regex under the m flag' => ['hostRegex' => '{^example\.com$}m'];
    }

    /**
     * A path regex under a flag the solver does not read leaves the route
     * out, the flag named.
     */
    #[Test]
    public function test_a_path_regex_under_a_flag_the_solver_does_not_read_skips_the_route(): void
    {
        $collection = new RouteCollection();
        $collection->add('odd', new CompiledAsRoute('/x', new CompiledRoute('/x', '{^/x$}sDxx', [], [])));
        $collection->add('fine', new Route('/x'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([['route' => 'odd', 'reason' => 'Unsupported regex flags: x']], $report->skippedRoutes);
        $this->assertSame(0, $report->stats['pairs_total']);
    }

    /**
     * A route condition is not evaluated: the route is named once, and
     * its conflicts say so. A condition of blanks is none.
     */
    #[Test]
    public function test_a_route_condition_is_named_and_left_unevaluated(): void
    {
        $collection = new RouteCollection();
        $collection->add('guarded', (new Route('/users/{id}'))->setCondition("request.headers.get('X-Admin') == 'yes'"));
        $collection->add('blank', (new Route('/users/list'))->setCondition(" \t\n"));
        $collection->add('also_guarded', (new Route('/users/new'))->setCondition('true'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(['guarded', 'also_guarded'], $report->routesWithConditions);
        $this->assertCount(2, $report->conflicts);
        $this->assertSame(['Route conditions were not evaluated.', 'Less specific route declared BEFORE more specific route.'], $report->conflicts[0]['notes']);
        $this->assertSame('blank', $report->conflicts[0]['conflict']['name']);
    }

    /**
     * The root route has no static segment; a placeholder route taking
     * an empty value declared before it takes "/" first.
     */
    #[Test]
    public function test_the_root_route_is_compared_like_any_other(): void
    {
        $collection = new RouteCollection();
        $collection->add('page', new Route('/{page}', ['page' => ''], ['page' => '.*']));
        $collection->add('home', new Route('/'));

        $this->assertSame('page', (new UrlMatcher($collection, new RequestContext()))->match('/')['_route']);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([['shadowed', 'page', 'home', '/']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name'], $conflict['example']],
            $report->conflicts,
        ));
        $this->assertSame([], $report->conflicts[0]['conflict']['staticSegments']);
    }

    /**
     * The algorithms are read as the configuration writes them, in any
     * case and with blanks around; a name the solver does not know falls
     * back to the default rather than fail the analysis.
     */
    #[Test]
    #[DataProvider('provideAlgorithmNames')]
    public function test_the_algorithm_names_are_read_leniently(string $minimizer, string $determinizer): void
    {
        $collection = new RouteCollection();
        $collection->add('user_show', new Route('/users/{id}'));
        $collection->add('user_list', new Route('/users/list'));

        $report = (new RouteConflictAnalyzer(Regex::create(), $minimizer, null, $determinizer))->analyze($collection, true);

        $this->assertSame([['shadowed', '/users/list']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['example']],
            $report->conflicts,
        ));
    }

    /**
     * @return iterable<string, array{minimizer: string, determinizer: string}>
     */
    public static function provideAlgorithmNames(): iterable
    {
        yield 'spelled in capitals, with blanks around' => ['minimizer' => " MOORE\t", 'determinizer' => ' SUBSET '];
        yield 'names the solver does not know' => ['minimizer' => 'bogus', 'determinizer' => 'bogus'];
    }
}

/**
 * A route of the application that compiles to what it is given.
 */
final class CompiledAsRoute extends Route
{
    public function __construct(string $path, private readonly CompiledRoute $compiled)
    {
        parent::__construct($path);
    }

    #[\Override]
    public function compile(): CompiledRoute
    {
        return $this->compiled;
    }
}

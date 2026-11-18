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
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
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

        // Only the second route carries the utf8 option, so /u: its "[^/]++"
        // takes "Ā" as one character, the first one as its two bytes.
        yield 'a route read as bytes declared before the same route read as UTF-8' => [
            'routes' => [
                ['bytes', '/users/{id}', [], false],
                ['characters', '/users/{id}', [], true],
            ],
            'probes' => ['/users/7' => 'bytes', "/users/\u{100}" => 'bytes'],
            'conflicts' => [['shadowed', 'bytes', 'characters']],
            'shadowed' => 1,
            'overlaps' => 0,
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

    /**
     * The regex the matchers try on a GET request swaps the "$" of the
     * route's regex for "/?$": a regex of the application's own without a
     * "$" is compared as given, with no redirect.
     */
    #[Test]
    public function test_a_compiled_regex_without_an_end_anchor_is_compared_as_given(): void
    {
        $collection = new RouteCollection();
        $collection->add('odd', new CompiledAsRoute('/x', new CompiledRoute('/x', '{^/x}sD', [], [])));
        $collection->add('fine', new Route('/x/'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->skippedRoutes);
        $this->assertSame([], $report->conflicts);
        $this->assertSame(1, $report->stats['path_intersections']);
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
     * Two routes on schemes they do not share, the earlier one taking no
     * GET request and so redirecting none, never answer the same request:
     * the pair is left before the solver runs. The matcher sends each
     * scheme to its own route.
     */
    #[Test]
    public function test_routes_on_disjoint_schemes_are_filtered_before_the_solver_runs(): void
    {
        $collection = new RouteCollection();
        $collection->add('secure', (new Route('/x'))->setMethods(['POST'])->setSchemes(['https']));
        $collection->add('plain', (new Route('/x'))->setMethods(['POST'])->setSchemes(['http']));

        $this->assertSame('secure', (new UrlMatcher($collection, new RequestContext(method: 'POST', scheme: 'https')))->match('/x')['_route']);
        $this->assertSame('plain', (new UrlMatcher($collection, new RequestContext(method: 'POST', scheme: 'http')))->match('/x')['_route']);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->conflicts);
        $this->assertSame(1, $report->stats['pairs_filtered_schemes']);
        $this->assertSame(0, $report->stats['pairs_filtered_methods']);
        $this->assertSame(0, $report->stats['path_intersections']);
    }

    /**
     * A conflict is scoped to the methods and the schemes both routes
     * take; a route that names none takes the other's. The later route is
     * shadowed when the earlier one takes all its methods and schemes too,
     * an overlap when it keeps some.
     *
     * @param array{0: list<string>, 1: list<string>} $earlier
     * @param array{0: list<string>, 1: list<string>} $later
     * @param list<string>                            $methods
     * @param list<string>                            $schemes
     */
    #[Test]
    #[DataProvider('provideConflictScopes')]
    public function test_a_conflict_is_scoped_to_what_both_routes_take(array $earlier, array $later, array $methods, array $schemes, string $type): void
    {
        $collection = new RouteCollection();
        $collection->add('user_show', (new Route('/users/{id}'))->setMethods($earlier[0])->setSchemes($earlier[1]));
        $collection->add('user_list', (new Route('/users/list'))->setMethods($later[0])->setSchemes($later[1]));

        $context = new RequestContext(method: $methods[0] ?? 'GET', scheme: $schemes[0] ?? 'http');
        $this->assertSame('user_show', (new UrlMatcher($collection, $context))->match('/users/list')['_route']);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount(1, $report->conflicts);
        $this->assertSame($type, $report->conflicts[0]['type']);
        $this->assertSame($methods, $report->conflicts[0]['methods']);
        $this->assertSame($schemes, $report->conflicts[0]['schemes']);
    }

    /**
     * @return iterable<string, array{earlier: array{0: list<string>, 1: list<string>}, later: array{0: list<string>, 1: list<string>}, methods: list<string>, schemes: list<string>, type: string}>
     */
    public static function provideConflictScopes(): iterable
    {
        yield 'neither route names any' => [
            'earlier' => [[], []],
            'later' => [[], []],
            'methods' => [],
            'schemes' => [],
            'type' => 'shadowed',
        ];
        // PUT /users/list goes to the later route.
        yield 'both name some, the scope is what they share' => [
            'earlier' => [['GET', 'post'], ['HTTPS', 'http']],
            'later' => [['get', 'PUT'], ['https']],
            'methods' => ['GET'],
            'schemes' => ['https'],
            'type' => 'overlap',
        ];
        yield 'only the later route names some' => [
            'earlier' => [[], []],
            'later' => [['PUT'], ['https']],
            'methods' => ['PUT'],
            'schemes' => ['https'],
            'type' => 'shadowed',
        ];
        // GET /users/list goes to the later route.
        yield 'only the earlier route names some' => [
            'earlier' => [['PUT'], ['https']],
            'later' => [[], []],
            'methods' => ['PUT'],
            'schemes' => ['https'],
            'type' => 'overlap',
        ];
    }

    /**
     * Methods or schemes given as [''] are not "any": Symfony's matchers,
     * the UrlMatcher and the compiled one, send no request to such a
     * route, whatever its method (GET, HEAD, POST, PUT) or scheme. The
     * route takes no request, so it holds no other route back and no other
     * route holds it back.
     *
     * @param array{0: list<string>, 1: list<string>} $earlier
     * @param array{0: list<string>, 1: list<string>} $later
     */
    #[Test]
    #[DataProvider('provideRoutesTakingNoRequest')]
    public function test_a_route_given_only_empty_methods_or_schemes_takes_no_request(array $earlier, array $later, string $winner, string $filtered): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route('/x'))->setMethods($earlier[0])->setSchemes($earlier[1]));
        $collection->add('later', (new Route('/x'))->setMethods($later[0])->setSchemes($later[1]));

        $compiled = (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes();
        foreach (['GET', 'HEAD', 'POST', 'PUT'] as $method) {
            foreach (['http', 'https'] as $scheme) {
                $context = new RequestContext(method: $method, scheme: $scheme);
                $this->assertSame($winner, (new UrlMatcher($collection, $context))->match('/x')['_route'], $method.' '.$scheme);
                $this->assertSame($winner, (new CompiledUrlMatcher($compiled, $context))->match('/x')['_route'], $method.' '.$scheme.', compiled');
            }
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->conflicts);
        $this->assertSame(1, $report->stats[$filtered]);
        $this->assertSame(0, $report->stats['path_intersections']);
    }

    /**
     * @return iterable<string, array{earlier: array{0: list<string>, 1: list<string>}, later: array{0: list<string>, 1: list<string>}, winner: string, filtered: string}>
     */
    public static function provideRoutesTakingNoRequest(): iterable
    {
        yield 'empty methods before any' => ['earlier' => [[''], []], 'later' => [[], []], 'winner' => 'later', 'filtered' => 'pairs_filtered_methods'];
        yield 'empty schemes before any' => ['earlier' => [[], ['']], 'later' => [[], []], 'winner' => 'later', 'filtered' => 'pairs_filtered_schemes'];
        yield 'any before empty methods' => ['earlier' => [[], []], 'later' => [[''], []], 'winner' => 'earlier', 'filtered' => 'pairs_filtered_methods'];
        yield 'any before empty schemes' => ['earlier' => [[], []], 'later' => [[], ['']], 'winner' => 'earlier', 'filtered' => 'pairs_filtered_schemes'];
    }

    /**
     * An empty method beside a real one leaves the real one: ['GET', '']
     * takes GET (and HEAD), which the matcher sends to it first.
     */
    #[Test]
    public function test_an_empty_method_beside_a_real_one_leaves_the_real_one(): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route('/x'))->setMethods(['GET', 'POST', '']));
        $collection->add('later', (new Route('/x'))->setMethods(['GET', 'PUT']));

        $this->assertSame('earlier', (new UrlMatcher($collection, new RequestContext(method: 'GET')))->match('/x')['_route']);
        $this->assertSame('later', (new UrlMatcher($collection, new RequestContext(method: 'PUT')))->match('/x')['_route']);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount(1, $report->conflicts);
        $this->assertSame(['GET'], $report->conflicts[0]['methods']);
    }

    /**
     * Symfony's matchers send a HEAD request to a route for GET: of a route
     * for GET and a route for HEAD only, the earlier one takes the HEAD
     * requests of both. The compiled matcher, which the framework runs, does
     * so on every release; the UrlMatcher sends none to a route for HEAD
     * only before symfony/routing 7.4.22 and 8.1.10, which read the HEAD
     * request as GET alone. A route for GET behind a route for HEAD only
     * keeps its GET requests: an overlap, not a shadow.
     *
     * @param list<string> $earlier
     * @param list<string> $later
     */
    #[Test]
    #[DataProvider('provideHeadRoutes')]
    public function test_a_route_for_get_takes_the_head_requests(array $earlier, array $later, ?string $type): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route('/x'))->setMethods($earlier));
        $collection->add('later', (new Route('/x'))->setMethods($later));

        $context = new RequestContext(method: 'HEAD');
        $this->assertSame('earlier', (new CompiledUrlMatcher((new CompiledUrlMatcherDumper($collection))->getCompiledRoutes(), $context))->match('/x')['_route']);
        $this->assertSame(['HEAD'] === $earlier && !self::urlMatcherSendsHeadToARouteForHeadOnly() ? 'later' : 'earlier', (new UrlMatcher($collection, $context))->match('/x')['_route']);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount(null === $type ? 0 : 1, $report->conflicts);
        if (null !== $type) {
            $this->assertSame($type, $report->conflicts[0]['type']);
            $this->assertSame(['HEAD'], $report->conflicts[0]['methods']);
        }
        $this->assertSame(0, $report->stats['pairs_filtered_methods']);
    }

    /**
     * @return iterable<string, array{earlier: list<string>, later: list<string>, type: string|null}>
     */
    public static function provideHeadRoutes(): iterable
    {
        yield 'a route for HEAD only behind a route for GET' => ['earlier' => ['GET'], 'later' => ['HEAD'], 'type' => 'shadowed'];
        // Its requests all among the later route's: a carve-out.
        yield 'a route for GET behind a route for HEAD only' => ['earlier' => ['HEAD'], 'later' => ['GET'], 'type' => null];
        yield 'a route for HEAD only behind a route for GET and POST' => ['earlier' => ['post', 'get'], 'later' => ['head'], 'type' => 'shadowed'];
    }

    /**
     * Of two routes on the same paths, the later one is shadowed only when
     * the earlier one takes every request of it: its methods (a route for
     * GET takes HEAD), its schemes and its host. An earlier route whose
     * requests are all among the later one's is a carve-out of it, which
     * is not reported. Otherwise each route keeps some requests, as the
     * compiled matcher shows, and the pair is an overlap.
     *
     * @param array{methods?: list<string>, schemes?: list<string>, host?: string} $earlier
     * @param array{methods?: list<string>, schemes?: list<string>, host?: string} $later
     * @param list<array{0: string, 1: string, 2: string, 3: string}>              $probes  method, scheme, host, and the route the matcher picks for "/x"
     */
    #[Test]
    #[DataProvider('provideRequestScopes')]
    public function test_a_route_is_shadowed_only_when_an_earlier_one_takes_every_request_of_it(array $earlier, array $later, array $probes, ?string $type): void
    {
        $collection = new RouteCollection();
        foreach (['earlier' => $earlier, 'later' => $later] as $name => $scope) {
            $route = (new Route('/x'))->setMethods($scope['methods'] ?? [])->setSchemes($scope['schemes'] ?? []);
            if (isset($scope['host'])) {
                $route->setHost($scope['host']);
            }
            $collection->add($name, $route);
        }

        $compiled = (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes();
        foreach ($probes as [$method, $scheme, $host, $winner]) {
            $context = new RequestContext(method: $method, host: $host, scheme: $scheme);
            $this->assertSame($winner, (new CompiledUrlMatcher($compiled, $context))->match('/x')['_route'], $method.' '.$scheme.'://'.$host);
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(null === $type ? [] : [[$type, 'earlier', 'later']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
        $this->assertSame('shadowed' === $type ? 1 : 0, $report->stats['shadowed']);
        $this->assertSame('overlap' === $type ? 1 : 0, $report->stats['overlaps']);
    }

    /**
     * @return iterable<string, array{earlier: array{methods?: list<string>, schemes?: list<string>, host?: string}, later: array{methods?: list<string>, schemes?: list<string>, host?: string}, probes: list<array{0: string, 1: string, 2: string, 3: string}>, type: string|null}>
     */
    public static function provideRequestScopes(): iterable
    {
        yield 'a route for HEAD only before a route for GET' => [
            'earlier' => ['methods' => ['HEAD']],
            'later' => ['methods' => ['GET']],
            'probes' => [['HEAD', 'http', 'localhost', 'earlier'], ['GET', 'http', 'localhost', 'later']],
            'type' => null,
        ];
        yield 'a route for GET before a route for GET and POST' => [
            'earlier' => ['methods' => ['GET']],
            'later' => ['methods' => ['GET', 'POST']],
            'probes' => [['GET', 'http', 'localhost', 'earlier'], ['POST', 'http', 'localhost', 'later']],
            'type' => null,
        ];
        yield 'a route for GET and POST before a route for GET and PUT' => [
            'earlier' => ['methods' => ['GET', 'POST']],
            'later' => ['methods' => ['GET', 'PUT']],
            'probes' => [['GET', 'http', 'localhost', 'earlier'], ['POST', 'http', 'localhost', 'earlier'], ['PUT', 'http', 'localhost', 'later']],
            'type' => 'overlap',
        ];
        yield 'a route for https only, for GET and POST, before a route for any scheme, for GET' => [
            'earlier' => ['methods' => ['GET', 'POST'], 'schemes' => ['https']],
            'later' => ['methods' => ['GET']],
            'probes' => [['GET', 'https', 'localhost', 'earlier'], ['GET', 'http', 'localhost', 'later']],
            'type' => 'overlap',
        ];
        yield 'a route for GET and POST before a route for GET' => [
            'earlier' => ['methods' => ['GET', 'POST']],
            'later' => ['methods' => ['GET']],
            'probes' => [['GET', 'http', 'localhost', 'earlier'], ['HEAD', 'http', 'localhost', 'earlier']],
            'type' => 'shadowed',
        ];
        yield 'a route for https only before a route for any scheme' => [
            'earlier' => ['schemes' => ['https']],
            'later' => [],
            'probes' => [['GET', 'https', 'localhost', 'earlier'], ['GET', 'http', 'localhost', 'later']],
            'type' => null,
        ];
        yield 'a route for any scheme before a route for https only' => [
            'earlier' => [],
            'later' => ['schemes' => ['https']],
            'probes' => [['GET', 'https', 'localhost', 'earlier']],
            'type' => 'shadowed',
        ];
        yield 'a route for a host before a route for any host' => [
            'earlier' => ['host' => 'admin.example.com'],
            'later' => [],
            'probes' => [['GET', 'http', 'admin.example.com', 'earlier'], ['GET', 'http', 'shop.example.com', 'later']],
            'type' => null,
        ];
        yield 'a route for a host before a route for a host it holds' => [
            'earlier' => ['host' => '{sub}.example.com'],
            'later' => ['host' => 'admin.example.com'],
            'probes' => [['GET', 'http', 'admin.example.com', 'earlier']],
            'type' => 'shadowed',
        ];
        yield 'a route for a host before a route for a wider host' => [
            'earlier' => ['host' => 'admin.example.com'],
            'later' => ['host' => '{sub}.example.com'],
            'probes' => [['GET', 'http', 'admin.example.com', 'earlier'], ['GET', 'http', 'shop.example.com', 'later']],
            'type' => null,
        ];
    }

    /**
     * The framework's matcher redirects a GET or HEAD request whose path
     * differs from a route's by a trailing slash alone to that route's
     * path, and tries no later route: for those requests, the earlier
     * route takes the paths of the later one. A later route that takes
     * only GET and HEAD requests is then shadowed; one that takes others
     * keeps them, an overlap. An earlier route that takes no GET request
     * redirects none.
     *
     * @param array{0: string, 1: list<string>}                 $earlier
     * @param array{0: string, 1: list<string>}                 $later
     * @param list<array{0: string, 1: string, 2: string|null}> $probes  method, path, and the route the request reaches, redirected or not
     */
    #[Test]
    #[DataProvider('provideTrailingSlashPairs')]
    public function test_a_get_request_redirected_for_its_trailing_slash_goes_to_the_earlier_route(array $earlier, array $later, array $probes, ?string $type): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route($earlier[0]))->setMethods($earlier[1]));
        $collection->add('later', (new Route($later[0]))->setMethods($later[1]));

        $compiled = (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes();
        foreach ($probes as [$method, $path, $winner]) {
            $this->assertSame($winner, FrameworkMatcher::reached($compiled, $method, $path), $method.' '.$path);
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(null === $type ? [] : [[$type, 'earlier', 'later']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
        if (null !== $type) {
            $this->assertContains('GET and HEAD requests differing by a trailing slash are redirected to the earlier route.', $report->conflicts[0]['notes']);
            $this->assertIsString($report->conflicts[0]['example']);
            $this->assertSame('earlier', FrameworkMatcher::reached($compiled, 'GET', $report->conflicts[0]['example']));
        }
    }

    /**
     * An overlap through the redirect is counted and left out of the list
     * as any other, a shadow listed.
     */
    #[Test]
    public function test_a_trailing_slash_overlap_left_out_of_the_list_is_still_counted(): void
    {
        $collection = new RouteCollection();
        $collection->add('slash', new Route('/foo/'));
        $collection->add('no_slash', new Route('/foo'));
        $collection->add('slash_for_get', (new Route('/bar/'))->setMethods(['GET']));
        $collection->add('no_slash_for_get', (new Route('/bar'))->setMethods(['GET']));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, false);

        $this->assertSame(1, $report->stats['overlaps']);
        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame([['shadowed', 'slash_for_get', 'no_slash_for_get']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>}, later: array{0: string, 1: list<string>}, probes: list<array{0: string, 1: string, 2: string|null}>, type: string|null}>
     */
    public static function provideTrailingSlashPairs(): iterable
    {
        yield 'a route with a trailing slash before the same route without' => [
            'earlier' => ['/foo/', []],
            'later' => ['/foo', []],
            'probes' => [['GET', '/foo', 'earlier'], ['HEAD', '/foo', 'earlier'], ['POST', '/foo', 'later'], ['GET', '/foo/', 'earlier']],
            'type' => 'overlap',
        ];
        yield 'a route with a trailing slash before the same route without, for GET only' => [
            'earlier' => ['/foo/', []],
            'later' => ['/foo', ['GET']],
            'probes' => [['GET', '/foo', 'earlier'], ['HEAD', '/foo', 'earlier']],
            'type' => 'shadowed',
        ];
        yield 'a route without a trailing slash before the same route with one' => [
            'earlier' => ['/foo', []],
            'later' => ['/foo/', []],
            'probes' => [['GET', '/foo/', 'earlier'], ['POST', '/foo/', 'later']],
            'type' => 'overlap',
        ];
        yield 'a placeholder route with a trailing slash before one without, for GET and HEAD' => [
            'earlier' => ['/a/{x}/', []],
            'later' => ['/a/{x}', ['GET', 'HEAD']],
            'probes' => [['GET', '/a/b', 'earlier'], ['HEAD', '/a/b', 'earlier']],
            'type' => 'shadowed',
        ];
        yield 'a route for GET before the same route without its slash, for HEAD only' => [
            'earlier' => ['/foo/', ['GET']],
            'later' => ['/foo', ['HEAD']],
            'probes' => [['HEAD', '/foo', 'earlier']],
            'type' => 'shadowed',
        ];
        yield 'an earlier route for POST only redirects nothing' => [
            'earlier' => ['/foo/', ['POST']],
            'later' => ['/foo', []],
            'probes' => [['GET', '/foo', 'later'], ['POST', '/foo/', 'earlier']],
            'type' => null,
        ];
        yield 'a later route for POST only is never redirected' => [
            'earlier' => ['/foo/', []],
            'later' => ['/foo', ['POST']],
            'probes' => [['POST', '/foo', 'later'], ['GET', '/foo', 'earlier']],
            'type' => null,
        ];
        yield 'paths that differ by more than a trailing slash' => [
            'earlier' => ['/foo/', []],
            'later' => ['/foobar', []],
            'probes' => [['GET', '/foobar', 'later'], ['GET', '/foo', 'earlier']],
            'type' => null,
        ];
        // The slash variant of a path carved out of a catch-all is the
        // router's normalisation, not a conflict.
        yield 'a route carved out of a catch-all, whose slash variant the redirect takes' => [
            'earlier' => ['/admin', ['GET']],
            'later' => ['/{path<.*>}', []],
            'probes' => [['GET', '/admin/', 'earlier'], ['HEAD', '/admin/', 'earlier'], ['POST', '/admin/', 'later'], ['PUT', '/admin/', 'later'], ['POST', '/admin', 'later'], ['GET', '/other', 'later']],
            'type' => null,
        ];
    }

    /**
     * The matcher checks the trailing slash of a route before its scheme:
     * an earlier route for GET on other schemes still stops a GET or HEAD
     * request whose path differs from its own by the slash alone, and
     * tries no later route. Older releases then answer that no route
     * matches, newer ones redirect to the earlier route on its scheme;
     * neither sends the request to the later route. A later route taking
     * only GET and HEAD is shadowed, one taking others an overlap. On the
     * paths both routes match, each scheme still reaches its own route.
     *
     * @param array{0: string, 1: list<string>, 2: list<string>}    $earlier path, methods, schemes
     * @param array{0: string, 1: list<string>, 2: list<string>}    $later   path, methods, schemes
     * @param list<array{0: string, 1: string, 2: string, 3: bool}> $probes  method, scheme, path, and whether the later route takes the request
     */
    #[Test]
    #[DataProvider('provideTrailingSlashPairsOnDisjointSchemes')]
    public function test_a_trailing_slash_redirect_crosses_disjoint_schemes(array $earlier, array $later, array $probes, ?string $type, int $filtered): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route($earlier[0]))->setMethods($earlier[1])->setSchemes($earlier[2]));
        $collection->add('later', (new Route($later[0]))->setMethods($later[1])->setSchemes($later[2]));

        $compiled = (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes();
        foreach ($probes as [$method, $scheme, $path, $laterTakesIt]) {
            $this->assertSame($laterTakesIt, 'later' === FrameworkMatcher::reached($compiled, $method, $path, $scheme), $method.' '.$scheme.' '.$path);
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(null === $type ? [] : [[$type, 'earlier', 'later']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
        $this->assertSame($filtered, $report->stats['pairs_filtered_schemes']);
        if (null !== $type) {
            $this->assertTrue($report->conflicts[0]['redirected']);
            $this->assertSame($later[2], $report->conflicts[0]['schemes']);
            $this->assertIsString($report->conflicts[0]['example']);
            $this->assertNotSame('later', FrameworkMatcher::reached($compiled, 'GET', $report->conflicts[0]['example'], $later[2][0] ?? 'http'));
        }
    }

    /**
     * An earlier route ending on a variable is matched, slash or no slash,
     * and redirected when the variable PCRE captured does not end on the
     * slash: which path it redirects is PCRE's ordered choice, not a
     * language the solver reads. Beside paths it shares with a later route
     * on another scheme, the pair is left out as one the solver gives up
     * on, rather than judged on one example.
     *
     * @param array{0: string, 1: list<string>, 2: list<string>}    $earlier
     * @param array{0: string, 1: list<string>, 2: list<string>}    $later
     * @param list<array{0: string, 1: string, 2: string, 3: bool}> $probes
     */
    #[Test]
    #[DataProvider('provideEarlierRoutesEndingOnAVariable')]
    public function test_an_earlier_route_ending_on_a_variable_is_left_out_beside_shared_paths_on_disjoint_schemes(array $earlier, array $later, array $probes): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route($earlier[0]))->setMethods($earlier[1])->setSchemes($earlier[2]));
        $collection->add('later', (new Route($later[0]))->setMethods($later[1])->setSchemes($later[2]));

        $compiled = (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes();
        foreach ($probes as [$method, $scheme, $path, $laterTakesIt]) {
            $this->assertSame($laterTakesIt, 'later' === FrameworkMatcher::reached($compiled, $method, $path, $scheme), $method.' '.$scheme.' '.$path);
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->conflicts);
        $this->assertSame(1, $report->stats['skipped_pairs']);
    }

    /**
     * An earlier route ending on a variable no value of which ends on the
     * slash, the default "[^/]++", "\d+" or "[a-z]+", redirects every GET
     * or HEAD request whose path differs from one of its own by a trailing
     * slash, whatever order PCRE tries the requirement in: beside paths it
     * shares with a later route on another scheme, the pair is read as for
     * a route ending on no variable. A requirement a value of which may end
     * on the slash, ".+" or "[^?]+", or be empty, which the matcher reads as
     * ending on it, leaves the redirect to that order and the pair skipped.
     * On the same scheme, the paths both routes match decide the pair.
     *
     * @param array{0: string, 1: list<string>, 2: list<string>}    $earlier path, methods, schemes
     * @param array{0: string, 1: list<string>, 2: list<string>}    $later   path, methods, schemes
     * @param list<array{0: string, 1: string, 2: string, 3: bool}> $probes  method, scheme, path, and whether the later route takes the request
     */
    #[Test]
    #[DataProvider('provideRequirementsOfTheVariableAnEarlierRouteEndsOn')]
    public function test_the_redirect_of_an_earlier_route_ending_on_a_variable_is_read_where_no_value_of_it_ends_on_the_slash(array $earlier, array $later, array $probes, ?string $type, int $skipped): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route($earlier[0]))->setMethods($earlier[1])->setSchemes($earlier[2]));
        $collection->add('later', (new Route($later[0]))->setMethods($later[1])->setSchemes($later[2]));

        $compiled = (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes();
        foreach ($probes as [$method, $scheme, $path, $laterTakesIt]) {
            $this->assertSame($laterTakesIt, 'later' === FrameworkMatcher::reached($compiled, $method, $path, $scheme), $method.' '.$scheme.' '.$path);
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(null === $type ? [] : [[$type, 'earlier', 'later']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
        $this->assertSame($skipped, $report->stats['skipped_pairs']);
        if (null !== $type) {
            $this->assertIsString($report->conflicts[0]['example']);
            $this->assertNotSame('later', FrameworkMatcher::reached($compiled, 'GET', $report->conflicts[0]['example'], $later[2][0] ?? 'http'));
        }
    }

    /**
     * A route of the application whose compiled route names no variable,
     * though its path ends on one, leaves the requirement unknown: beside
     * shared paths on another scheme, the pair is skipped.
     */
    #[Test]
    public function test_an_earlier_route_whose_compiled_route_names_no_variable_is_left_out_beside_shared_paths_on_disjoint_schemes(): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new CompiledAsRoute('/{x}', new CompiledRoute('/', '{^/(?P<x>[^/]++)$}sD', [], [])))->setMethods(['GET'])->setSchemes(['https']));
        $collection->add('later', (new Route('/{y<a|b/>}'))->setMethods(['GET'])->setSchemes(['http']));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->conflicts);
        $this->assertSame(1, $report->stats['skipped_pairs']);
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>, 2: list<string>}, later: array{0: string, 1: list<string>, 2: list<string>}, probes: list<array{0: string, 1: string, 2: string, 3: bool}>, type: string|null, skipped: int}>
     */
    public static function provideRequirementsOfTheVariableAnEarlierRouteEndsOn(): iterable
    {
        yield 'the default requirement, on https before a route on http that loses a path to the redirect' => [
            'earlier' => ['/files/{name}', ['GET'], ['https']],
            'later' => ['/files/{p<a|b/>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a', true], ['GET', 'http', '/files/b/', false], ['HEAD', 'http', '/files/b/', false]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        yield 'the default requirement before a trailing slash, on https before a route on http that loses a path to the redirect' => [
            'earlier' => ['/files/{name}/', ['GET'], ['https']],
            'later' => ['/files/{p<a/|b>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a/', true], ['GET', 'http', '/files/b', false], ['HEAD', 'http', '/files/b', false]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        yield 'a custom requirement, on https before a route on http that loses a path to the redirect' => [
            'earlier' => ['/page/{n<\d+>}', ['GET'], ['https']],
            'later' => ['/page/{p<1|2/>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/page/1', true], ['GET', 'http', '/page/2/', false], ['HEAD', 'http', '/page/2/', false]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        yield 'a custom requirement before a trailing slash, on https before a route on http that loses a path to the redirect' => [
            'earlier' => ['/tag/{t<[a-z]+>}/', ['GET'], ['https']],
            'later' => ['/tag/{p<a/|b>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/tag/a/', true], ['GET', 'http', '/tag/b', false], ['HEAD', 'http', '/tag/b', false]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        yield 'the default requirement, on https before a route on http that matches every path of it' => [
            'earlier' => ['/files/{name}', ['GET'], ['https']],
            'later' => ['/files/{path<.+>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a', true], ['GET', 'http', '/files/a/', true], ['HEAD', 'http', '/files/a/', true]],
            'type' => null,
            'skipped' => 0,
        ];
        // The shortest path the redirect takes leads to a path the later
        // route serves; another may not, which no question on two
        // languages tells: the pair is left out rather than reported.
        yield 'the default requirement, on https before a route on http that serves the path the shortest redirect leads to' => [
            'earlier' => ['/files/{name}', ['GET'], ['https']],
            'later' => ['/files/{path<[a-z/]+>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a/', true], ['HEAD', 'http', '/files/a/', true], ['GET', 'http', '/files/ab/', true]],
            'type' => null,
            'skipped' => 1,
        ];
        yield 'the default requirement, on https before a route on http that serves the path the shortest redirect leads to and loses another' => [
            'earlier' => ['/files/{name}', ['GET'], ['https']],
            'later' => ['/files/{path<a/|a|b/>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a/', true], ['HEAD', 'http', '/files/a/', true], ['GET', 'http', '/files/b/', false]],
            'type' => null,
            'skipped' => 1,
        ];
        // A value captured with the slash is matched as is, on the earlier
        // route's schemes; one captured without it is redirected, on any:
        // which of the two is PCRE's order.
        yield 'a requirement that can end on the slash, on https before a route on http whose paths it matches only with the slash' => [
            'earlier' => ['/{x<a/?>}', ['GET'], ['https']],
            'later' => ['/{y<a//>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/a//', true], ['HEAD', 'http', '/a//', true]],
            'type' => null,
            'skipped' => 1,
        ];
        yield 'a requirement that can end on the slash, on https before a route on https and http whose paths it matches only with the slash' => [
            'earlier' => ['/{x<a/?>}', ['GET'], ['https']],
            'later' => ['/{y<a//>}', ['GET'], ['https', 'http']],
            'probes' => [['GET', 'https', '/a//', false], ['GET', 'http', '/a//', true]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        yield 'a requirement that can end on the slash, before a route whose paths it matches only with the slash' => [
            'earlier' => ['/{x<a/?>}', ['GET'], []],
            'later' => ['/{y<a//>}', ['GET'], []],
            'probes' => [['GET', 'http', '/a//', false], ['HEAD', 'http', '/a//', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        // A path ending on the slash whose value PCRE captured without it
        // is redirected for GET and HEAD, and passed on to the later route
        // for any other method.
        yield 'a lazy requirement that can end on the slash, before a route for any method ending on a slash' => [
            'earlier' => ['/files/{path<.+?>}', [], []],
            'later' => ['/files/{name}/', [], []],
            'probes' => [['GET', 'http', '/files/a/', false], ['HEAD', 'http', '/files/a/', false], ['POST', 'http', '/files/a/', true]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        // The greedy requirement captures the slash and takes every request,
        // which its language does not tell from the lazy one: an overlap,
        // never a shadow the lazy one refutes.
        yield 'a greedy requirement that can end on the slash, before a route for any method ending on a slash' => [
            'earlier' => ['/files/{path<.+>}', [], []],
            'later' => ['/files/{name}/', [], []],
            'probes' => [['GET', 'http', '/files/a/', false], ['POST', 'http', '/files/a/', false]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        // The matcher never reads "/" for its trailing slash.
        yield 'a requirement that can be empty, before the root route for any method' => [
            'earlier' => ['/{page<.*?>}', [], []],
            'later' => ['/', [], []],
            'probes' => [['GET', 'http', '/', false], ['POST', 'http', '/', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'a lazy requirement that can end on the slash, before a route for GET ending on a slash' => [
            'earlier' => ['/files/{path<.+?>}', [], []],
            'later' => ['/files/{name}/', ['GET'], []],
            'probes' => [['GET', 'http', '/files/a/', false], ['HEAD', 'http', '/files/a/', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'a requirement that can end on the slash, on https before a route on http' => [
            'earlier' => ['/files/{path<.+>}', ['GET'], ['https']],
            'later' => ['/files/{name}/', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a/', true], ['HEAD', 'http', '/files/a/', true]],
            'type' => null,
            'skipped' => 1,
        ];
        yield 'a requirement that can end on the slash before a trailing slash, on https before a route on http' => [
            'earlier' => ['/files/{path<[^?]+>}/', ['GET'], ['https']],
            'later' => ['/files/{p<.+>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a', true], ['GET', 'http', '/files/a/', true]],
            'type' => null,
            'skipped' => 1,
        ];
        // The matcher reads an empty value as ending on the slash: "/f/" is
        // matched with an empty name, not redirected.
        yield 'a requirement that can be empty, on https before a route on http' => [
            'earlier' => ['/f{name<[a-z]*>}', ['GET'], ['https']],
            'later' => ['/f{p</?>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/f/', true], ['GET', 'http', '/f', true]],
            'type' => null,
            'skipped' => 1,
        ];
        yield 'the default requirement before a route that matches every path of it' => [
            'earlier' => ['/files/{name}', ['GET'], []],
            'later' => ['/files/{path<.+>}', ['GET'], []],
            'probes' => [['GET', 'http', '/files/a', false], ['GET', 'http', '/files/a/', false], ['GET', 'http', '/files/a/b', true]],
            'type' => null,
            'skipped' => 0,
        ];
        yield 'the default requirement before a trailing slash, before a route that matches every path of it' => [
            'earlier' => ['/files/{name}/', ['GET'], []],
            'later' => ['/files/{path<.+>}', ['GET'], []],
            'probes' => [['GET', 'http', '/files/a', false], ['GET', 'http', '/files/a/', false], ['GET', 'http', '/files/a/b', true]],
            'type' => null,
            'skipped' => 0,
        ];
        yield 'a custom requirement, before a route whose every path it redirects' => [
            'earlier' => ['/page/{n<\d+>}', ['GET'], []],
            'later' => ['/page/{p<\d+/>}', ['GET'], []],
            'probes' => [['GET', 'http', '/page/1/', false], ['HEAD', 'http', '/page/1/', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'a custom requirement before a trailing slash, before a route whose every path it redirects' => [
            'earlier' => ['/tag/{t<[a-z]+>}/', ['GET'], []],
            'later' => ['/tag/{p<[a-z]+>}', ['GET'], []],
            'probes' => [['GET', 'http', '/tag/a', false], ['HEAD', 'http', '/tag/a', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'a requirement that can end on the slash, before a route whose every path it matches' => [
            'earlier' => ['/files/{path<.+>}', ['GET'], []],
            'later' => ['/files/{name}/', ['GET'], []],
            'probes' => [['GET', 'http', '/files/a/', false], ['HEAD', 'http', '/files/a/', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'a requirement that can end on the slash before a trailing slash, before a route that matches every path of it' => [
            'earlier' => ['/files/{path<[^?]+>}/', ['GET'], []],
            'later' => ['/files/{p<.+>}', ['GET'], []],
            'probes' => [['GET', 'http', '/files/a', false], ['GET', 'http', '/files/a/', false], ['GET', 'http', '/files/a?b', true]],
            'type' => null,
            'skipped' => 0,
        ];
    }

    /**
     * The compiled matcher looks a route with no variable up by its path,
     * every trailing slash of the request trimmed off, before it tries any
     * route with a regex. A route ending on a slash takes its path with any
     * number of slashes as is, and redirects it with none; any other takes
     * its path, and redirects it with any number of slashes, "/" answering
     * them with a 404. A route matched with a regex takes its path with one
     * slash at most.
     *
     * @param array{0: string, 1: list<string>, 2: list<string>}    $earlier path, methods, schemes
     * @param array{0: string, 1: list<string>, 2: list<string>}    $later   path, methods, schemes
     * @param list<array{0: string, 1: string, 2: string, 3: bool}> $probes  method, scheme, path, and whether the later route takes the request
     */
    #[Test]
    #[DataProvider('provideRoutesLookedUpByTheirPath')]
    public function test_a_route_looked_up_by_its_path_takes_it_with_any_number_of_trailing_slashes(array $earlier, array $later, array $probes, ?string $type, int $skipped): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route($earlier[0]))->setMethods($earlier[1])->setSchemes($earlier[2]));
        $collection->add('later', (new Route($later[0]))->setMethods($later[1])->setSchemes($later[2]));

        $compiled = (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes();
        foreach ($probes as [$method, $scheme, $path, $laterTakesIt]) {
            $this->assertSame($laterTakesIt, 'later' === FrameworkMatcher::reached($compiled, $method, $path, $scheme), $method.' '.$scheme.' '.$path);
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(null === $type ? [] : [[$type, 'earlier', 'later']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
        $this->assertSame($skipped, $report->stats['skipped_pairs']);
        if (null !== $type) {
            $this->assertIsString($report->conflicts[0]['example']);
            $this->assertNotSame('later', FrameworkMatcher::reached($compiled, 'GET', $report->conflicts[0]['example'], $later[2][0] ?? 'http'));
        }
    }

    /**
     * A route with no variable is matched with a regex, one slash at most,
     * when a route declared before it that is matched with a regex matches
     * its path: the dumper keeps it among the static paths otherwise.
     *
     * @param list<string> $conflicts the conflicts, as "type earlier later"
     */
    #[Test]
    #[DataProvider('provideRoutesDeclaredBeforeOneWithNoVariable')]
    public function test_a_route_with_no_variable_is_matched_with_a_regex_after_one_matching_its_path(string $first, ?string $winner, array $conflicts): void
    {
        $collection = new RouteCollection();
        $collection->add('first', (new Route($first))->setMethods(['POST']));
        $collection->add('earlier', (new Route('/admin'))->setMethods(['GET']));
        $collection->add('later', (new Route('/{p<admin//>}'))->setMethods(['GET']));

        $this->assertSame($winner, FrameworkMatcher::reached($collection, 'GET', '/admin//'));
        $this->assertSame('earlier', FrameworkMatcher::reached($collection, 'GET', '/admin'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame($conflicts, array_map(
            static fn (array $conflict): string => $conflict['type'].' '.$conflict['route']['name'].' '.$conflict['conflict']['name'],
            $report->conflicts,
        ));
    }

    /**
     * The dumper reads the hosts too: a route with no variable on a host
     * an earlier route tried with a regex does not match stays looked up
     * by its path.
     */
    #[Test]
    #[DataProvider('provideHostsOfARouteWithNoVariable')]
    public function test_a_route_with_no_variable_is_matched_with_a_regex_after_one_matching_its_path_on_its_host(string $earlierHost, string $laterHost, string $winner, int $conflicts): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route('/{x<admin>}'))->setMethods(['GET'])->setHost($earlierHost));
        $collection->add('later', (new Route('/admin'))->setMethods(['GET'])->setHost($laterHost));

        $this->assertSame($winner, FrameworkMatcher::reached($collection, 'GET', '/admin', 'http', $laterHost));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame($conflicts, $report->stats['shadowed']);
        $this->assertCount($conflicts, $report->conflicts);
    }

    /**
     * @return iterable<string, array{earlierHost: string, laterHost: string, winner: string, conflicts: int}>
     */
    public static function provideHostsOfARouteWithNoVariable(): iterable
    {
        yield 'the same host' => ['earlierHost' => 'a.example.com', 'laterHost' => 'a.example.com', 'winner' => 'earlier', 'conflicts' => 1];
        yield 'a host the earlier route matches with a variable' => ['earlierHost' => '{sub}.example.com', 'laterHost' => 'a.example.com', 'winner' => 'earlier', 'conflicts' => 1];
        yield 'another host' => ['earlierHost' => 'a.example.com', 'laterHost' => 'b.example.com', 'winner' => 'later', 'conflicts' => 0];
    }

    /**
     * When a route names a host, the dumper sorts the routes by the literal
     * end of their host, letter case included, and may move a route before
     * an earlier one whose literal end parts from its own. No host matches
     * both, unless they part on letter case alone, which hosts ignore: the
     * later route may then answer first, and the pair is skipped.
     *
     * @param array{0: string, 1: string} $host the hosts of the second and the third route
     */
    #[Test]
    #[DataProvider('provideHostsTheDumperMaySort')]
    public function test_two_routes_whose_hosts_part_on_letter_case_alone_are_skipped(array $host, string $winner, int $conflicts, int $skipped): void
    {
        $collection = new RouteCollection();
        $collection->add('first', (new Route('/p'))->setHost('{sub}.a.com'));
        $collection->add('earlier', (new Route('/p'))->setHost($host[0]));
        $collection->add('later', (new Route('/p'))->setHost($host[1]));

        $this->assertSame($winner, FrameworkMatcher::reached($collection, 'GET', '/p', 'http', 'a.com'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount($conflicts, $report->conflicts);
        $this->assertSame($skipped, $report->stats['skipped_pairs']);
    }

    /**
     * @return iterable<string, array{host: array{0: string, 1: string}, winner: string, conflicts: int, skipped: int}>
     */
    public static function provideHostsTheDumperMaySort(): iterable
    {
        yield 'hosts parting on letter case alone' => ['host' => ['A.COM', 'a.com'], 'winner' => 'later', 'conflicts' => 0, 'skipped' => 1];
        yield 'the same host' => ['host' => ['a.com', 'a.com'], 'winner' => 'earlier', 'conflicts' => 1, 'skipped' => 0];
    }

    /**
     * A later route ending on a variable that may end on the slash, with
     * no slash after it, reads a path of its own ending on the slash for
     * the slash, as PCRE captures the value: it redirects a GET or HEAD
     * request of it, and passes any other on. A request it passes on is
     * none it loses, and a path it redirects is no path it serves.
     *
     * @param array{0: string, 1: list<string>, 2: list<string>}    $earlier path, methods, schemes
     * @param array{0: string, 1: list<string>, 2: list<string>}    $later   path, methods, schemes
     * @param list<array{0: string, 1: string, 2: string, 3: bool}> $probes  method, scheme, path, and whether the later route takes the request
     * @param list<array{0: string, 1: string, 2: string, 3: bool}> $alone   the same, the later route alone
     */
    #[Test]
    #[DataProvider('provideLaterRoutesReadingTheirOwnPathsForTheSlash')]
    public function test_a_later_route_reading_its_own_paths_for_the_slash_loses_none_it_would_not_serve(array $earlier, array $later, array $probes, array $alone, ?string $type, int $skipped): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route($earlier[0]))->setMethods($earlier[1])->setSchemes($earlier[2]));
        $collection->add('later', (new Route($later[0]))->setMethods($later[1])->setSchemes($later[2]));
        $laterAlone = new RouteCollection();
        $laterAlone->add('later', (new Route($later[0]))->setMethods($later[1])->setSchemes($later[2]));

        foreach ($probes as [$method, $scheme, $path, $laterTakesIt]) {
            $this->assertSame($laterTakesIt, 'later' === FrameworkMatcher::reached($collection, $method, $path, $scheme), $method.' '.$scheme.' '.$path);
        }
        foreach ($alone as [$method, $scheme, $path, $laterTakesIt]) {
            $this->assertSame($laterTakesIt, 'later' === FrameworkMatcher::reached($laterAlone, $method, $path, $scheme), $method.' '.$scheme.' '.$path.', alone');
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(null === $type ? [] : [[$type, 'earlier', 'later']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
        $this->assertSame($skipped, $report->stats['skipped_pairs']);
        if (null !== $type) {
            $this->assertIsString($report->conflicts[0]['example']);
            $this->assertNotSame('later', FrameworkMatcher::reached($collection, $earlier[1][0] ?? 'GET', $report->conflicts[0]['example'], $later[2][0] ?? 'http'));
            $this->assertSame('later', FrameworkMatcher::reached($laterAlone, $earlier[1][0] ?? 'GET', $report->conflicts[0]['example'], $later[2][0] ?? 'http'));
        }
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>, 2: list<string>}, later: array{0: string, 1: list<string>, 2: list<string>}, probes: list<array{0: string, 1: string, 2: string, 3: bool}>, alone: list<array{0: string, 1: string, 2: string, 3: bool}>, type: string|null, skipped: int}>
     */
    public static function provideLaterRoutesReadingTheirOwnPathsForTheSlash(): iterable
    {
        yield 'for POST, the shortest shared path the later route passes on' => [
            'earlier' => ['/{x<a/|c>}', ['POST'], []],
            'later' => ['/{y<a|a/|b>}', ['POST'], []],
            'probes' => [['POST', 'http', '/a/', false]],
            'alone' => [['POST', 'http', '/a/', false], ['POST', 'http', '/b', true]],
            'type' => null,
            'skipped' => 1,
        ];
        yield 'for GET, the shortest shared path the later route redirects to a path of its own' => [
            'earlier' => ['/{x<a/|c>}', ['GET'], []],
            'later' => ['/{y<a|a/|b>}', ['GET'], []],
            'probes' => [['GET', 'http', '/a/', false]],
            'alone' => [['GET', 'http', '/a/', true]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        yield 'for POST, the shortest shared path the later route takes as is' => [
            'earlier' => ['/{x<a/|c|d>}', ['POST'], []],
            'later' => ['/{y<a|a/|d|b>}', ['POST'], []],
            'probes' => [['POST', 'http', '/d', false]],
            'alone' => [['POST', 'http', '/d', true]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        // The redirect leads to a path the later route reads for its own
        // slash: it sends the request back to the earlier route's scheme.
        yield 'a route on https before a route on http that redirects the path the redirect leads to' => [
            'earlier' => ['/admin/', ['GET'], ['https']],
            'later' => ['/{p<admin|admin/>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/admin', false], ['HEAD', 'http', '/admin', false]],
            'alone' => [['GET', 'http', '/admin', true]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        // The shortest redirect leads to a path the later route takes as
        // is, another to one it reads for its slash.
        yield 'a route on https before a route on http that matches every path of it and reads some for the slash' => [
            'earlier' => ['/files/{name}/', ['GET'], ['https']],
            'later' => ['/files/{p<[^b]/|.+?>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a', true], ['GET', 'http', '/files/b', false]],
            'alone' => [['GET', 'http', '/files/b', true]],
            'type' => null,
            'skipped' => 1,
        ];
    }

    /**
     * A conflict the trailing slash makes notes what the matcher does with
     * a GET request of its example: it redirects it to the earlier route,
     * matches it as is under a variable whose captured value ends on the
     * slash, or answers "/" with more slashes with a 404.
     *
     * @param array{0: string, 1: list<string>, 2: list<string>} $earlier path, methods, schemes
     * @param array{0: string, 1: list<string>, 2: list<string>} $later   path, methods, schemes
     */
    #[Test]
    #[DataProvider('provideAnswersToTheSlash')]
    public function test_a_conflict_of_the_trailing_slash_notes_what_the_matcher_does(array $earlier, array $later, string $answer, string $note): void
    {
        $collection = new RouteCollection();
        $collection->add('earlier', (new Route($earlier[0]))->setMethods($earlier[1])->setSchemes($earlier[2]));
        $collection->add('later', (new Route($later[0]))->setMethods($later[1])->setSchemes($later[2]));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertCount(1, $report->conflicts);
        $this->assertIsString($report->conflicts[0]['example']);
        $this->assertSame($answer, FrameworkMatcher::answer($collection, 'GET', $report->conflicts[0]['example'], $earlier[2][0] ?? 'http'));
        $this->assertContains($note, $report->conflicts[0]['notes']);
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>, 2: list<string>}, later: array{0: string, 1: list<string>, 2: list<string>}, answer: string, note: string}>
     */
    public static function provideAnswersToTheSlash(): iterable
    {
        yield 'a path the earlier route redirects' => [
            'earlier' => ['/foo/', [], []],
            'later' => ['/foo', ['GET'], []],
            'answer' => 'redirect',
            'note' => 'GET and HEAD requests differing by a trailing slash are redirected to the earlier route.',
        ];
        yield '"/" with more slashes' => [
            'earlier' => ['/', ['GET'], []],
            'later' => ['/{p</+>}', ['GET'], []],
            'answer' => '404',
            'note' => 'The earlier route answers GET and HEAD requests for "/" followed by more slashes with a 404.',
        ];
        yield 'a path the earlier route matches as is' => [
            'earlier' => ['/{x<a/?>}', ['GET'], []],
            'later' => ['/{y<a//>}', ['GET'], []],
            'answer' => 'match',
            'note' => 'GET and HEAD requests differing by a trailing slash are matched as is by the earlier route, as PCRE captures its last variable.',
        ];
        yield 'slashes alone the earlier route matches as is under a slash after its variable' => [
            'earlier' => ['/{x<.+>}/', ['GET'], []],
            'later' => ['/{y</>}', ['GET'], []],
            'answer' => 'match',
            'note' => 'GET and HEAD requests differing by a trailing slash are matched as is by the earlier route, as PCRE captures its last variable.',
        ];
        yield 'a path the earlier route matches as is on its scheme' => [
            'earlier' => ['/{x<a/?>}', ['GET'], ['https']],
            'later' => ['/{y<a//>}', ['GET'], ['https', 'http']],
            'answer' => 'match',
            'note' => 'GET and HEAD requests differing by a trailing slash are matched as is by the earlier route, as PCRE captures its last variable.',
        ];
    }

    /**
     * @return iterable<string, array{first: string, winner: string, conflicts: list<string>}>
     */
    public static function provideRoutesDeclaredBeforeOneWithNoVariable(): iterable
    {
        yield 'a route matching its path' => ['first' => '/{x<admin>}', 'winner' => 'later', 'conflicts' => []];
        yield 'a route matching another path' => ['first' => '/{x<other>}', 'winner' => 'earlier', 'conflicts' => ['shadowed earlier later']];
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>, 2: list<string>}, later: array{0: string, 1: list<string>, 2: list<string>}, probes: list<array{0: string, 1: string, 2: string, 3: bool}>, type: string|null, skipped: int}>
     */
    public static function provideRoutesLookedUpByTheirPath(): iterable
    {
        yield 'a route before one taking its path with two slashes' => [
            'earlier' => ['/admin', ['GET'], []],
            'later' => ['/{p<admin//>}', ['GET'], []],
            'probes' => [['GET', 'http', '/admin//', false], ['HEAD', 'http', '/admin//', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'a route on https before one on http taking its path with two slashes' => [
            'earlier' => ['/admin', ['GET'], ['https']],
            'later' => ['/{p<admin//>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/admin//', false], ['HEAD', 'http', '/admin//', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'a route for any method before one taking its path with one slash or more' => [
            'earlier' => ['/admin', [], []],
            'later' => ['/{p<admin/+>}', [], []],
            'probes' => [['GET', 'http', '/admin///', false], ['POST', 'http', '/admin///', true]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        yield 'a route ending on a slash before one taking its path with two slashes' => [
            'earlier' => ['/admin/', [], []],
            'later' => ['/{p<admin//>}', [], []],
            'probes' => [['GET', 'http', '/admin//', false], ['POST', 'http', '/admin//', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'a route on https ending on a slash before one on http taking its path with two slashes' => [
            'earlier' => ['/admin/', [], ['https']],
            'later' => ['/{p<admin//>}', [], ['http']],
            'probes' => [['GET', 'http', '/admin//', true], ['POST', 'http', '/admin//', true]],
            'type' => null,
            'skipped' => 0,
        ];
        // Every path a route with no variable redirects leads to its own:
        // the later route serves it, and loses none.
        yield 'a route on https ending on a slash before one on http that serves the path the redirect leads to' => [
            'earlier' => ['/admin/', ['GET'], ['https']],
            'later' => ['/{p<admin/|admin>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/admin', true], ['GET', 'http', '/admin/', true]],
            'type' => null,
            'skipped' => 0,
        ];
        yield 'the root route before one taking it with more slashes' => [
            'earlier' => ['/', ['GET'], []],
            'later' => ['/{p</+>}', ['GET'], []],
            'probes' => [['GET', 'http', '//', false], ['HEAD', 'http', '///', false]],
            'type' => 'shadowed',
            'skipped' => 0,
        ];
        yield 'the root route on https before a catch-all on http' => [
            'earlier' => ['/', ['GET'], ['https']],
            'later' => ['/{p<.*>}', [], ['http']],
            'probes' => [['GET', 'http', '/', true], ['GET', 'http', '//', false], ['HEAD', 'http', '//', false], ['POST', 'http', '//', true]],
            'type' => 'overlap',
            'skipped' => 0,
        ];
        yield 'a route matched with a regex before one taking its path with two slashes' => [
            'earlier' => ['/{x<admin>}', ['GET'], []],
            'later' => ['/{p<admin//>}', ['GET'], []],
            'probes' => [['GET', 'http', '/admin//', true]],
            'type' => null,
            'skipped' => 0,
        ];
        yield 'a route matched with a regex ending on a slash before one taking its path with two slashes' => [
            'earlier' => ['/{x<admin>}/', ['GET'], []],
            'later' => ['/{p<admin//>}', ['GET'], []],
            'probes' => [['GET', 'http', '/admin//', true]],
            'type' => null,
            'skipped' => 0,
        ];
        // The static path is looked up first: the earlier route never gets
        // it, whatever it matches.
        yield 'a route matched with a regex before a route with no variable that it does not match' => [
            'earlier' => ['/{x<admin//|other>}', ['GET'], []],
            'later' => ['/admin/', ['GET'], []],
            'probes' => [['GET', 'http', '/admin//', true], ['GET', 'http', '/admin/', true], ['GET', 'http', '/other', false]],
            'type' => null,
            'skipped' => 0,
        ];
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>, 2: list<string>}, later: array{0: string, 1: list<string>, 2: list<string>}, probes: list<array{0: string, 1: string, 2: string, 3: bool}>}>
     */
    public static function provideEarlierRoutesEndingOnAVariable(): iterable
    {
        yield 'a variable that may end on the slash' => [
            'earlier' => ['/{x<a/?|b>}', ['GET'], ['https']],
            'later' => ['/{y<a/|b/>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/b/', false]],
        ];
        yield 'the same variable, its branches swapped' => [
            'earlier' => ['/{x<b/?|a>}', ['GET'], ['https']],
            'later' => ['/{y<b/|a/>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/a/', false]],
        ];
        yield 'a lazy variable before a route ending on the slash' => [
            'earlier' => ['/files/{path<.+?>}', ['GET'], ['https']],
            'later' => ['/files/{name}/', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/files/a/', false], ['HEAD', 'http', '/files/a/', false]],
        ];
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>, 2: list<string>}, later: array{0: string, 1: list<string>, 2: list<string>}, probes: list<array{0: string, 1: string, 2: string, 3: bool}>, type: string|null, filtered: int}>
     */
    public static function provideTrailingSlashPairsOnDisjointSchemes(): iterable
    {
        yield 'a route on https with a trailing slash before the same route without on http, for GET only' => [
            'earlier' => ['/foo/', ['GET'], ['https']],
            'later' => ['/foo', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/foo', false], ['HEAD', 'http', '/foo', false]],
            'type' => 'shadowed',
            'filtered' => 0,
        ];
        yield 'a route on https with a trailing slash before the same route without on http' => [
            'earlier' => ['/foo/', [], ['https']],
            'later' => ['/foo', [], ['http']],
            'probes' => [['GET', 'http', '/foo', false], ['POST', 'http', '/foo', true]],
            'type' => 'overlap',
            'filtered' => 0,
        ];
        yield 'a route on https without a trailing slash before the same route with one on http, for GET only' => [
            'earlier' => ['/foo', ['GET'], ['https']],
            'later' => ['/foo/', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/foo/', false], ['HEAD', 'http', '/foo/', false]],
            'type' => 'shadowed',
            'filtered' => 0,
        ];
        yield 'the same path on disjoint schemes' => [
            'earlier' => ['/x', [], ['https']],
            'later' => ['/x', [], ['http']],
            'probes' => [['GET', 'http', '/x', true], ['GET', 'https', '/x', false]],
            'type' => null,
            'filtered' => 0,
        ];
        // The paths both match stay the later route's on its scheme; the
        // redirect still takes the others.
        yield 'a route on https sharing some paths with a later one on http, which loses the rest to the redirect' => [
            'earlier' => ['/{x<a|b>}.html', ['GET'], ['https']],
            'later' => ['/{y<a\.html|b\.html/>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/a.html', true], ['GET', 'http', '/b.html/', false], ['HEAD', 'http', '/b.html/', false]],
            'type' => 'overlap',
            'filtered' => 0,
        ];
        yield 'a route on https ending on a slash sharing a path with a later one on http, which loses the path without it' => [
            'earlier' => ['/{x<a|b>}.d/', ['GET'], ['https']],
            'later' => ['/{y<a\.d/|b\.d>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/a.d/', true], ['GET', 'http', '/b.d', false], ['HEAD', 'http', '/b.d', false]],
            'type' => 'overlap',
            'filtered' => 0,
        ];
        // The router redirects to the path of the earlier route, which the
        // later route also matches and serves on its own scheme.
        yield 'a route on https before a catch-all on http, which serves the paths the redirect leads to' => [
            'earlier' => ['/admin', ['GET'], ['https']],
            'later' => ['/{path<.*>}', [], ['http']],
            'probes' => [['GET', 'http', '/admin/', true], ['HEAD', 'http', '/admin/', true], ['GET', 'http', '/admin', true], ['GET', 'https', '/admin', false]],
            'type' => null,
            'filtered' => 0,
        ];
        yield 'a route on https ending on a slash before a catch-all on http, which serves the paths the redirect leads to' => [
            'earlier' => ['/admin/', ['GET'], ['https']],
            'later' => ['/{path<.+>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/admin', true], ['HEAD', 'http', '/admin', true], ['GET', 'http', '/admin/', true]],
            'type' => null,
            'filtered' => 0,
        ];
        // A route with no variable redirects every path to one: the later
        // route serves it, or loses each redirected path.
        yield 'a route on https ending on a slash before one on http that serves the path the redirect leads to' => [
            'earlier' => ['/admin/', ['GET'], ['https']],
            'later' => ['/{p<admin/|admin>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/admin', true], ['HEAD', 'http', '/admin', true], ['GET', 'http', '/admin/', true]],
            'type' => null,
            'filtered' => 0,
        ];
        yield 'a route on https ending on a slash before one on http that does not serve the path the redirect leads to' => [
            'earlier' => ['/admin/', ['GET'], ['https']],
            'later' => ['/{p<admin|admin//>}', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/admin', false], ['HEAD', 'http', '/admin', false], ['GET', 'http', '/admin//', true]],
            'type' => 'overlap',
            'filtered' => 0,
        ];
        yield 'a route on https sharing every path with a later one on http, whose paths the redirect leaves alone' => [
            'earlier' => ['/{x<a|b>}.html', ['GET'], ['https']],
            'later' => ['/{y<a>}.html', ['GET'], ['http']],
            'probes' => [['GET', 'http', '/a.html', true]],
            'type' => null,
            'filtered' => 0,
        ];
        yield 'an earlier route for POST only on another scheme redirects nothing' => [
            'earlier' => ['/foo/', ['POST'], ['https']],
            'later' => ['/foo', [], ['http']],
            'probes' => [['GET', 'http', '/foo', true]],
            'type' => null,
            'filtered' => 1,
        ];
    }

    /**
     * A later route whose paths the earlier one matches in part and
     * redirects for the rest, each path the earlier route's own or one
     * differing from it by a trailing slash alone, is shadowed when it
     * takes only GET and HEAD requests on the schemes and hosts of the
     * earlier route: the matcher sends every request of it to the earlier
     * route, as is or redirected. One that keeps some requests, another
     * method or a scheme of its own, overlaps.
     *
     * @param array{0: string, 1: list<string>, 2: list<string>, 3: array<string, string>, 4: array<string, string>} $earlier path, methods, schemes, requirements, defaults
     * @param array{0: string, 1: list<string>, 2: list<string>, 3: array<string, string>, 4: array<string, string>} $later   path, methods, schemes, requirements, defaults
     * @param list<array{0: string, 1: string, 2: string, 3: string|null}>                                           $probes  method, scheme, path, and the route the request reaches, redirected or not
     */
    #[Test]
    #[DataProvider('provideRoutesMatchedInPartAndRedirectedForTheRest')]
    public function test_a_later_route_the_earlier_one_matches_in_part_and_redirects_for_the_rest(array $earlier, array $later, array $probes, string $type, ?string $example): void
    {
        $collection = new RouteCollection();
        foreach (['earlier' => $earlier, 'later' => $later] as $name => [$path, $methods, $schemes, $requirements, $defaults]) {
            $collection->add($name, (new Route($path, $defaults, $requirements))->setMethods($methods)->setSchemes($schemes));
        }

        $compiled = (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes();
        foreach ($probes as [$method, $scheme, $path, $winner]) {
            $this->assertSame($winner, FrameworkMatcher::reached($compiled, $method, $path, $scheme), $method.' '.$scheme.' '.$path);
        }

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([[$type, 'earlier', 'later']], array_map(
            static fn (array $conflict): array => [$conflict['type'], $conflict['route']['name'], $conflict['conflict']['name']],
            $report->conflicts,
        ));
        $this->assertSame('shadowed' === $type ? 1 : 0, $report->stats['shadowed']);
        $this->assertSame('overlap' === $type ? 1 : 0, $report->stats['overlaps']);
        $this->assertSame('shadowed' === $type, $report->conflicts[0]['redirected']);
        $this->assertSame($example, $report->conflicts[0]['example']);
        if ('shadowed' === $type) {
            $this->assertContains('GET and HEAD requests differing by a trailing slash are redirected to the earlier route.', $report->conflicts[0]['notes']);
            $this->assertSame('earlier', FrameworkMatcher::reached($compiled, 'GET', (string) $example));
        }
    }

    /**
     * @return iterable<string, array{earlier: array{0: string, 1: list<string>, 2: list<string>, 3: array<string, string>, 4: array<string, string>}, later: array{0: string, 1: list<string>, 2: list<string>, 3: array<string, string>, 4: array<string, string>}, probes: list<array{0: string, 1: string, 2: string, 3: string|null}>, type: string, example: string|null}>
     */
    public static function provideRoutesMatchedInPartAndRedirectedForTheRest(): iterable
    {
        yield 'a placeholder matched as is on one value and redirected on the other' => [
            'earlier' => ['/{x}', ['GET'], [], ['x' => 'a|b'], []],
            'later' => ['/{y}', ['GET'], [], ['y' => 'a|b/'], []],
            'probes' => [['GET', 'http', '/a', 'earlier'], ['GET', 'http', '/b/', 'earlier'], ['HEAD', 'http', '/b/', 'earlier']],
            'type' => 'shadowed',
            'example' => '/b/',
        ];
        yield 'a later route taking its path with and without a trailing slash' => [
            'earlier' => ['/blog', [], [], [], []],
            'later' => ['/blog{slash}', ['GET', 'HEAD'], [], ['slash' => '/?'], ['slash' => '']],
            'probes' => [['GET', 'http', '/blog', 'earlier'], ['GET', 'http', '/blog/', 'earlier'], ['HEAD', 'http', '/blog/', 'earlier']],
            'type' => 'shadowed',
            'example' => '/blog/',
        ];
        yield 'a later route that takes POST too keeps it' => [
            'earlier' => ['/{x}', ['GET'], [], ['x' => 'a|b'], []],
            'later' => ['/{y}', ['GET', 'POST'], [], ['y' => 'a|b/'], []],
            'probes' => [['GET', 'http', '/b/', 'earlier'], ['POST', 'http', '/b/', 'later'], ['POST', 'http', '/a', 'later']],
            'type' => 'overlap',
            'example' => '/a',
        ];
        yield 'a later route on a scheme the earlier one does not take keeps it' => [
            'earlier' => ['/{x}', ['GET'], ['https'], ['x' => 'a|b'], []],
            'later' => ['/{y}', ['GET'], [], ['y' => 'a|b/'], []],
            'probes' => [['GET', 'https', '/a', 'earlier'], ['GET', 'http', '/a', 'later']],
            'type' => 'overlap',
            'example' => '/a',
        ];
        // A possessive the slash may follow is ordered behaviour the solver
        // cannot read: the paths the earlier route matches decide the pair.
        yield 'a redirect the solver gives up on leaves the pair to the paths both match' => [
            'earlier' => ['/{x}', ['GET'], [], ['x' => '.++'], []],
            'later' => ['/{y}', ['GET'], [], ['y' => 'a?'], []],
            'probes' => [['GET', 'http', '/a', 'earlier'], ['GET', 'http', '/', 'later']],
            'type' => 'overlap',
            'example' => '/a',
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
     * A host check the solver gives up on, a conditional it does not read,
     * leaves the hosts unread: the conflict it still reports says so, as
     * for a host it cannot read at all. The matcher sends "bb.example.com"
     * to the earlier route.
     */
    #[Test]
    public function test_a_host_check_given_up_on_complexity_is_named_on_the_conflict(): void
    {
        $collection = new RouteCollection();
        $collection->add('echo', (new Route('/x'))->setHost('{sub}.example.com')->setRequirement('sub', '(?(?=b)bb|c)'));
        $collection->add('any', (new Route('/x'))->setHost('{name}.example.com'));

        $this->assertSame('echo', (new UrlMatcher($collection, new RequestContext(host: 'bb.example.com')))->match('/x')['_route']);
        $this->assertSame('any', (new UrlMatcher($collection, new RequestContext(host: 'b.example.com')))->match('/x')['_route']);

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame([], $report->routesWithUnsupportedHosts);
        $this->assertSame(1, $report->stats['host_skipped']);
        $this->assertCount(1, $report->conflicts);
        $this->assertContains('Host requirements were not evaluated.', $report->conflicts[0]['notes']);
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

    private static function urlMatcherSendsHeadToARouteForHeadOnly(): bool
    {
        $collection = new RouteCollection();
        $collection->add('head', (new Route('/x'))->setMethods(['HEAD']));

        try {
            (new UrlMatcher($collection, new RequestContext(method: 'HEAD')))->match('/x');
        } catch (MethodNotAllowedException) {
            return false;
        }

        return true;
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

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

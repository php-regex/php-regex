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
use PHPRegex\Symfony\Routing\RouteConflictSuggestionBuilder;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * What the report suggests for each conflict: the reorder, a merge of two
 * routes matching the same paths, and a requirement for the placeholder
 * that took the static segment of the later route.
 */
final class RouteConflictSuggestionBuilderTest extends TestCase
{
    /**
     * @param list<string> $suggestions
     */
    #[Test]
    #[DataProvider('provideShadowingPairs')]
    public function test_a_placeholder_that_takes_a_static_segment_gets_a_requirement(string $earlier, string $later, string $example, array $suggestions): void
    {
        $collection = new RouteCollection();
        $collection->add('first', new Route($earlier));
        $collection->add('second', new Route($later));

        // The matcher itself sends the later route's path to the earlier one.
        $this->assertSame('first', (new UrlMatcher($collection, new RequestContext()))->match($example)['_route']);

        $conflicts = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true)->conflicts;
        $this->assertSame([$example], array_column($conflicts, 'example'));

        $this->assertSame($suggestions, (new RouteConflictSuggestionBuilder())->collect($conflicts));
    }

    /**
     * @return iterable<string, array{earlier: string, later: string, example: string, suggestions: list<string>}>
     */
    public static function provideShadowingPairs(): iterable
    {
        $reorder = 'Reorder routes: move "second" before "first".';

        yield 'a placeholder named for nothing known' => [
            'earlier' => '/profile/{user}',
            'later' => '/profile/admin',
            'example' => '/profile/admin',
            'suggestions' => [$reorder, 'Add a requirement for {user} in "first" to avoid matching "admin".'],
        ];
        yield 'a uuid' => [
            'earlier' => '/items/{uuid}',
            'later' => '/items/latest',
            'example' => '/items/latest',
            'suggestions' => [$reorder, 'Add a requirement for {uuid} in "first" (e.g. "[0-9a-fA-F-]{36}").'],
        ];
        yield 'a name ending in id' => [
            'earlier' => '/posts/{postId}',
            'later' => '/posts/drafts',
            'example' => '/posts/drafts',
            'suggestions' => [$reorder, 'Add a requirement for {postId} in "first" (e.g. "\d+").'],
        ];
        yield 'a slug' => [
            'earlier' => '/blog/{slug}',
            'later' => '/blog/archive',
            'example' => '/blog/archive',
            'suggestions' => [$reorder, 'Add a requirement for {slug} in "first" (e.g. "[a-z0-9-]+").'],
        ];
        yield 'a locale' => [
            'earlier' => '/{locale}',
            'later' => '/en',
            'example' => '/en',
            'suggestions' => [$reorder, 'Add a requirement for {locale} in "first" (e.g. "[a-z]{2}").'],
        ];
    }

    /**
     * The requirements suggested for a uuid and for an id keep the static
     * segment out: the engine refuses it under each.
     */
    #[Test]
    public function test_the_uuid_and_id_requirements_refuse_the_static_segment(): void
    {
        $this->assertSame(0, preg_match('{^[0-9a-fA-F-]{36}$}', 'latest'));
        $this->assertSame(1, preg_match('{^[0-9a-fA-F-]{36}$}', '0f8fad5b-d9cb-469f-a165-70867728950e'));
        $this->assertSame(0, preg_match('{^\d+$}', 'drafts'));

        $collection = new RouteCollection();
        $collection->add('first', new Route('/items/{uuid}', [], ['uuid' => '[0-9a-fA-F-]{36}']));
        $collection->add('second', new Route('/items/latest'));

        $this->assertSame([], (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true)->conflicts);
    }

    /**
     * Two routes matching the same paths are offered a merge, once, with
     * the reorder.
     */
    #[Test]
    public function test_two_routes_matching_the_same_paths_are_offered_a_merge(): void
    {
        $collection = new RouteCollection();
        $collection->add('first', new Route('/pages/{name}'));
        $collection->add('second', new Route('/pages/{slug}'));
        $conflicts = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true)->conflicts;

        $this->assertSame(
            ['Reorder routes: move "second" before "first".', 'Merge duplicate routes: "first" and "second" match the same paths.'],
            (new RouteConflictSuggestionBuilder())->collect([...$conflicts, ...$conflicts]),
        );
    }

    /**
     * An earlier route that takes only some requests of a later route on
     * the same paths, a route for HEAD only before a route for GET, is the
     * order that serves both, a carve-out: moving the later route first
     * would take every request of the earlier one, and merging them would
     * drop one. The pair is not reported, and nothing is suggested.
     *
     * @param list<string> $earlier
     * @param list<string> $later
     */
    #[Test]
    #[DataProvider('provideOrdersServingBoth')]
    public function test_a_carve_out_the_reorder_would_shadow_gets_no_reorder_and_no_merge(array $earlier, array $later): void
    {
        $collection = new RouteCollection();
        $collection->add('first', (new Route('/foo'))->setMethods($earlier));
        $collection->add('second', (new Route('/foo'))->setMethods($later));

        // As declared, the matcher sends some requests to each route;
        // moved first, the later route takes all of them.
        $this->assertSame(['first', 'second'], self::routesReached($collection, '/foo'));
        $this->assertSame(['second'], self::routesReached(self::reversed($collection), '/foo'));

        $conflicts = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true)->conflicts;
        $this->assertSame([], array_column($conflicts, 'type'));

        $this->assertSame([], (new RouteConflictSuggestionBuilder())->collect($conflicts));
    }

    /**
     * @return iterable<string, array{earlier: list<string>, later: list<string>}>
     */
    public static function provideOrdersServingBoth(): iterable
    {
        yield 'a route for HEAD only before a route for GET' => ['earlier' => ['HEAD'], 'later' => ['GET']];
        yield 'a route for GET before a route for GET and POST' => ['earlier' => ['GET'], 'later' => ['GET', 'POST']];
    }

    /**
     * Two routes whose paths differ by a trailing slash alone: whichever
     * comes first takes the GET and HEAD requests of the other, redirected,
     * so moving the later one first only turns the redirect round. The
     * paths are what to change.
     *
     * @param list<string> $methods
     */
    #[Test]
    #[DataProvider('provideTrailingSlashConflicts')]
    public function test_a_trailing_slash_redirect_gets_no_reorder_but_distinct_paths(string $earlier, string $later, array $methods): void
    {
        $collection = new RouteCollection();
        $collection->add('first', new Route($earlier));
        $collection->add('second', (new Route($later))->setMethods($methods));

        // As declared, the GET and HEAD requests of the later path are
        // redirected to the earlier route; moved first, the later route
        // takes those of the earlier path the same way.
        foreach (['GET', 'HEAD'] as $method) {
            $this->assertSame('first', FrameworkMatcher::reached($collection, $method, $later), $method.' '.$later);
            $this->assertSame('second', FrameworkMatcher::reached(self::reversed($collection), $method, $earlier), $method.' '.$earlier.', reversed');
        }

        $conflicts = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true)->conflicts;
        $this->assertCount(1, $conflicts);

        $this->assertSame(
            ['Give "first" and "second" paths that differ by more than a trailing slash: "first" answers the GET and HEAD requests of "second" that differ from its paths by the slash.'],
            (new RouteConflictSuggestionBuilder())->collect($conflicts),
        );
    }

    /**
     * @return iterable<string, array{earlier: string, later: string, methods: list<string>}>
     */
    public static function provideTrailingSlashConflicts(): iterable
    {
        yield 'an overlap' => ['earlier' => '/foo/', 'later' => '/foo', 'methods' => []];
        yield 'a shadow' => ['earlier' => '/foo', 'later' => '/foo/', 'methods' => ['GET']];
    }

    /**
     * An overlap leaves each route requests of its own, by method, scheme,
     * host or path: moving the later route first still serves both, so the
     * reorder is suggested. An earlier route whose requests the later one
     * all takes is a carve-out, not reported.
     *
     * @param array{0: string, 1: list<string>, 2: list<string>, 3: string} $first  path, methods, schemes, host
     * @param array{0: string, 1: list<string>, 2: list<string>, 3: string} $second path, methods, schemes, host
     * @param list<array{0: string, 1: string, 2: string, 3: string}>       $probes method, scheme, host, path
     */
    #[Test]
    #[DataProvider('provideOverlapsServedInEitherOrder')]
    public function test_an_overlap_the_reorder_would_not_shadow_keeps_the_reorder(array $first, array $second, array $probes): void
    {
        $collection = new RouteCollection();
        foreach (['first' => $first, 'second' => $second] as $name => [$path, $methods, $schemes, $host]) {
            $collection->add($name, (new Route($path))->setMethods($methods)->setSchemes($schemes)->setHost($host));
        }

        // Either way round, the matcher sends some requests to each route.
        $this->assertSame(['first', 'second'], self::routesReachedBy($collection, $probes));
        $this->assertSame(['first', 'second'], self::routesReachedBy(self::reversed($collection), $probes));

        $conflicts = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true)->conflicts;
        $this->assertSame(['overlap'], array_column($conflicts, 'type'));

        $this->assertContains('Reorder routes: move "second" before "first".', (new RouteConflictSuggestionBuilder())->collect($conflicts));
    }

    /**
     * @return iterable<string, array{first: array{0: string, 1: list<string>, 2: list<string>, 3: string}, second: array{0: string, 1: list<string>, 2: list<string>, 3: string}, probes: list<array{0: string, 1: string, 2: string, 3: string}>}>
     */
    public static function provideOverlapsServedInEitherOrder(): iterable
    {
        $methods = [['GET', 'http', 'localhost', '/foo'], ['POST', 'http', 'localhost', '/foo'], ['PUT', 'http', 'localhost', '/foo']];

        yield 'each route for a method of its own' => [
            'first' => ['/foo', ['GET', 'PUT'], [], ''],
            'second' => ['/foo', ['GET', 'POST'], [], ''],
            'probes' => $methods,
        ];
        yield 'the earlier route on https for a method of its own' => [
            'first' => ['/foo', ['GET', 'POST'], ['https'], ''],
            'second' => ['/foo', ['GET'], [], ''],
            'probes' => [['GET', 'http', 'localhost', '/foo'], ['GET', 'https', 'localhost', '/foo'], ['POST', 'https', 'localhost', '/foo']],
        ];
        yield 'the earlier route on a host for a method of its own' => [
            'first' => ['/foo', ['GET', 'POST'], [], 'admin.example.com'],
            'second' => ['/foo', ['GET'], [], ''],
            'probes' => [['GET', 'http', 'shop.example.com', '/foo'], ['GET', 'http', 'admin.example.com', '/foo'], ['POST', 'http', 'admin.example.com', '/foo']],
        ];
        yield 'each route for paths of its own' => [
            'first' => ['/blog/{slug<[a-z]+>}', [], [], ''],
            'second' => ['/blog/{id<\d+|new>}', [], [], ''],
            'probes' => [['GET', 'http', 'localhost', '/blog/abc'], ['GET', 'http', 'localhost', '/blog/new'], ['GET', 'http', 'localhost', '/blog/1']],
        ];
    }

    /**
     * No requirement is suggested where the later route has no static
     * segment, nor where the placeholder took a value none of its static
     * segments holds.
     */
    #[Test]
    #[DataProvider('provideConflictsWithoutARequirement')]
    public function test_a_placeholder_that_took_no_static_segment_gets_no_requirement(string $earlier, string $later, string $example): void
    {
        $collection = new RouteCollection();
        $collection->add('first', new Route($earlier));
        $collection->add('second', new Route($later, [], ['b' => '[a-z]+']));

        $this->assertSame('first', (new UrlMatcher($collection, new RequestContext()))->match($example)['_route']);

        $conflicts = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true)->conflicts;
        $this->assertSame([$example], array_column($conflicts, 'example'));

        $this->assertSame(['Reorder routes: move "second" before "first".'], (new RouteConflictSuggestionBuilder())->collect($conflicts));
    }

    /**
     * @return iterable<string, array{earlier: string, later: string, example: string}>
     */
    public static function provideConflictsWithoutARequirement(): iterable
    {
        yield 'a later route of a placeholder only' => ['earlier' => '/{a}', 'later' => '/{b}', 'example' => '/a'];
        yield 'a value the static segment does not hold' => ['earlier' => '/x/{a}', 'later' => '/x/{b}', 'example' => '/x/a'];
    }

    /**
     * The routes the framework's matcher sends a request on the path to,
     * for some method: each name once, sorted.
     *
     * @return list<string>
     */
    private static function routesReached(RouteCollection $collection, string $path): array
    {
        $routes = [];
        foreach (['GET', 'HEAD', 'POST', 'PUT'] as $method) {
            $route = FrameworkMatcher::reached($collection, $method, $path);
            if (null !== $route) {
                $routes[$route] = true;
            }
        }
        ksort($routes);

        return array_keys($routes);
    }

    /**
     * The routes the framework's matcher sends the requests to: each name
     * once, sorted.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $requests method, scheme, host, path
     *
     * @return list<string>
     */
    private static function routesReachedBy(RouteCollection $collection, array $requests): array
    {
        $routes = [];
        foreach ($requests as [$method, $scheme, $host, $path]) {
            $route = FrameworkMatcher::reached($collection, $method, $path, $scheme, $host);
            if (null !== $route) {
                $routes[$route] = true;
            }
        }
        ksort($routes);

        return array_keys($routes);
    }

    /**
     * The same routes, declared the other way round.
     */
    private static function reversed(RouteCollection $collection): RouteCollection
    {
        $reversed = new RouteCollection();
        foreach (array_reverse($collection->all()) as $name => $route) {
            $reversed->add($name, $route);
        }

        return $reversed;
    }
}

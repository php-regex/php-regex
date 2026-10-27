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
}

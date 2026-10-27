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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Analyzer;

use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\AnalysisIssue;
use PHPRegex\Symfony\Analyzer\AnalysisNotice;
use PHPRegex\Symfony\Analyzer\IssueDetail;
use PHPRegex\Symfony\Analyzer\ReportSection;
use PHPRegex\Symfony\Analyzer\RoutesAnalyzer;
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
use Symfony\Component\Routing\RouterInterface;

/**
 * The routes section of regex:analyze, as the analyzer builds it from the
 * conflicts of the router's collection.
 */
final class RoutesAnalyzerTest extends TestCase
{
    #[Test]
    public function test_the_section_warns_when_there_is_no_router(): void
    {
        $analyzer = new RoutesAnalyzer(new RouteConflictAnalyzer(Regex::create()));

        $section = $this->single($analyzer->analyze(new AnalysisContext(null, null)));

        $this->assertSame('routes', $section->id);
        $this->assertSame('Routes', $section->title);
        $this->assertSame([], $section->meta);
        $this->assertSame([['warn', 'Router service is not available.']], self::notices($section->summary));
        $this->assertSame('routes', $analyzer->getId());
        $this->assertSame('Routes', $analyzer->getLabel());
    }

    #[Test]
    public function test_the_section_passes_a_router_without_routes(): void
    {
        $section = $this->analyze(new RouteCollection(), new AnalysisContext(null, null, debug: true));

        $this->assertSame(['Routes' => 0], $section->meta);
        $this->assertSame([['pass', 'No routes found.']], self::notices($section->summary));
        $this->assertSame([], $section->debug);
    }

    #[Test]
    public function test_the_section_passes_routes_without_conflicts(): void
    {
        $collection = new RouteCollection();
        $collection->add('blog', new Route('/blog/{slug}'));
        $collection->add('shop', new Route('/shop/{slug}'));

        $section = $this->analyze($collection, new AnalysisContext(null, null));

        $this->assertSame(['Routes' => 2, 'Mode' => 'Shadowed only', 'Shadowed' => 0, 'Overlaps' => 0], $section->meta);
        $this->assertSame([['pass', 'No route conflicts detected.']], self::notices($section->summary));
        $this->assertSame([], $section->warnings);
        $this->assertSame([], $section->issues);
        $this->assertSame([], $section->suggestions);
        $this->assertSame([], $section->debug);
    }

    /**
     * A shadowed route fails, an overlap warns; the overlaps are listed
     * only when asked for, and counted either way. The matcher sends the
     * paths both ways round as the issues say.
     */
    #[Test]
    #[DataProvider('provideOverlapModes')]
    public function test_shadowed_routes_fail_and_overlaps_warn(bool $includeOverlaps, string $mode, string $overlapNotice, int $issues): void
    {
        $collection = self::conflictingRoutes();
        $matcher = new UrlMatcher($collection, new RequestContext(scheme: 'https'));
        $this->assertSame('user_show', $matcher->match('/users/list')['_route']);
        $this->assertSame('blog_slug', $matcher->match('/blog/new')['_route']);
        $this->assertSame('blog_id', $matcher->match('/blog/7')['_route']);

        $section = $this->analyze($collection, new AnalysisContext(null, null, includeOverlaps: $includeOverlaps));

        $this->assertSame(['Routes' => 4, 'Mode' => $mode, 'Shadowed' => 1, 'Overlaps' => 1], $section->meta);
        $this->assertSame([
            ['fail', '1 shadowed routes detected.'],
            ['warn', '1 overlapping routes detected. '.$overlapNotice],
        ], self::notices($section->summary));
        $this->assertCount($issues, $section->issues);
    }

    /**
     * @return iterable<string, array{includeOverlaps: bool, mode: string, overlapNotice: string, issues: int}>
     */
    public static function provideOverlapModes(): iterable
    {
        yield 'shadowed only' => ['includeOverlaps' => false, 'mode' => 'Shadowed only', 'overlapNotice' => 'Use --show-overlaps to include them.', 'issues' => 1];
        yield 'with the overlaps' => ['includeOverlaps' => true, 'mode' => 'Shadowed + overlaps', 'overlapNotice' => 'Listed below.', 'issues' => 2];
    }

    #[Test]
    public function test_each_conflict_is_an_issue_naming_both_routes(): void
    {
        $section = $this->analyze(self::conflictingRoutes(), new AnalysisContext(null, null, includeOverlaps: true));

        $this->assertSame([
            [
                'kind' => 'overlap',
                'severity' => 'warn',
                'title' => "blog_slug (#1) \u{21B3} blog_id (#2)",
                'details' => [
                    'Route' => ['/blog/{slug}', 'text'],
                    'Conflict' => ['/blog/{id}', 'text'],
                    'Scope' => ['any', 'text'],
                    'Example' => ['/blog/new', 'example'],
                ],
                'notes' => ['The earlier route wins every path both routes match.'],
            ],
            [
                'kind' => 'shadowed',
                'severity' => 'fail',
                'title' => "user_show (#3) \u{21B3} user_list (#4)",
                'details' => [
                    'Route' => ['/users/{id}', 'text'],
                    'Conflict' => ['/users/list', 'text'],
                    'Scope' => ['GET|POST • https', 'text'],
                    'Example' => ['/users/list', 'example'],
                ],
                'notes' => ['Less specific route declared BEFORE more specific route.'],
            ],
        ], array_map(self::issue(...), $section->issues));
        $this->assertSame([
            'Reorder routes: move "blog_id" before "blog_slug".',
            'Reorder routes: move "user_list" before "user_show".',
            'Add a requirement for {id} in "user_show" (e.g. "\d+").',
        ], $section->suggestions);
    }

    /**
     * The scope names the methods, the schemes, both, or "any".
     *
     * @param list<string> $methods
     * @param list<string> $schemes
     */
    #[Test]
    #[DataProvider('provideScopes')]
    public function test_the_scope_names_what_the_conflict_is_limited_to(array $methods, array $schemes, string $scope): void
    {
        $collection = new RouteCollection();
        $collection->add('user_show', (new Route('/users/{id}'))->setMethods($methods)->setSchemes($schemes));
        $collection->add('user_list', new Route('/users/list'));

        $section = $this->analyze($collection, new AnalysisContext(null, null));

        $this->assertSame(['Scope' => [$scope, 'text']], array_intersect_key(self::issue($section->issues[0])['details'], ['Scope' => true]));
    }

    /**
     * @return iterable<string, array{methods: list<string>, schemes: list<string>, scope: string}>
     */
    public static function provideScopes(): iterable
    {
        yield 'no restriction' => ['methods' => [], 'schemes' => [], 'scope' => 'any'];
        yield 'methods only' => ['methods' => ['GET', 'HEAD'], 'schemes' => [], 'scope' => 'GET|HEAD'];
        yield 'schemes only' => ['methods' => [], 'schemes' => ['https'], 'scope' => 'https'];
        yield 'both' => ['methods' => ['PUT'], 'schemes' => ['http', 'https'], 'scope' => 'PUT • http|https'];
    }

    /**
     * What the analysis could not read is a warning each: the routes it
     * left out, those whose condition it did not evaluate, and those whose
     * host it could not read, each route counted once.
     */
    #[Test]
    public function test_what_the_analysis_could_not_read_is_warned_about(): void
    {
        $collection = new RouteCollection();
        $collection->add('broken', new Route('/x/{a}/{a}'));
        $collection->add('guarded', (new Route('/y'))->setCondition('true'));
        $collection->add('also_guarded', (new Route('/z'))->setCondition('false'));
        // A route of the application compiling its host to a regex under
        // a flag the solver does not read.
        $oddHost = new class('/w') extends Route {
            #[\Override]
            public function compile(): CompiledRoute
            {
                return new CompiledRoute('/w', '{^/w$}sD', [], [], '{^example\.com$}m');
            }
        };
        $collection->add('odd_host', $oddHost->setHost('example.com'));
        $collection->add('plain_host', (new Route('/w'))->setHost('example.com'));

        $section = $this->analyze($collection, new AnalysisContext(null, null));

        $this->assertSame([
            ['warn', '1 routes skipped due to unsupported regex features.'],
            ['warn', '2 routes use conditions; conditions are not evaluated during analysis.'],
            ['warn', '1 routes use host requirements that could not be analyzed.'],
        ], self::notices($section->warnings));
        $this->assertSame(['Host requirements were not evaluated.', 'Equivalent route patterns.', 'Less specific route declared BEFORE more specific route.'], $section->issues[0]->notes);
    }

    /**
     * The debug rows: the counts of the analysis, and why each route left
     * out was left out.
     */
    #[Test]
    public function test_the_debug_rows_count_the_comparisons_and_name_the_skipped_routes(): void
    {
        $collection = self::conflictingRoutes();
        $collection->add('broken', new Route('/x/{a}/{a}'));

        $section = $this->analyze($collection, new AnalysisContext(null, null, debug: true));

        $debug = $section->debug;
        $skipped = $debug['Skipped route broken'] ?? null;
        unset($debug['Skipped route broken']);
        $this->assertSame([
            'Pairs total' => 6,
            'Pairs filtered (methods)' => 0,
            'Pairs filtered (schemes)' => 0,
            'Pairs filtered (prefix)' => 4,
            'Host checks' => 0,
            'Host checks skipped' => 0,
            'Host intersections' => 0,
            'Path intersections' => 2,
            'Path subset checks' => 2,
            'Pairs skipped (complexity)' => 0,
        ], $debug);
        $this->assertIsString($skipped);
        $this->assertStringStartsWith('Route compile failed: ', $skipped);
    }

    /**
     * An overlap on the blog paths, the slug route first, and a placeholder
     * route shadowing a static one on the user paths, on GET and POST over
     * https.
     */
    private static function conflictingRoutes(): RouteCollection
    {
        $collection = new RouteCollection();
        $collection->add('blog_slug', new Route('/blog/{slug}', [], ['slug' => '[a-z]+']));
        $collection->add('blog_id', new Route('/blog/{id}', [], ['id' => '\d+|new']));
        $collection->add('user_show', (new Route('/users/{id}'))->setMethods(['GET', 'POST'])->setSchemes(['https']));
        $collection->add('user_list', new Route('/users/list'));

        return $collection;
    }

    private function analyze(RouteCollection $collection, AnalysisContext $context): ReportSection
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        return $this->single((new RoutesAnalyzer(new RouteConflictAnalyzer(Regex::create()), router: $router))->analyze($context));
    }

    /**
     * @param array<ReportSection> $sections
     */
    private function single(array $sections): ReportSection
    {
        $this->assertCount(1, $sections);

        return $sections[0];
    }

    /**
     * @param array<AnalysisNotice> $notices
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function notices(array $notices): array
    {
        return array_values(array_map(
            static fn (AnalysisNotice $notice): array => [$notice->severity->value, $notice->message],
            $notices,
        ));
    }

    /**
     * @return array{kind: string, severity: string, title: string, details: array<string, array{0: string, 1: string}>, notes: array<string>}
     */
    private static function issue(AnalysisIssue $issue): array
    {
        $details = [];
        foreach ($issue->details as $detail) {
            self::assertInstanceOf(IssueDetail::class, $detail);
            $details[$detail->label] = [$detail->value, $detail->kind];
        }

        return [
            'kind' => $issue->kind,
            'severity' => $issue->severity->value,
            'title' => $issue->title,
            'details' => $details,
            'notes' => $issue->notes,
        ];
    }
}

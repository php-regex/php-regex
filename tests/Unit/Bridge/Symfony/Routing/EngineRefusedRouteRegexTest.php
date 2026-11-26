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
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A route whose requirement PCRE2 refuses to compile matches no request:
 * the solver refuses its regex with the validation error, where it read
 * the tree and compared the route as if "\d{2,1}" matched nothing. The
 * pairs of that route are left out and the other routes are still read.
 */
final class EngineRefusedRouteRegexTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusedRequirements')]
    public function test_the_pairs_of_a_route_pcre_refuses_are_left_out(string $requirement): void
    {
        $collection = self::routes($requirement);

        // The engine first: the regex the router compiles for the route
        // does not compile.
        $this->assertFalse(@preg_match($collection->get('refused')?->compile()->getRegex() ?? '', '/x/list'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyze($collection, true);

        $this->assertSame(['shadowed user users'], array_map(
            static fn (array $conflict): string => $conflict['type'].' '.$conflict['route']['name'].' '.$conflict['conflict']['name'],
            $report->conflicts,
        ));
        $this->assertSame(1, $report->stats['skipped_pairs']);
    }

    #[Test]
    #[DataProvider('provideRefusedRequirementsAndWhy')]
    public function test_a_route_pcre_refuses_has_no_verdict_and_says_why(string $requirement, string $error): void
    {
        $collection = self::routes($requirement);
        $this->assertFalse(@preg_match($collection->get('refused')?->compile()->getRegex() ?? '', '/x/list'));

        $report = (new RouteConflictAnalyzer(Regex::create()))->analyzeReachability($collection);

        $routes = array_column($report->routes, null, 'name');
        $this->assertNull($routes['refused']['reachable']);
        $this->assertSame($error, $routes['refused']['refused']);
        $this->assertTrue($routes['list']['reachable']);
        $this->assertSame([], $routes['list']['takenBy'], 'A route the engine refuses takes no request.');
        $this->assertFalse($routes['users']['reachable']);
        $this->assertSame(1, $report->stats['refused']);
    }

    /**
     * @return iterable<string, array{requirement: string}>
     */
    public static function provideRefusedRequirements(): iterable
    {
        foreach (self::provideRefusedRequirementsAndWhy() as $name => ['requirement' => $requirement]) {
            yield $name => ['requirement' => $requirement];
        }
    }

    /**
     * @return iterable<string, array{requirement: string, error: string}>
     */
    public static function provideRefusedRequirementsAndWhy(): iterable
    {
        yield 'a count out of order' => ['requirement' => '\d{2,1}', 'error' => 'Invalid quantifier range "{2,1}": min > max.'];
        yield 'a range out of order' => ['requirement' => '[z-a]+', 'error' => 'Invalid range "z-a": start character comes after end character.'];
    }

    private static function routes(string $requirement): RouteCollection
    {
        $collection = new RouteCollection();
        $collection->add('refused', new Route('/x/{a}', [], ['a' => $requirement]));
        $collection->add('list', new Route('/x/list'));
        $collection->add('user', new Route('/users/{id}'));
        $collection->add('users', new Route('/users/list'));

        return $collection;
    }
}

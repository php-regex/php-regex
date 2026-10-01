<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Routing;

use PhpRegex\Symfony\Routing\RouteConflictAnalyzer;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RouteConflictAnalyzerTest extends TestCase
{
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
}

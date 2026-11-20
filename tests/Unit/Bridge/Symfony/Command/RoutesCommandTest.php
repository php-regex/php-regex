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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Command;

use PHPRegex\Symfony\Command\RoutesCommand;
use PHPRegex\Symfony\Routing\RouteConflictAnalyzer;
use PHPRegex\Symfony\Routing\RouteConflictSuggestionBuilder;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class RoutesCommandTest extends TestCase
{
    #[Test]
    public function test_command_fails_without_router(): void
    {
        $analyzer = new RouteConflictAnalyzer(Regex::create());
        $command = new RoutesCommand($analyzer, new RouteConflictSuggestionBuilder(), null);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('Router service is not available', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_command_reports_conflicts(): void
    {
        $collection = new RouteCollection();
        $collection->add('api_user_show', new Route('/users/{id}'));
        $collection->add('api_user_list', new Route('/users/list'));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        $analyzer = new RouteConflictAnalyzer(Regex::create());
        $command = new RoutesCommand($analyzer, new RouteConflictSuggestionBuilder(), $router);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('1 shadowed routes detected.', (string) $tester->getDisplay());
        $this->assertStringContainsString('api_user_show', (string) $tester->getDisplay());
        $this->assertStringContainsString('api_user_list', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_reachability_reports_a_route_several_earlier_routes_shadow_together(): void
    {
        $collection = new RouteCollection();
        $collection->add('show', new Route('/c/{id}', methods: ['GET']));
        $collection->add('update', new Route('/c/{id}', methods: ['POST']));
        $collection->add('both', new Route('/c/{id}', methods: ['GET', 'POST']));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);
        $command = new RoutesCommand(new RouteConflictAnalyzer(Regex::create()), new RouteConflictSuggestionBuilder(), $router);

        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute([]), 'No pair of routes tells it.');
        $this->assertStringContainsString('No route conflicts detected.', (string) $tester->getDisplay());

        $status = $tester->execute(['--reachability' => true]);
        $display = (string) $tester->getDisplay();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('1 routes shadowed by several earlier routes together.', $display);
        $this->assertStringContainsString('both (#3)', $display);
        $this->assertStringContainsString('show (#1) + update (#2)', $display);
        $this->assertStringNotContainsString('No route conflicts detected.', $display);
    }

    #[Test]
    public function test_reachability_passes_routes_each_keeping_a_request(): void
    {
        $collection = new RouteCollection();
        $collection->add('show', new Route('/c/{id}', methods: ['GET']));
        $collection->add('any', new Route('/c/{id}'));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);
        $command = new RoutesCommand(new RouteConflictAnalyzer(Regex::create()), new RouteConflictSuggestionBuilder(), $router);

        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute(['--reachability' => true]));
        $this->assertStringContainsString('No route is shadowed by several earlier routes together.', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_command_succeeds_when_no_conflicts(): void
    {
        $collection = new RouteCollection();

        $route = new Route('/users/{id}');
        $route->setRequirements(['id' => '\d+']);
        $collection->add('api_user_show', $route);
        $collection->add('api_user_list', new Route('/users/list'));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        $analyzer = new RouteConflictAnalyzer(Regex::create());
        $command = new RoutesCommand($analyzer, new RouteConflictSuggestionBuilder(), $router);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No route conflicts detected', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_command_passes_a_router_with_no_routes(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn(new RouteCollection());

        $tester = new CommandTester(new RoutesCommand(new RouteConflictAnalyzer(Regex::create()), new RouteConflictSuggestionBuilder(), $router));
        $status = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $display = (string) $tester->getDisplay();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No routes found.', $display);
        $this->assertStringContainsString('If PHPRegex helps, a GitHub star is appreciated', $display);
        $this->assertStringContainsString('by Younes ENNAJI', $display);

        $tester->execute([]);
        $this->assertStringNotContainsString('GitHub star', $tester->getDisplay());
        $this->assertStringNotContainsString('by Younes ENNAJI', $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Bridge\Symfony\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Bridge\Symfony\Command\RegexRoutesCommand;
use RegexParser\Bridge\Symfony\Routing\RouteConflictAnalyzer;
use RegexParser\Bridge\Symfony\Routing\RouteConflictSuggestionBuilder;
use RegexParser\Regex;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class RegexRoutesCommandTest extends TestCase
{
    #[Test]
    public function test_command_fails_without_router(): void
    {
        $analyzer = new RouteConflictAnalyzer(Regex::create());
        $command = new RegexRoutesCommand($analyzer, new RouteConflictSuggestionBuilder(), null);

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
        $command = new RegexRoutesCommand($analyzer, new RouteConflictSuggestionBuilder(), $router);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Route Conflicts Detected', (string) $tester->getDisplay());
        $this->assertStringContainsString('api_user_show', (string) $tester->getDisplay());
        $this->assertStringContainsString('api_user_list', (string) $tester->getDisplay());
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
        $command = new RegexRoutesCommand($analyzer, new RouteConflictSuggestionBuilder(), $router);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No route conflicts detected', (string) $tester->getDisplay());
    }
}

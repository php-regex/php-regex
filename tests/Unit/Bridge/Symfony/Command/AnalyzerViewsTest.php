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

use PHPRegex\Symfony\Analyzer\AnalyzerRegistry;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\RoutesAnalyzer;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Command\AnalyzeCommand;
use PHPRegex\Symfony\Command\RoutesCommand;
use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Routing\RouteConflictAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * regex:routes and regex:security are views over the analyzers regex:analyze
 * runs: the same sections, printed the same way.
 */
final class AnalyzerViewsTest extends TestCase
{
    use TemporaryProject;

    private const SECURITY = "security:\n"
        ."    firewalls:\n        api:\n            pattern: ^/api/(a+)+$\n"
        ."    access_control:\n"
        ."        - { path: ^/admin, roles: ROLE_ADMIN }\n"
        ."        - { path: ^/admin/public, roles: PUBLIC_ACCESS, methods: [GET] }\n";

    #[Test]
    public function test_the_routes_command_prints_the_routes_section_of_the_analyze_command(): void
    {
        $router = $this->router();

        $routes = new CommandTester(new RoutesCommand(new RouteConflictAnalyzer(Regex::create()), router: $router));
        $routesStatus = $routes->execute(['--show-overlaps' => true]);

        $analyze = new CommandTester($this->analyzeCommand($router));
        $analyzeStatus = $analyze->execute(['--only' => ['routes'], '--show-overlaps' => true, '--fail-on' => ['any']]);

        $this->assertSame(Command::FAILURE, $routesStatus);
        $this->assertSame($analyzeStatus, $routesStatus);
        $this->assertStringContainsString('Route: /users/{id}', $routes->getDisplay());
        $this->assertStringContainsString('Scope: any', $routes->getDisplay());
        $this->assertSame(self::from($analyze->getDisplay(), 'Routes'), self::from($routes->getDisplay(), 'Routes'));
    }

    #[Test]
    public function test_the_security_command_prints_the_security_sections_of_the_analyze_command(): void
    {
        $path = $this->makeProject(['security.yaml' => self::SECURITY]).'/security.yaml';

        $security = new CommandTester($this->securityCommand());
        $securityStatus = $security->execute(['--config' => [$path]]);

        $analyze = new CommandTester($this->analyzeCommand(null));
        $analyzeStatus = $analyze->execute(['--only' => ['security'], '--config' => [$path]]);

        $this->assertSame(Command::FAILURE, $securityStatus);
        $this->assertSame($analyzeStatus, $securityStatus);
        $this->assertStringContainsString('Conflict: #2 ', $security->getDisplay());
        $this->assertStringContainsString('scope=methods=GET', $security->getDisplay());
        $this->assertSame(self::from($analyze->getDisplay(), 'Security Access Control'), self::from($security->getDisplay(), 'Security Access Control'));
    }

    /**
     * What a verbose run adds: the counts of the analysis and why each
     * route it left out was left out.
     */
    #[Test]
    public function test_a_verbose_routes_command_says_why_a_route_was_skipped(): void
    {
        $collection = new RouteCollection();
        $collection->add('broken', new Route('/x/{a}/{a}'));
        $collection->add('fine', new Route('/x/{b}'));
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        $tester = new CommandTester(new RoutesCommand(new RouteConflictAnalyzer(Regex::create()), router: $router));
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('1 routes skipped due to unsupported regex features.', $display);
        $this->assertMatchesRegularExpression('/Skipped route broken\s*:\s*Route compile failed: /', $display);
        $this->assertMatchesRegularExpression('/Pairs total\s*:\s*0\b/', $display);
    }

    #[Test]
    public function test_a_quiet_routes_command_keeps_the_skipped_routes_to_their_count(): void
    {
        $collection = new RouteCollection();
        $collection->add('broken', new Route('/x/{a}/{a}'));
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        $tester = new CommandTester(new RoutesCommand(new RouteConflictAnalyzer(Regex::create()), router: $router));
        $tester->execute([]);

        $this->assertStringContainsString('1 routes skipped due to unsupported regex features.', $tester->getDisplay());
        $this->assertStringNotContainsString('Skipped route broken', $tester->getDisplay());
    }

    #[Test]
    public function test_a_verbose_security_command_says_why_a_rule_was_skipped(): void
    {
        $path = $this->makeProject([
            'security.yaml' => "security:\n    access_control:\n        - { request_matcher: app.admin_matcher, roles: ROLE_ADMIN }\n",
        ]).'/security.yaml';

        $tester = new CommandTester($this->securityCommand());
        $tester->execute(['--config' => [$path], '--skip-firewalls' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('1 access_control rules skipped due to unsupported regex features.', $display);
        $this->assertMatchesRegularExpression('/Skipped rule #1 \(.*security\.yaml:3\)\s*:\s*request_matcher rules cannot be analyzed\./', $display);
    }

    #[Test]
    public function test_a_verbose_security_command_says_why_a_file_and_a_firewall_were_skipped(): void
    {
        $project = $this->makeProject([
            'security.yaml' => "security:\n    firewalls:\n        dev:\n            request_matcher: app.dev_matcher\n",
        ]);

        $tester = new CommandTester($this->securityCommand());
        $tester->execute(['--config' => [$project.'/missing.yaml', $project.'/security.yaml']], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('1 security config files were skipped.', $display);
        $this->assertMatchesRegularExpression('/Skipped file \S*missing\.yaml\s*:\s*File not found\./', $display);
        $this->assertStringContainsString('1 firewalls skipped during ReDoS analysis.', $display);
        $this->assertMatchesRegularExpression('/Skipped firewall dev \(\S*security\.yaml:3\)\s*:\s*request_matcher patterns cannot be analyzed\./', $display);
    }

    #[Test]
    public function test_a_verbose_security_command_with_no_entry_says_why_a_file_was_skipped(): void
    {
        $project = $this->makeProject(['security.yaml' => "security: ~\n"]);

        $tester = new CommandTester($this->securityCommand());
        $status = $tester->execute(['--config' => [$project.'/missing.yaml', $project.'/security.yaml']], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('No security configuration entries found.', $display);
        $this->assertStringContainsString('1 security config files were skipped.', $display);
        $this->assertMatchesRegularExpression('/Skipped file \S*missing\.yaml\s*:\s*File not found\./', $display);
    }

    /**
     * The sections and what follows them, from the header of the first
     * one: everything but the banner and the progress line regex:analyze
     * prints before them.
     */
    private static function from(string $display, string $title): string
    {
        $start = strpos($display, ' '.$title.' '.\PHP_EOL);
        self::assertNotFalse($start, $display);

        return substr($display, $start);
    }

    private function router(): RouterInterface
    {
        $collection = new RouteCollection();
        $collection->add('api_user_show', new Route('/users/{id}'));
        $collection->add('api_user_list', new Route('/users/list'));
        $collection->add('blog_slug', new Route('/blog/{slug}', [], ['slug' => '[a-z]+']));
        $collection->add('blog_id', new Route('/blog/{id}', [], ['id' => '\d+|new']));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        return $router;
    }

    private function securityCommand(): SecurityCommand
    {
        return new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );
    }

    private function analyzeCommand(?RouterInterface $router): AnalyzeCommand
    {
        return new AnalyzeCommand(
            new AnalyzerRegistry([
                new RoutesAnalyzer(new RouteConflictAnalyzer(Regex::create()), router: $router),
                new SecurityAnalyzer(
                    new SecurityConfigExtractor(),
                    new SecurityConfigLocator(),
                    new SecurityAccessControlAnalyzer(Regex::create()),
                    new SecurityFirewallAnalyzer(Regex::create()),
                ),
            ]),
            new ConsoleReportFormatter(),
            new JsonReportFormatter(),
        );
    }
}

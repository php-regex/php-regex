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
use PHPRegex\Symfony\Routing\RouteConflictAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * regex:analyze --format=json prints the document the JSON output reference
 * describes, from the real analyzers: one JSON document on stdout, every key
 * snake_case, the people's labels of a section's facts written as values.
 * A usage error in JSON mode prints the error envelope.
 */
final class AnalyzeJsonContractTest extends TestCase
{
    private const SECURITY_FIXTURE = __DIR__.'/../../../../Fixtures/Symfony/security_access_control.yaml';

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_the_report_follows_the_contract(array $options, int $exitCode): void
    {
        $tester = new CommandTester($this->command());
        $status = $tester->execute(['--format' => 'json', '--config' => [self::SECURITY_FIXTURE]] + $options);
        $display = $tester->getDisplay();

        $this->assertSame($exitCode, $status, $display);
        $document = JsonContract::decodeDocument($display);
        JsonContract::assertShape('symfony_analyze', $document);
        JsonContract::assertSnakeCaseKeys($document);
        $this->assertStringContainsString('↳', $display, 'Unicode is written as it is.');
        $this->assertStringContainsString('"/users/list"', $display, 'Slashes are written as they are.');
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>, exitCode: int}>
     */
    public static function provideRuns(): iterable
    {
        yield 'every analyzer' => ['options' => [], 'exitCode' => 1];
        yield 'with the debug rows' => ['options' => ['--debug' => true], 'exitCode' => 1];
        yield 'with the overlaps' => ['options' => ['--show-overlaps' => true], 'exitCode' => 1];
        yield 'failing on nothing' => ['options' => ['--fail-on' => ['none']], 'exitCode' => 0];
    }

    #[Test]
    public function test_a_section_writes_its_facts_as_label_and_value(): void
    {
        $sections = $this->sections(['--debug' => true]);
        $routes = $sections['routes'];

        $this->assertSame(['label' => 'Routes', 'value' => 2], JsonContract::asArray($routes['meta'])[0]);
        $this->assertContains(['label' => 'Pairs total', 'value' => 1], JsonContract::asArray($routes['debug']));
        $this->assertArrayNotHasKey('debug', $this->sections([])['routes'], 'The debug rows only come with --debug.');
    }

    /**
     * The firewall finding names the ReDoS severity under "Severity".
     */
    #[Test]
    public function test_the_firewall_finding_labels_its_severity(): void
    {
        $issues = JsonContract::asArray($this->sections([])['security_firewall']['issues']);
        $details = JsonContract::asArray(JsonContract::asArray($issues[0])['details']);

        $this->assertSame(['label' => 'Severity', 'value' => 'CRITICAL', 'kind' => 'text'], $details[0]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('provideUsageErrors')]
    public function test_a_usage_error_prints_the_envelope(array $options): void
    {
        $tester = new CommandTester($this->command());
        $status = $tester->execute(['--format' => 'json'] + $options);
        $display = $tester->getDisplay();

        $this->assertSame(2, $status, $display);
        $document = JsonContract::decodeDocument($display);
        JsonContract::assertShape('error', $document);
        $this->assertSame('usage', $document['stage']);
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>}>
     */
    public static function provideUsageErrors(): iterable
    {
        yield 'an unknown analyzer' => ['options' => ['--only' => ['nosuch']]];
        yield 'an unknown --fail-on' => ['options' => ['--fail-on' => ['nosuch']]];
        yield 'an unknown --redos-threshold' => ['options' => ['--redos-threshold' => 'severe']];
        yield 'a format in upper case' => ['options' => ['--format' => 'JSON', '--only' => ['nosuch']]];
    }

    #[Test]
    public function test_a_usage_error_in_the_console_is_text(): void
    {
        $tester = new CommandTester($this->command());
        $status = $tester->execute(['--only' => ['nosuch']]);
        $display = $tester->getDisplay();

        $this->assertSame(2, $status);
        $this->assertStringContainsString('Unknown analyzer ids: nosuch.', $display);
        $this->assertNull(json_decode($display), 'Text, not JSON.');
    }

    #[Test]
    public function test_no_analyzer_at_all_prints_the_envelope(): void
    {
        $tester = new CommandTester(new AnalyzeCommand(new AnalyzerRegistry([]), new ConsoleReportFormatter(), new JsonReportFormatter()));
        $status = $tester->execute(['--format' => 'json']);

        $this->assertSame(2, $status);
        JsonContract::assertShape('error', JsonContract::decodeDocument($tester->getDisplay()));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, array<mixed>>
     */
    private function sections(array $options): array
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json', '--config' => [self::SECURITY_FIXTURE]] + $options);

        $sections = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($tester->getDisplay())['sections']) as $section) {
            $section = JsonContract::asArray($section);
            $this->assertIsString($section['id']);
            $sections[$section['id']] = $section;
        }

        return $sections;
    }

    private function command(): AnalyzeCommand
    {
        // The second route is shadowed by the first: one conflict to report.
        $collection = new RouteCollection();
        $collection->add('api_user_show', new Route('/users/{id}'));
        $collection->add('api_user_list', new Route('/users/list'));

        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

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

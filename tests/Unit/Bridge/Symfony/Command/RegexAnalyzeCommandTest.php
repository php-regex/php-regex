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

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Command;

use PhpRegex\Symfony\Analyzer\AnalysisContext;
use PhpRegex\Symfony\Analyzer\AnalysisIssue;
use PhpRegex\Symfony\Analyzer\AnalysisNotice;
use PhpRegex\Symfony\Analyzer\AnalyzerInterface;
use PhpRegex\Symfony\Analyzer\AnalyzerRegistry;
use PhpRegex\Symfony\Analyzer\CheckOutcome;
use PhpRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PhpRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PhpRegex\Symfony\Analyzer\IssueDetail;
use PhpRegex\Symfony\Analyzer\ReportSection;
use PhpRegex\Symfony\Command\AnalyzeCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RegexAnalyzeCommandTest extends TestCase
{
    #[Test]
    public function test_command_outputs_json_report(): void
    {
        $registry = new AnalyzerRegistry([
            new class implements AnalyzerInterface {
                public function getId(): string
                {
                    return 'routes';
                }

                public function getLabel(): string
                {
                    return 'Routes';
                }

                public function getPriority(): int
                {
                    return 10;
                }

                public function analyze(AnalysisContext $context): array
                {
                    return [
                        new ReportSection(
                            'routes',
                            'Routes',
                            meta: ['Routes' => 1],
                            summary: [new AnalysisNotice(CheckOutcome::Fail, '1 shadowed route detected.')],
                            issues: [
                                new AnalysisIssue(
                                    'shadowed',
                                    CheckOutcome::Fail,
                                    'demo (#1) -> demo (#2)',
                                    [new IssueDetail('Example', '/demo', 'example')],
                                ),
                            ],
                        ),
                    ];
                }
            },
        ]);

        $command = new AnalyzeCommand(
            $registry,
            new ConsoleReportFormatter(),
            new JsonReportFormatter(),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute(['--format' => 'json']);

        $this->assertSame(1, $status);

        $payload = json_decode((string) $tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('sections', $payload);
        $this->assertIsArray($payload['sections']);
        $this->assertIsArray($payload['sections'][0]);
        $this->assertSame('routes', $payload['sections'][0]['id']);
        $this->assertSame('Routes', $payload['sections'][0]['title']);
        $this->assertIsArray($payload['sections'][0]['issues']);
        $this->assertIsArray($payload['sections'][0]['issues'][0]);
        $this->assertSame('shadowed', $payload['sections'][0]['issues'][0]['kind']);
    }

    #[Test]
    public function test_command_allows_disabling_fail_on(): void
    {
        $registry = new AnalyzerRegistry([
            new class implements AnalyzerInterface {
                public function getId(): string
                {
                    return 'security';
                }

                public function getLabel(): string
                {
                    return 'Security';
                }

                public function getPriority(): int
                {
                    return 10;
                }

                public function analyze(AnalysisContext $context): array
                {
                    return [
                        new ReportSection(
                            'security',
                            'Security',
                            summary: [new AnalysisNotice(CheckOutcome::Fail, '1 finding.')],
                            issues: [new AnalysisIssue('redos', CheckOutcome::Fail, 'Firewall')],
                        ),
                    ];
                }
            },
        ]);

        $command = new AnalyzeCommand(
            $registry,
            new ConsoleReportFormatter(),
            new JsonReportFormatter(),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute(['--format' => 'json', '--fail-on' => ['none']]);

        $this->assertSame(0, $status);
    }

    #[Test]
    public function test_command_includes_debug_payload_when_requested(): void
    {
        $registry = new AnalyzerRegistry([
            new class implements AnalyzerInterface {
                public function getId(): string
                {
                    return 'routes';
                }

                public function getLabel(): string
                {
                    return 'Routes';
                }

                public function getPriority(): int
                {
                    return 10;
                }

                public function analyze(AnalysisContext $context): array
                {
                    return [
                        new ReportSection(
                            'routes',
                            'Routes',
                            meta: ['Routes' => 1],
                            debug: ['Pairs total' => 1],
                        ),
                    ];
                }
            },
        ]);

        $command = new AnalyzeCommand(
            $registry,
            new ConsoleReportFormatter(),
            new JsonReportFormatter(),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute(['--format' => 'json', '--debug' => true]);

        $this->assertSame(0, $status);

        $payload = json_decode((string) $tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('sections', $payload);
        $sections = $payload['sections'];
        $this->assertIsArray($sections);
        $this->assertIsArray($sections[0]);
        $this->assertIsArray($sections[0]['debug']);
        $this->assertArrayHasKey('Duration', $sections[0]['debug']);
        $this->assertSame(1, $sections[0]['debug']['Pairs total']);
    }
}

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

use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\AnalysisIssue;
use PHPRegex\Symfony\Analyzer\AnalysisNotice;
use PHPRegex\Symfony\Analyzer\AnalyzerInterface;
use PHPRegex\Symfony\Analyzer\CheckOutcome;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\ReportSection;
use PHPRegex\Symfony\Command\AnalyzeCommand;
use PHPRegex\Symfony\Exception\SecurityConfigException;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * How regex:analyze runs its analyzers and judges what they found: the
 * progress it shows, what it prints when quiet, which kinds fail the run,
 * and a security configuration it cannot read.
 */
final class AnalyzeCommandRunTest extends TestCase
{
    /**
     * On a terminal the command shows a progress bar over the analyzers,
     * each named as it runs; elsewhere one line counting them.
     */
    #[Test]
    public function test_a_decorated_run_shows_a_progress_bar_over_the_analyzers(): void
    {
        $tester = new CommandTester(self::command([self::analyzer('routes', 'Routes'), self::analyzer('security', 'Security')]));
        $status = $tester->execute([], ['decorated' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Analyzing 2 analyzers...', $display);
        $this->assertStringContainsString(' 2/2 [', $display);
        $this->assertStringContainsString('100% Security', $display);
    }

    #[Test]
    public function test_an_undecorated_run_counts_the_analyzers_on_one_line(): void
    {
        $tester = new CommandTester(self::command([self::analyzer('routes', 'Routes')]));
        $status = $tester->execute(['--format' => ''], ['decorated' => false]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Analyzing 1 analyzer...', $display);
        $this->assertStringNotContainsString('1/1 [', $display);
        $this->assertStringContainsString('Routes', $display);
    }

    /**
     * Quiet, the command prints no banner and no progress; the exit code
     * still says whether the report fails.
     */
    #[Test]
    public function test_a_quiet_run_prints_no_banner_and_still_fails_on_a_finding(): void
    {
        $tester = new CommandTester(self::command([self::analyzer('routes', 'Routes', [new AnalysisIssue('shadowed', CheckOutcome::Fail, 'a (#1) -> b (#2)')])]));
        $status = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_QUIET]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringNotContainsString('Analyzing', $tester->getDisplay());
    }

    /**
     * Which findings fail the run: the kinds named, "critical" for a
     * critical finding whatever its kind, "any" for any finding, "none"
     * for none; by default shadowed, redos and critical.
     *
     * @param list<string|null> $failOn
     */
    #[Test]
    #[DataProvider('provideFailOn')]
    public function test_the_kinds_named_decide_whether_the_run_fails(string $kind, CheckOutcome $severity, array $failOn, int $status): void
    {
        $tester = new CommandTester(self::command([self::analyzer('routes', 'Routes', [new AnalysisIssue($kind, $severity, 'a (#1) -> b (#2)')])]));

        $this->assertSame($status, $tester->execute(['--format' => 'json'] + ([] === $failOn ? [] : ['--fail-on' => $failOn])));
    }

    /**
     * @return iterable<string, array{kind: string, severity: CheckOutcome, failOn: list<string|null>, status: int}>
     */
    public static function provideFailOn(): iterable
    {
        yield 'an overlap, by default' => ['kind' => 'overlap', 'severity' => CheckOutcome::Warn, 'failOn' => [], 'status' => Command::SUCCESS];
        yield 'an overlap, overlaps named' => ['kind' => 'overlap', 'severity' => CheckOutcome::Warn, 'failOn' => ['OVERLAP'], 'status' => Command::FAILURE];
        yield 'an overlap, shadowed named' => ['kind' => 'overlap', 'severity' => CheckOutcome::Warn, 'failOn' => ['shadowed'], 'status' => Command::SUCCESS];
        yield 'a critical overlap, critical named' => ['kind' => 'overlap', 'severity' => CheckOutcome::Critical, 'failOn' => ['critical'], 'status' => Command::FAILURE];
        yield 'an overlap, critical named' => ['kind' => 'overlap', 'severity' => CheckOutcome::Warn, 'failOn' => ['critical'], 'status' => Command::SUCCESS];
        yield 'an overlap, any named' => ['kind' => 'overlap', 'severity' => CheckOutcome::Warn, 'failOn' => ['any'], 'status' => Command::FAILURE];
        yield 'a shadowed route, none named with it' => ['kind' => 'shadowed', 'severity' => CheckOutcome::Fail, 'failOn' => ['shadowed', 'none'], 'status' => Command::SUCCESS];
        yield 'a redos finding, by default' => ['kind' => 'redos', 'severity' => CheckOutcome::Fail, 'failOn' => [], 'status' => Command::FAILURE];
        // "--fail-on" given without a value, or with an empty one: the
        // default kinds.
        yield 'a shadowed route, fail-on without a value' => ['kind' => 'shadowed', 'severity' => CheckOutcome::Fail, 'failOn' => [null], 'status' => Command::FAILURE];
        yield 'an overlap, fail-on empty' => ['kind' => 'overlap', 'severity' => CheckOutcome::Warn, 'failOn' => [' '], 'status' => Command::SUCCESS];
    }

    #[Test]
    public function test_any_passes_a_run_without_findings(): void
    {
        $tester = new CommandTester(self::command([self::analyzer('routes', 'Routes')]));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json', '--fail-on' => ['any']]));
    }

    /**
     * A security configuration the analyzer cannot read stops the run: in
     * JSON the error envelope at the config stage, in the console an error
     * block, both a usage exit code.
     */
    #[Test]
    public function test_a_security_configuration_it_cannot_read_stops_the_run(): void
    {
        $failing = new class implements AnalyzerInterface {
            public function getId(): string
            {
                return 'security';
            }

            public function getLabel(): string
            {
                return 'Security';
            }

            public function analyze(AnalysisContext $context): array
            {
                throw new SecurityConfigException('The security configuration is invalid: no such key.');
            }
        };

        $json = new CommandTester(self::command([$failing]));
        $jsonStatus = $json->execute(['--format' => 'json']);
        $document = JsonContract::decodeDocument($json->getDisplay());

        $this->assertSame(Command::INVALID, $jsonStatus);
        JsonContract::assertShape('error', $document);
        $this->assertSame('config', $document['stage']);
        $this->assertSame('The security configuration is invalid: no such key.', $document['error'] ?? null);

        $console = new CommandTester(self::command([$failing]));
        $this->assertSame(Command::INVALID, $console->execute([]));
        $this->assertStringContainsString('The security configuration is invalid: no such key.', $console->getDisplay());
    }

    /**
     * @param list<AnalyzerInterface> $analyzers
     */
    private static function command(array $analyzers): AnalyzeCommand
    {
        return new AnalyzeCommand($analyzers, new ConsoleReportFormatter(), new JsonReportFormatter());
    }

    /**
     * @param list<AnalysisIssue> $issues
     */
    private static function analyzer(string $id, string $label, array $issues = []): AnalyzerInterface
    {
        return new class($id, $label, $issues) implements AnalyzerInterface {
            /**
             * @param list<AnalysisIssue> $issues
             */
            public function __construct(
                private readonly string $id,
                private readonly string $label,
                private readonly array $issues
            ) {}

            public function getId(): string
            {
                return $this->id;
            }

            public function getLabel(): string
            {
                return $this->label;
            }

            public function analyze(AnalysisContext $context): array
            {
                $summary = [] === $this->issues
                    ? [new AnalysisNotice(CheckOutcome::Pass, 'Nothing found.')]
                    : [new AnalysisNotice(CheckOutcome::Fail, \count($this->issues).' findings.')];

                return [new ReportSection($this->id, $this->label, summary: $summary, issues: $this->issues)];
            }
        };
    }
}

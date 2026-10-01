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
use PhpRegex\Symfony\Analyzer\AnalyzerInterface;
use PhpRegex\Symfony\Analyzer\AnalyzerRegistry;
use PhpRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PhpRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PhpRegex\Symfony\Command\AnalyzeCommand;
use PhpRegex\Symfony\Command\SecurityCommand;
use PhpRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PhpRegex\Symfony\Security\SecurityConfigExtractor;
use PhpRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * regex:analyze and regex:security read --redos-threshold with the one
 * threshold parser: "safe" (accepted by 1.x) and "unknown" are refused.
 */
final class RedosThresholdOptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedThresholds(): iterable
    {
        yield 'safe' => ['safe'];
        yield 'unknown' => ['unknown'];
        yield 'a word that is no severity' => ['severe'];
    }

    #[Test]
    #[DataProvider('provideRefusedThresholds')]
    public function test_analyze_refuses_a_threshold_that_names_no_severity(string $threshold): void
    {
        $tester = new CommandTester($this->analyzeCommand());

        $status = $tester->execute(['--redos-threshold' => $threshold]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('"'.$threshold.'"', $tester->getDisplay());
    }

    #[Test]
    #[DataProvider('provideRefusedThresholds')]
    public function test_security_refuses_a_threshold_that_names_no_severity(string $threshold): void
    {
        $tester = new CommandTester($this->securityCommand());

        $status = $tester->execute(['--redos-threshold' => $threshold]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('"'.$threshold.'"', $tester->getDisplay());
    }

    #[Test]
    public function test_security_reads_a_threshold_in_any_case(): void
    {
        $path = \dirname(__DIR__, 4).'/Fixtures/Symfony/security_access_control.yaml';
        $tester = new CommandTester($this->securityCommand());

        $tester->execute(['--config' => [$path], '--redos-threshold' => 'CRITICAL']);

        $this->assertStringNotContainsString('--redos-threshold', $tester->getDisplay());
        $this->assertStringContainsString('Access Control Conflicts', $tester->getDisplay());
    }

    private function analyzeCommand(): AnalyzeCommand
    {
        $analyzer = new class implements AnalyzerInterface {
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
                return [];
            }
        };

        return new AnalyzeCommand(new AnalyzerRegistry([$analyzer]), new ConsoleReportFormatter(), new JsonReportFormatter());
    }

    private function securityCommand(): SecurityCommand
    {
        return new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );
    }
}

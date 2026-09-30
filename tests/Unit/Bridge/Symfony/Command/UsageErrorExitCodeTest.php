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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Bridge\Symfony\Analyzer\AnalysisContext;
use RegexParser\Bridge\Symfony\Analyzer\AnalyzerInterface;
use RegexParser\Bridge\Symfony\Analyzer\AnalyzerRegistry;
use RegexParser\Bridge\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use RegexParser\Bridge\Symfony\Analyzer\Formatter\JsonReportFormatter;
use RegexParser\Bridge\Symfony\Command\CompareCommand;
use RegexParser\Bridge\Symfony\Command\RegexAnalyzeCommand;
use RegexParser\Bridge\Symfony\Command\RegexSecurityCommand;
use RegexParser\Bridge\Symfony\Command\RegexTranspileCommand;
use RegexParser\Bridge\Symfony\Security\SecurityAccessControlAnalyzer;
use RegexParser\Bridge\Symfony\Security\SecurityConfigExtractor;
use RegexParser\Bridge\Symfony\Security\SecurityFirewallAnalyzer;
use RegexParser\Regex;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The console commands of the Symfony bridge exit with Command::INVALID (2)
 * when the command line or the configuration cannot be used, and keep
 * Command::FAILURE (1) for the patterns they judged.
 */
final class UsageErrorExitCodeTest extends TestCase
{
    /**
     * @param \Closure(): Command  $command
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('provideUsageErrors')]
    public function test_a_usage_error_exits_invalid(\Closure $command, array $input): void
    {
        $tester = new CommandTester($command());

        $this->assertSame(Command::INVALID, $tester->execute($input));
    }

    /**
     * @return iterable<string, array{\Closure(): Command, array<string, mixed>}>
     */
    public static function provideUsageErrors(): iterable
    {
        $analyze = static fn (): Command => self::analyzeCommand();
        $security = static fn (): Command => self::securityCommand();
        $transpile = static fn (): Command => new RegexTranspileCommand(Regex::create());
        $compare = static fn (): Command => new CompareCommand(Regex::create());

        yield 'regex:analyze with an unknown --format' => [$analyze, ['--format' => 'xml']];
        yield 'regex:analyze with an unknown analyzer' => [$analyze, ['--only' => ['nosuch']]];
        yield 'regex:analyze with an unknown --fail-on' => [$analyze, ['--fail-on' => ['nosuch']]];
        yield 'regex:analyze with an unknown --redos-threshold' => [$analyze, ['--redos-threshold' => 'severe']];
        yield 'regex:analyze with no analyzer at all' => [static fn (): Command => self::analyzeCommand([]), []];
        yield 'regex:security with an unknown --redos-threshold' => [$security, ['--redos-threshold' => 'severe']];
        yield 'regex:security with --config files that do not exist' => [$security, ['--config' => ['missing/security.yaml']]];
        yield 'regex:transpile with an unknown --target' => [$transpile, ['pattern' => '/a/', '--target' => 'perl']];
        yield 'regex:compare with an unknown --method' => [$compare, ['pattern1' => '/a/', 'pattern2' => '/b/', '--method' => 'nosuch']];
        yield 'regex:compare with an unknown --minimizer' => [$compare, ['pattern1' => '/a/', 'pattern2' => '/b/', '--minimizer' => 'nosuch']];
        yield 'regex:compare with an unknown --determinizer' => [$compare, ['pattern1' => '/a/', 'pattern2' => '/b/', '--determinizer' => 'nosuch']];
        yield 'regex:compare with an empty first pattern' => [$compare, ['pattern1' => '', 'pattern2' => '/b/']];
        yield 'regex:compare with an empty second pattern' => [$compare, ['pattern1' => '/a/', 'pattern2' => '']];
        yield 'regex:compare with an empty --method' => [$compare, ['pattern1' => '/a/', 'pattern2' => '/b/', '--method' => '']];
        yield 'regex:transpile with no pattern' => [$transpile, ['pattern' => null]];
        yield 'regex:security with no security configuration to read' => [$security, []];
    }

    #[Test]
    public function test_a_pattern_the_command_judges_still_exits_failure(): void
    {
        $tester = new CommandTester(new RegexTranspileCommand(Regex::create()));

        $this->assertSame(Command::FAILURE, $tester->execute(['pattern' => '/(/']));
    }

    #[Test]
    public function test_patterns_that_differ_still_exit_failure(): void
    {
        $tester = new CommandTester(new CompareCommand(Regex::create()));

        $this->assertSame(Command::FAILURE, $tester->execute(['pattern1' => '/a/', 'pattern2' => '/b/', '--method' => 'equivalence']));
    }

    /**
     * @param list<AnalyzerInterface>|null $analyzers
     */
    private static function analyzeCommand(?array $analyzers = null): RegexAnalyzeCommand
    {
        $analyzers ??= [new class implements AnalyzerInterface {
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
        }];

        return new RegexAnalyzeCommand(new AnalyzerRegistry($analyzers), new ConsoleReportFormatter(), new JsonReportFormatter());
    }

    private static function securityCommand(): RegexSecurityCommand
    {
        return new RegexSecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );
    }
}

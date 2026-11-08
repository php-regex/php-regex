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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Security;

use PHPRegex\Redos\RedosWitness;
use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\AnalysisIssue;
use PHPRegex\Symfony\Analyzer\CheckOutcome;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\IssueDetail;
use PHPRegex\Symfony\Analyzer\ReportSection;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Analyzer\SecurityReport;
use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Text that comes from a pattern reaches the console as text: a value that
 * looks like console markup prints literally instead of being read as a
 * style (and swallowed).
 */
final class SecurityMarkupEscapingTest extends TestCase
{
    use TemporaryProject;

    /**
     * ^(<info>|<info>)+$ reads "<info>" two ways: <info>…<info>! fails at
     * n=19 (PCRE2 10.49, JIT on and off), so the witness carries the markup.
     */
    private const FIREWALL_PATTERN = '^(<info>|<info>)+$';

    #[Test]
    #[DataProvider('provideUnsafeDetails')]
    public function test_report_details_cannot_inject_terminal_controls(string $kind): void
    {
        $value = "é<error>x</error>\nPASS\r\t\x1B[2J\x00\x7F\u{202E}";
        $detail = new IssueDetail('Value', $value, $kind);
        $report = new SecurityReport([new ReportSection('security', 'Security', issues: [
            new AnalysisIssue('test', CheckOutcome::Warn, 'Finding', [$detail]),
        ])]);
        $buffer = new BufferedOutput();
        (new ConsoleReportFormatter())->render($report, new SymfonyStyle(new ArrayInput([]), $buffer), false);
        $display = $buffer->fetch();

        $this->assertStringContainsString('é<error>x</error>\\nPASS\\r\\t\\x1B[2J\\x00\\x7F\\x{202E}', $display);
        $this->assertSame($value, $detail->value);
    }

    #[Test]
    public function test_yaml_roles_are_escaped_only_for_console_display(): void
    {
        $project = $this->makeProject(['security.yaml' => <<<'YAML'
            security:
              access_control:
                - { path: ^/, roles: PUBLIC_ACCESS }
                - { path: ^/admin, roles: "ROLE_ADMIN\nFORGED\e[2J" }
            YAML]);
        $analyzer = new SecurityAnalyzer(
            new SecurityConfigExtractor(),
            new SecurityConfigLocator(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );
        $report = new SecurityReport($analyzer->analyze(new AnalysisContext(
            $project, null, securityConfigPaths: [$project.'/security.yaml'],
        )));
        $buffer = new BufferedOutput();
        (new ConsoleReportFormatter())->render($report, new SymfonyStyle(new ArrayInput([]), $buffer), false);
        $display = $buffer->fetch();
        $this->assertStringContainsString('ROLE_ADMIN\\nFORGED\\x1B[2J', $display);
        $this->assertStringNotContainsString("\x1B[2J", $display);
        $this->assertStringNotContainsString("\nFORGED", $display);
        // The JSON keeps the role as written: its newline and escape encoded, not spelled.
        $this->assertStringContainsString('ROLE_ADMIN\\nFORGED\\u001b[2J', (new JsonReportFormatter())->format($report));
    }

    /**
     * @return iterable<string, array{kind: string}>
     */
    public static function provideUnsafeDetails(): iterable
    {
        yield 'text' => ['kind' => 'text'];
        yield 'pattern' => ['kind' => 'pattern'];
    }

    #[Test]
    public function test_console_report_prints_a_text_detail_literally(): void
    {
        $report = new SecurityReport([new ReportSection('security_firewall', 'Security Firewall Regex', issues: [
            new AnalysisIssue('redos', CheckOutcome::Critical, 'main', [new IssueDetail('Verdict', '<error>x</error>')]),
        ])]);

        $buffer = new BufferedOutput();
        (new ConsoleReportFormatter())->render($report, new SymfonyStyle(new ArrayInput([]), $buffer), false);

        $this->assertStringContainsString('Verdict: <error>x</error>', $buffer->fetch());
    }

    #[Test]
    public function test_the_console_report_prints_a_pattern_detail_literally(): void
    {
        $report = new SecurityReport([new ReportSection('security_firewall', 'Security Firewall Regex', issues: [
            new AnalysisIssue('redos', CheckOutcome::Critical, 'main', [new IssueDetail('Pattern', '(<error>x</error>)+', 'pattern')]),
        ])]);

        $buffer = new BufferedOutput();
        (new ConsoleReportFormatter())->render($report, new SymfonyStyle(new ArrayInput([]), $buffer), false);

        $this->assertStringContainsString('(<error>x</error>)+', $buffer->fetch());
    }

    #[Test]
    public function test_the_security_command_prints_the_pattern_literally(): void
    {
        $project = $this->makeProject(['security.yaml' => "security:\n  firewalls:\n    main:\n      pattern: ".self::FIREWALL_PATTERN."\n"]);

        $tester = new CommandTester(new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
            new SecurityConfigLocator(),
            new SecurityAccessSuggestionBuilder(),
            null,
            'high',
        ));
        $tester->execute(['--config' => [$project.'/security.yaml']]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Pattern:', $display);
        $this->assertStringContainsString(self::FIREWALL_PATTERN, $display, 'the firewall pattern itself must survive as written');
    }

    #[Test]
    public function test_security_command_prints_a_markup_witness_literally(): void
    {
        $witness = Regex::create()->redos('#'.self::FIREWALL_PATTERN.'#')->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);
        $this->assertStringContainsString('<info>', $witness->render());

        // A plain YAML scalar: the extractor keeps the quotes of a quoted one as part of the pattern.
        $project = $this->makeProject(['security.yaml' => "security:\n  firewalls:\n    main:\n      pattern: ".self::FIREWALL_PATTERN."\n"]);

        $tester = new CommandTester(new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
            new SecurityConfigLocator(),
            new SecurityAccessSuggestionBuilder(),
            null,
            'high',
        ));
        $tester->execute(['--config' => [$project.'/security.yaml']]);

        $this->assertStringContainsString('Attack: '.$witness->render(), $tester->getDisplay());
    }
}

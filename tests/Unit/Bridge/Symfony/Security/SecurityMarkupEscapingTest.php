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
use PHPRegex\Symfony\Analyzer\AnalysisIssue;
use PHPRegex\Symfony\Analyzer\CheckOutcome;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\IssueDetail;
use PHPRegex\Symfony\Analyzer\ReportSection;
use PHPRegex\Symfony\Analyzer\SecurityReport;
use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
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

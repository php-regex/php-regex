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

use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\AnalysisIssue;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\IssueDetail;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Analyzer\SecurityReport;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The firewall section of the "security" analyzer ("regex:analyze"):
 * the issue names the verdict and carries the attack, in the console and
 * the JSON report alike.
 *
 * The fixture's firewall pattern ^/api/(a+)+$ makes preg_match() fail on
 * "/api/" followed by 19 "a" and "!" (PCRE2 10.49, JIT on and off).
 */
final class SecurityAnalyzerRedosVerdictTest extends TestCase
{
    #[Test]
    public function test_firewall_issue_details_carry_the_headline_and_the_attack(): void
    {
        $details = $this->firewallIssue()->details;

        $values = array_map(static fn (IssueDetail $detail): string => $detail->value, $details);
        $this->assertContains('Exponential backtracking (proven)', $values);

        $verdict = array_values(array_filter($details, static fn (IssueDetail $detail): bool => 'Verdict' === $detail->label));
        $this->assertCount(1, $verdict);
        $this->assertSame('Exponential backtracking (proven)', $verdict[0]->value);

        $attack = array_values(array_filter($details, static fn (IssueDetail $detail): bool => 'Attack' === $detail->label));
        $this->assertCount(1, $attack);
        $this->assertSame(self::attack(), $attack[0]->value);
        $this->assertSame('text', $attack[0]->kind);
    }

    #[Test]
    public function test_console_report_prints_the_headline_and_the_attack_line(): void
    {
        $buffer = new BufferedOutput();
        (new ConsoleReportFormatter())->render($this->report(), new SymfonyStyle(new ArrayInput([]), $buffer), false);
        $display = $buffer->fetch();

        $this->assertStringContainsString('Exponential backtracking (proven)', $display);
        $this->assertStringContainsString('Attack: '.self::attack(), $display);
    }

    #[Test]
    public function test_json_report_carries_the_headline_and_the_attack(): void
    {
        $payload = json_decode((new JsonReportFormatter())->format($this->report()), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['sections']);

        $details = [];
        foreach ($payload['sections'] as $section) {
            $this->assertIsArray($section);
            if ('security_firewall' !== $section['id']) {
                continue;
            }
            $this->assertIsArray($section['issues']);
            foreach ($section['issues'] as $issue) {
                $this->assertIsArray($issue);
                $this->assertIsArray($issue['details']);
                foreach ($issue['details'] as $detail) {
                    $this->assertIsArray($detail);
                    $details[] = ['label' => $detail['label'], 'value' => $detail['value']];
                }
            }
        }

        $this->assertContains('Exponential backtracking (proven)', array_column($details, 'value'));
        $this->assertContains(['label' => 'Verdict', 'value' => 'Exponential backtracking (proven)'], $details);
        $this->assertContains(['label' => 'Attack', 'value' => self::attack()], $details);
    }

    private function firewallIssue(): AnalysisIssue
    {
        foreach ($this->report()->sections as $section) {
            if ('security_firewall' === $section->id) {
                $this->assertCount(1, $section->issues);

                return $section->issues[0];
            }
        }

        $this->fail('No firewall section in the security report.');
    }

    private function report(): SecurityReport
    {
        $analyzer = new SecurityAnalyzer(
            new SecurityConfigExtractor(),
            new SecurityConfigLocator(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );

        return new SecurityReport($analyzer->analyze(new AnalysisContext(
            null,
            null,
            securityConfigPaths: [\dirname(__DIR__, 4).'/Fixtures/Symfony/security_access_control.yaml'],
        )));
    }

    private static function attack(): string
    {
        $witness = Regex::create()->redos('#^/api/(a+)+$#')->witness;
        self::assertNotNull($witness);

        return $witness->render();
    }
}

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

use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The firewall ReDoS findings of "regex:security" carry the verdict
 * headline and the attack, as every other ReDoS surface.
 *
 * The fixture's firewall pattern ^/api/(a+)+$ makes preg_match() fail on
 * "/api/" followed by 19 "a" and "!" (PCRE2 10.49, JIT on and off).
 */
final class SecurityFirewallRedosVerdictTest extends TestCase
{
    private const FIREWALL_PATTERN = '^/api/(a+)+$';

    #[Test]
    public function test_firewall_finding_carries_the_verdict_headline(): void
    {
        $report = (new SecurityFirewallAnalyzer(Regex::create()))->analyze([[
            'name' => 'main',
            'file' => 'security.yaml',
            'line' => 1,
            'pattern' => self::FIREWALL_PATTERN,
            'requestMatcher' => null,
        ]], RedosSeverity::High);

        $this->assertCount(1, $report->findings);
        $this->assertContains('Exponential backtracking (proven)', $report->findings[0]);
        $this->assertArrayHasKey('verdict', $report->findings[0]);
        $this->assertSame('Exponential backtracking (proven)', $report->findings[0]['verdict']);
    }

    #[Test]
    public function test_security_command_prints_the_headline_and_the_attack(): void
    {
        $tester = new CommandTester(new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
            new SecurityConfigLocator(),
            new SecurityAccessSuggestionBuilder(),
            null,
            'high',
        ));

        $tester->execute(['--config' => [self::fixture()]]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Exponential backtracking (proven)', $display);
        $this->assertStringContainsString('Attack: '.self::attack(), $display);
    }

    private static function attack(): string
    {
        $witness = Regex::create()->redos('#'.self::FIREWALL_PATTERN.'#')->witness;
        self::assertNotNull($witness);

        return $witness->render();
    }

    private static function fixture(): string
    {
        return \dirname(__DIR__, 4).'/Fixtures/Symfony/security_access_control.yaml';
    }
}

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

use PhpRegex\Symfony\Command\SecurityCommand;
use PhpRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PhpRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PhpRegex\Symfony\Security\SecurityConfigExtractor;
use PhpRegex\Symfony\Security\SecurityConfigLocator;
use PhpRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RegexSecurityCommandTest extends TestCase
{
    #[Test]
    public function test_command_reports_security_conflicts(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_access_control.yaml';

        $command = new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
            new SecurityConfigLocator(),
            new SecurityAccessSuggestionBuilder(),
            null,
            'high',
        );

        $tester = new CommandTester($command);
        $status = $tester->execute(['--config' => [$path]]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Access Control Conflicts', (string) $tester->getDisplay());
        $this->assertStringContainsString('Firewall Regex ReDoS', (string) $tester->getDisplay());
    }
}

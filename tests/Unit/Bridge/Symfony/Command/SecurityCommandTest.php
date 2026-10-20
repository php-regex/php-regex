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

use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Tests\Support\SecurityConfigFunctionOverrides;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class SecurityCommandTest extends TestCase
{
    use TemporaryProject;

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

    #[Test]
    public function test_command_reads_the_recipe_layout_past_the_dev_firewall(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_recipe_layout.yaml';

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
        $display = (string) $tester->getDisplay();

        $this->assertSame(1, $status, $display);
        $this->assertMatchesRegularExpression('/Rules\s*:\s*2\b/', $display);
        $this->assertMatchesRegularExpression('/Firewalls\s*:\s*3\b/', $display);
        $this->assertStringContainsString('api', $display);
    }

    #[Test]
    public function test_command_passes_a_configuration_with_no_security_entries(): void
    {
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
        $status = $tester->execute(['--config' => [dirname(__DIR__, 4).'/Fixtures/Symfony/routes_only.yaml']]);
        $display = (string) $tester->getDisplay();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No security configuration entries found.', $display);
        $this->assertStringContainsString('If PHPRegex helps, a GitHub star is appreciated', $display);
    }

    /**
     * The flow sequence a line scanner read as no rule at all: the second
     * rule is shadowed by the first.
     */
    #[Test]
    public function test_command_reads_an_access_control_written_as_a_flow_sequence(): void
    {
        $path = $this->makeProject([
            'security.yaml' => "security:\n    access_control: [{ path: ^/admin, roles: ROLE_ADMIN }, { path: ^/admin/public, roles: PUBLIC_ACCESS }]\n",
        ]).'/security.yaml';

        $tester = new CommandTester(self::command());
        $status = $tester->execute(['--config' => [$path]]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertStringContainsString('1 shadowed rules detected.', $display);
    }

    #[Test]
    public function test_command_refuses_a_named_file_that_is_no_yaml(): void
    {
        $path = $this->makeProject(['security.php' => "<?php\n"]).'/security.php';

        $tester = new CommandTester(self::command());
        $status = $tester->execute(['--config' => [$path]]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('Only YAML files can be read with --config', $tester->getDisplay());
    }

    #[Test]
    public function test_command_refuses_a_named_file_without_symfony_yaml(): void
    {
        SecurityConfigFunctionOverrides::$missingClasses = [Yaml::class];
        $path = $this->makeProject(['security.yaml' => "security:\n    access_control: [{ path: ^/admin }]\n"]).'/security.yaml';

        $tester = new CommandTester(self::command());
        $status = $tester->execute(['--config' => [$path]]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('needs symfony/yaml', $tester->getDisplay());
    }

    #[Test]
    public function test_command_refuses_a_project_with_no_security_configuration(): void
    {
        $this->enterProject();

        $tester = new CommandTester(self::command());
        $status = $tester->execute([]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('No security configuration found', $tester->getDisplay());
    }

    #[After]
    protected function restoreYaml(): void
    {
        SecurityConfigFunctionOverrides::reset();
    }

    private static function command(): SecurityCommand
    {
        return new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );
    }
}

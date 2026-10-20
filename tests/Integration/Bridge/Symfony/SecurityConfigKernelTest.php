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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Symfony\Analyzer\AnalyzerRegistry;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Command\AnalyzeCommand;
use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityConfigException;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Tests\Support\SecurityConfigKernel;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Inside an application the security configuration is the one the kernel
 * processes, as debug:config shows it: every file format the kernel loads,
 * every YAML shape, the parameters resolved.
 */
final class SecurityConfigKernelTest extends TestCase
{
    use PhpUnitForwardCompat;
    use TemporaryProject;

    private const FIREWALLS = "    firewalls:\n        main:\n            pattern: ^/\n";

    /**
     * @param array<string, string>                    $files
     * @param list<array{0: ?string, 1: list<string>}> $rules
     * @param array<string, ?string>                   $firewalls
     */
    #[Test]
    #[DataProvider('provideConfigurations')]
    public function test_the_kernel_configuration_is_read_whatever_its_shape(array $files, array $rules, array $firewalls): void
    {
        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($this->makeProject($files)));

        $this->assertNotNull($config);
        $this->assertSame($rules, array_map(
            static fn (array $rule): array => [$rule['path'], $rule['roles']],
            $config['accessControl'],
        ));
        $this->assertSame($firewalls, array_column($config['firewalls'], 'pattern', 'name'));
    }

    /**
     * @return iterable<string, array{files: array<string, string>, rules: list<array{0: ?string, 1: list<string>}>, firewalls: array<string, ?string>}>
     */
    public static function provideConfigurations(): iterable
    {
        $shadowing = [['^/admin', ['ROLE_ADMIN']], ['^/admin/public', ['PUBLIC_ACCESS']]];

        yield 'block style' => [
            'files' => ['config/packages/security.yaml' => "security:\n".self::FIREWALLS
                ."    access_control:\n"
                ."        - path: ^/admin\n          roles: ROLE_ADMIN\n"
                ."        - path: ^/admin/public\n          roles: PUBLIC_ACCESS\n"],
            'rules' => $shadowing,
            'firewalls' => ['main' => '^/'],
        ];

        yield 'flow sequence on one line' => [
            'files' => ['config/packages/security.yaml' => "security:\n".self::FIREWALLS
                ."    access_control: [{ path: ^/admin, roles: ROLE_ADMIN }, { path: ^/admin/public, roles: PUBLIC_ACCESS }]\n"],
            'rules' => $shadowing,
            'firewalls' => ['main' => '^/'],
        ];

        yield 'flow sequence over several lines' => [
            'files' => ['config/packages/security.yaml' => "security:\n".self::FIREWALLS
                ."    access_control: [\n"
                ."        { path: ^/admin, roles: ROLE_ADMIN },\n"
                ."        { path: ^/admin/public, roles: PUBLIC_ACCESS },\n"
                ."    ]\n"],
            'rules' => $shadowing,
            'firewalls' => ['main' => '^/'],
        ];

        yield 'multiline values and a block list of roles' => [
            'files' => ['config/packages/security.yaml' => "security:\n"
                ."    firewalls:\n        api:\n            pattern: >-\n                ^/api/(\\w+/?)+$\n"
                ."    access_control:\n"
                ."        - path: \"^/admin\n            /reports\"\n          roles:\n              - ROLE_ADMIN\n              - ROLE_AUDITOR\n"
                ."          allow_if: |\n              is_granted('ROLE_ADMIN')\n"],
            'rules' => [['^/admin /reports', ['ROLE_ADMIN', 'ROLE_AUDITOR']]],
            'firewalls' => ['api' => '^/api/(\w+/?)+$'],
        ];

        yield 'anchors, aliases and merge keys' => [
            'files' => ['config/packages/security.yaml' => "security:\n"
                ."    firewalls:\n        main: &main\n            pattern: ^/\n        api:\n            <<: *main\n            pattern: ^/api\n"
                ."    access_control:\n"
                ."        - &admin { path: ^/admin, roles: ROLE_ADMIN }\n"
                ."        - <<: *admin\n          path: ^/admin/public\n          roles: PUBLIC_ACCESS\n"
                ."        - *admin\n"],
            'rules' => [...$shadowing, ['^/admin', ['ROLE_ADMIN']]],
            'firewalls' => ['main' => '^/', 'api' => '^/api'],
        ];

        yield 'parameters' => [
            'files' => [
                'config/services.yaml' => "parameters:\n    admin_path: ^/admin\n    public_path: '%admin_path%/public'\n    api_pattern: ^/api\n",
                'config/packages/security.yaml' => "security:\n"
                    ."    firewalls:\n        api:\n            pattern: '%api_pattern%'\n"
                    ."    access_control:\n"
                    ."        - { path: '%admin_path%', roles: ROLE_ADMIN }\n"
                    ."        - { path: '%public_path%', roles: PUBLIC_ACCESS }\n"
                    ."        - { path: ^/100%%, roles: ROLE_USER }\n",
            ],
            'rules' => [...$shadowing, ['^/100%', ['ROLE_USER']]],
            'firewalls' => ['api' => '^/api'],
        ];

        yield 'environment blocks' => [
            'files' => ['config/packages/security.yaml' => "security:\n".self::FIREWALLS
                ."when@prod:\n    security:\n        access_control:\n            - { path: ^/prod, roles: ROLE_ADMIN }\n"
                ."when@test:\n    security:\n        access_control:\n            - { path: ^/test, roles: ROLE_ADMIN }\n"],
            'rules' => [['^/test', ['ROLE_ADMIN']]],
            'firewalls' => ['main' => '^/'],
        ];

        yield 'PHP configuration' => [
            'files' => ['config/packages/security.php' => <<<'PHP'
                <?php

                use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

                return static function (ContainerConfigurator $container): void {
                    $container->parameters()->set('admin_path', '^/admin');
                    $container->extension('security', [
                        'firewalls' => ['main' => ['pattern' => ['^/app', '^/api']]],
                        'access_control' => [
                            ['path' => '%admin_path%', 'roles' => 'ROLE_ADMIN'],
                            ['path' => '^/admin/public', 'roles' => 'PUBLIC_ACCESS'],
                        ],
                    ]);
                };
                PHP],
            'rules' => $shadowing,
            'firewalls' => ['main' => '(?:^/app|^/api)'],
        ];
    }

    #[Test]
    public function test_each_entry_is_located_in_the_file_that_writes_it(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_path: ^/admin\n",
            'config/packages/security.yaml' => "security:\n"
                ."    firewalls:\n"
                ."        dev:\n"
                ."            pattern: ^/admin\n"
                ."            security: false\n"
                ."        main:\n"
                ."            lazy: true\n"
                ."    access_control:\n"
                ."        - { path: '%admin_path%', roles: ROLE_ADMIN }\n"
                ."        - { path: ^/admin, roles: ROLE_USER }\n"
                ."        - { host: ^admin\\.example\\.com$, roles: ROLE_ADMIN }\n",
        ]);

        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
        $this->assertNotNull($config);

        $file = $project.'/config/packages/security.yaml';
        $this->assertSame(
            [[$file, 9], [$file, 10], [$file, 11]],
            array_map(static fn (array $rule): array => [$rule['file'], $rule['line']], $config['accessControl']),
        );
        $this->assertSame(
            [[$file, 4], [$file, 6]],
            array_map(static fn (array $firewall): array => [$firewall['file'], $firewall['line']], $config['firewalls']),
        );
    }

    #[Test]
    public function test_an_entry_no_file_writes_has_no_location(): void
    {
        $project = $this->makeProject([
            'config/packages/app.php' => <<<'PHP'
                <?php

                use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

                return static function (ContainerConfigurator $container): void {
                    $container->extension('security', [
                        'firewalls' => ['main' => ['pattern' => '^/']],
                        'access_control' => [['path' => '^/admin', 'roles' => 'ROLE_ADMIN']],
                    ]);
                };
                PHP,
        ]);

        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
        $this->assertNotNull($config);

        $this->assertSame(['', 0], [$config['accessControl'][0]['file'], $config['accessControl'][0]['line']]);
        $this->assertSame(['', 0], [$config['firewalls'][0]['file'], $config['firewalls'][0]['line']]);
    }

    /**
     * A file named with --config takes the parameters it does not define
     * from the application, once the kernel is booted.
     */
    #[Test]
    public function test_a_named_file_takes_its_parameters_from_the_booted_kernel(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_path: ^/admin\n",
            'security.yaml' => "security:\n    access_control: [{ path: '%admin_path%' }]\n",
        ]);
        $kernel = new SecurityConfigKernel($project, withSecurityBundle: false);
        $kernel->boot();

        $config = (new SecurityConfigExtractor())->read([$project.'/security.yaml'], $kernel, new SecurityConfigLocator(), $project, 'test');

        $this->assertNotNull($config);
        $this->assertSame(['^/admin'], array_column($config['accessControl'], 'path'));
    }

    #[Test]
    public function test_a_kernel_not_booted_lends_a_named_file_no_parameter(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_path: ^/admin\n",
            'security.yaml' => "security:\n    access_control: [{ path: '%admin_path%' }]\n",
        ]);

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('admin_path');

        (new SecurityConfigExtractor())->read([$project.'/security.yaml'], new SecurityConfigKernel($project, withSecurityBundle: false), new SecurityConfigLocator(), $project, 'test');
    }

    #[Test]
    public function test_a_kernel_without_the_security_bundle_has_no_security_configuration(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject(['config/services.yaml' => "parameters: {}\n"]), withSecurityBundle: false);

        $this->assertNull((new SecurityConfigExtractor())->extractFromKernel($kernel));
    }

    #[Test]
    public function test_a_security_bundle_left_unconfigured_has_no_security_configuration(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject(['config/services.yaml' => "parameters: {}\n"]));

        $this->assertNull((new SecurityConfigExtractor())->extractFromKernel($kernel));
    }

    #[Test]
    public function test_a_parameter_the_kernel_does_not_define_is_an_error(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n        - { path: '%missing_path%' }\n",
        ]));

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('missing_path');

        (new SecurityConfigExtractor())->extractFromKernel($kernel);
    }

    #[Test]
    public function test_a_configuration_the_security_bundle_refuses_is_an_error(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/security.yaml' => "security:\n    access_control:\n        - { path: ^/admin, unknown_key: true }\n",
        ]));

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('unknown_key');

        (new SecurityConfigExtractor())->extractFromKernel($kernel);
    }

    /**
     * The flow sequence the line scanner could not read: the second rule is
     * shadowed by the first, and the command says so.
     */
    #[Test]
    public function test_the_security_command_reads_the_kernel_configuration(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_path: ^/admin\n",
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS
                ."    access_control: [{ path: '%admin_path%', roles: ROLE_ADMIN }, { path: ^/admin/public, roles: PUBLIC_ACCESS }]\n",
        ]));

        $tester = new CommandTester(self::securityCommand($kernel));
        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertMatchesRegularExpression('/Rules\s*:\s*2\b/', $display);
        $this->assertStringContainsString('1 shadowed rules detected.', $display);
        $this->assertStringContainsString('config/packages/security.yaml:5', $display);
    }

    #[Test]
    public function test_the_security_command_names_no_line_for_an_entry_no_security_file_writes(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/app.php' => <<<'PHP'
                <?php

                use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

                return static function (ContainerConfigurator $container): void {
                    $container->extension('security', [
                        'firewalls' => ['main' => ['pattern' => '^/']],
                        'access_control' => [
                            ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                            ['path' => '^/admin/public', 'roles' => 'PUBLIC_ACCESS'],
                        ],
                    ]);
                };
                PHP,
        ]));

        $tester = new CommandTester(self::securityCommand($kernel));
        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertStringContainsString('#2 security configuration', $display);
        $this->assertStringNotContainsString(':0', $display);
    }

    #[Test]
    public function test_the_security_command_refuses_a_kernel_with_no_security_configuration(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject(['config/services.yaml' => "parameters: {}\n"]), withSecurityBundle: false);

        $tester = new CommandTester(self::securityCommand($kernel));
        $status = $tester->execute([]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('No security configuration found', $tester->getDisplay());
    }

    #[Test]
    public function test_the_security_command_reports_a_configuration_it_cannot_read(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n        - { path: '%missing_path%' }\n",
        ]));

        $tester = new CommandTester(self::securityCommand($kernel));
        $status = $tester->execute([]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('missing_path', $tester->getDisplay());
    }

    #[Test]
    public function test_the_analyze_command_reads_the_kernel_configuration(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/security.php' => <<<'PHP'
                <?php

                use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

                return static function (ContainerConfigurator $container): void {
                    $container->extension('security', [
                        'firewalls' => ['main' => ['pattern' => '^/']],
                        'access_control' => [
                            ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                            ['path' => '^/admin/public', 'roles' => 'PUBLIC_ACCESS'],
                        ],
                    ]);
                };
                PHP,
        ]));

        $tester = new CommandTester(self::analyzeCommand($kernel));
        $status = $tester->execute(['--format' => 'json']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertStringContainsString('"kind": "shadowed"', $display);
    }

    #[Test]
    public function test_the_analyze_command_stops_on_a_configuration_it_cannot_read(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n        - { path: '%missing_path%' }\n",
        ]));

        $tester = new CommandTester(self::analyzeCommand($kernel));
        $status = $tester->execute(['--format' => 'json']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::INVALID, $status, $display);
        $this->assertStringContainsString('"stage": "config"', $display);
        $this->assertStringContainsString('missing_path', $display);
    }

    #[Test]
    public function test_the_analyze_command_warns_of_a_kernel_with_no_security_configuration(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject(['config/services.yaml' => "parameters: {}\n"]), withSecurityBundle: false);

        $tester = new CommandTester(self::analyzeCommand($kernel));
        $status = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('No security configuration found.', $tester->getDisplay());
    }

    private static function securityCommand(SecurityConfigKernel $kernel): SecurityCommand
    {
        return new SecurityCommand(
            new SecurityConfigExtractor(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
            kernel: $kernel,
        );
    }

    private static function analyzeCommand(SecurityConfigKernel $kernel): AnalyzeCommand
    {
        $analyzer = new SecurityAnalyzer(
            new SecurityConfigExtractor(),
            new SecurityConfigLocator(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );

        return new AnalyzeCommand(new AnalyzerRegistry([$analyzer]), new ConsoleReportFormatter(), new JsonReportFormatter(), $kernel);
    }
}

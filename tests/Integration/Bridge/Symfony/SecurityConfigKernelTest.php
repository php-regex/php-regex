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

use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Command\AnalyzeCommand;
use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Exception\SecurityConfigException;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
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
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
    public function test_an_entry_is_located_in_the_environment_file_that_writes_it(): void
    {
        $project = $this->makeProject([
            'config/packages/security.yaml' => "security:\n    firewalls:\n        main: { pattern: ^/ }\n",
            'config/packages/test/security.yaml' => "security:\n    access_control:\n        - { path: ^/admin, roles: ROLE_ADMIN }\n",
        ]);

        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
        $this->assertNotNull($config);

        $this->assertSame([[$project.'/config/packages/test/security.yaml', 3]], array_map(static fn (array $rule): array => [$rule['file'], $rule['line']], $config['accessControl']));
        $this->assertSame([[$project.'/config/packages/security.yaml', 3]], array_map(static fn (array $firewall): array => [$firewall['file'], $firewall['line']], $config['firewalls']));
    }

    #[Test]
    public function test_an_entry_of_a_php_file_is_located_under_its_key_and_not_in_a_comment(): void
    {
        $project = $this->makeProject([
            'config/packages/security.php' => <<<'PHP'
                <?php

                use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

                return static function (ContainerConfigurator $container): void {
                    $container->extension('security', [
                        'firewalls' => [
                            'admin' => ['pattern' => '^/admin', 'context' => 'domain', 'provider' => 'mainland'],
                            'main' => ['lazy' => true],
                        ],
                        // '^/admin' is the back office
                        'access_control' => [
                            ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                        ],
                    ]);
                };
                PHP,
        ]);

        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
        $this->assertNotNull($config);

        $this->assertSame(13, $config['accessControl'][0]['line']);
        $this->assertSame(['admin' => 8, 'main' => 9], array_column($config['firewalls'], 'line', 'name'));
    }

    /**
     * The processed configuration holds a port as an integer and the
     * attributes as a map, the route beside them.
     */
    #[Test]
    public function test_the_port_the_attributes_and_the_route_are_read_as_the_kernel_processes_them(): void
    {
        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($this->makeProject([
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n"
                ."        - { path: ^/admin, port: 8080, roles: PUBLIC_ACCESS }\n"
                ."        - { path: ^/api, attributes: { _format: json }, roles: PUBLIC_ACCESS }\n"
                ."        - { route: app_login, roles: PUBLIC_ACCESS }\n"
                ."        - { path: ^/, roles: ROLE_USER }\n",
        ])));

        $this->assertNotNull($config);
        $this->assertSame(
            [[8080, [], null, 6], [null, ['_format' => 'json'], null, 7], [null, [], 'app_login', 8], [null, [], null, 9]],
            array_map(static fn (array $rule): array => [$rule['port'] ?? null, $rule['attributes'] ?? [], $rule['route'] ?? null, $rule['line']], $config['accessControl']),
        );
    }

    /**
     * Symfony reads an environment value at run time, in the environment
     * the application runs in: the analysis knows no such value, and never
     * reads the one of the machine it runs on. The kernel builds its
     * container with a path, a host, addresses, roles and a typed firewall
     * option read from the environment, directly or through a parameter;
     * the rule keeps each as written and names it.
     */
    #[Test]
    public function test_an_environment_value_is_unknown_and_never_read_from_the_machine(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_host: '%env(PHPREGEX_TEST_ADMIN_HOST)%'\n",
            'config/packages/security.yaml' => "security:\n    firewalls:\n        main:\n            pattern: ^/\n            stateless: '%env(bool:PHPREGEX_TEST_STATELESS)%'\n"
                ."    access_control:\n        - { path: '%env(PHPREGEX_TEST_ADMIN_PATH)%', host: '%admin_host%', ips: ['%env(PHPREGEX_TEST_ADMIN_IP)%'], roles: '%env(PHPREGEX_TEST_ADMIN_ROLE)%' }\n",
        ]);
        $_ENV['PHPREGEX_TEST_ADMIN_PATH'] = $_SERVER['PHPREGEX_TEST_ADMIN_PATH'] = '^/back-office';

        try {
            $kernel = new SecurityConfigKernel($project);
            $kernel->boot();
            $this->assertTrue($kernel->getContainer()->has('kernel'));
            $kernel->shutdown();

            $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
        } finally {
            unset($_ENV['PHPREGEX_TEST_ADMIN_PATH'], $_SERVER['PHPREGEX_TEST_ADMIN_PATH']);
        }

        $this->assertNotNull($config);
        $rule = $config['accessControl'][0];
        $this->assertSame('%env(PHPREGEX_TEST_ADMIN_PATH)%', $rule['path']);
        $this->assertSame('%env(PHPREGEX_TEST_ADMIN_HOST)%', $rule['host']);
        $this->assertSame(['%env(PHPREGEX_TEST_ADMIN_IP)%'], $rule['ips']);
        $this->assertSame(['%env(PHPREGEX_TEST_ADMIN_ROLE)%'], $rule['roles']);
        $this->assertSame(['path', 'host', 'roles', 'ips'], $rule['fromEnvironment'] ?? []);
    }

    /**
     * The security bundle refuses a port or methods read from the
     * environment: SecurityExtension hands the port to the matcher it builds
     * when the container is compiled, which takes an integer only, and
     * upper-cases the methods there, which loses the environment value. The
     * application does not boot, and the analysis stops as it does on any
     * configuration the bundle refuses, naming the field and the rule.
     *
     * @param \Closure(\Throwable): bool $refusal what the kernel throws when it boots
     */
    #[Test]
    #[DataProvider('provideFieldsTheBundleRefusesFromTheEnvironment')]
    public function test_a_field_the_security_bundle_refuses_from_the_environment_stops_the_reading(string $rule, \Closure $refusal, string $message): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_port: '%env(int:PHPREGEX_TEST_PORT)%'\n",
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n        - { path: ^/, roles: ROLE_USER }\n        - ".$rule."\n",
        ]);
        $_ENV['PHPREGEX_TEST_PORT'] = $_SERVER['PHPREGEX_TEST_PORT'] = '8080';
        $_ENV['PHPREGEX_TEST_METHOD'] = $_SERVER['PHPREGEX_TEST_METHOD'] = 'POST';

        try {
            try {
                (new SecurityConfigKernel($project))->boot();
                $this->fail('The kernel booted.');
            } catch (\Throwable $e) {
                $this->assertTrue($refusal($e), $e::class.': '.$e->getMessage());
            }

            $status = (new CommandTester(self::securityCommand(new SecurityConfigKernel($project))))->execute([]);
            $this->assertSame(Command::INVALID, $status);

            $this->expectException(SecurityConfigException::class);
            $this->expectExceptionMessageIsOrContains(\sprintf($message, $project.'/config/packages/security.yaml:7'));

            (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
        } finally {
            unset($_ENV['PHPREGEX_TEST_PORT'], $_SERVER['PHPREGEX_TEST_PORT'], $_ENV['PHPREGEX_TEST_METHOD'], $_SERVER['PHPREGEX_TEST_METHOD']);
        }
    }

    /**
     * @return iterable<string, array{rule: string, refusal: \Closure(\Throwable): bool, message: string}>
     */
    public static function provideFieldsTheBundleRefusesFromTheEnvironment(): iterable
    {
        $port = 'The security configuration is invalid: the port of access_control rule 2 (%s), "%%env(int:PHPREGEX_TEST_PORT)%%", is read from the environment, which the security bundle refuses: it builds the port matcher when the container is compiled, from an integer only.';
        $portRefused = static fn (\Throwable $e): bool => $e instanceof \TypeError && str_contains($e->getMessage(), '$port');

        yield 'a port' => ['rule' => "{ path: ^/admin, port: '%env(int:PHPREGEX_TEST_PORT)%', roles: ROLE_ADMIN }", 'refusal' => $portRefused, 'message' => $port];
        yield 'a port held by a parameter' => ['rule' => "{ path: ^/admin, port: '%admin_port%', roles: ROLE_ADMIN }", 'refusal' => $portRefused, 'message' => $port];
        yield 'methods' => [
            'rule' => "{ path: ^/admin, methods: ['%env(PHPREGEX_TEST_METHOD)%'], roles: ROLE_ADMIN }",
            'refusal' => static fn (\Throwable $e): bool => str_contains($e->getMessage(), '"PHPREGEX_TEST_METHOD" are never used'),
            'message' => 'The security configuration is invalid: the methods of access_control rule 2 (%s), "%%env(PHPREGEX_TEST_METHOD)%%", are read from the environment, which the security bundle refuses: it upper-cases them when the container is compiled, which loses the environment value.',
        ];
    }

    /**
     * A rule no security file writes is named by its place in
     * access_control alone.
     */
    #[Test]
    public function test_a_port_from_the_environment_no_security_file_writes_is_refused_by_its_place(): void
    {
        $project = $this->makeProject([
            'config/packages/app.php' => <<<'CONFIG'
                <?php

                use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

                return static function (ContainerConfigurator $container): void {
                    $container->extension('security', [
                        'firewalls' => ['main' => ['pattern' => '^/']],
                        'access_control' => [['path' => '^/admin', 'port' => '%env(int:PHPREGEX_TEST_PORT)%', 'roles' => 'ROLE_ADMIN']],
                    ]);
                };
                CONFIG,
        ]);

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('the port of access_control rule 1 (security configuration), "%env(int:PHPREGEX_TEST_PORT)%", is read from the environment');

        (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
    }

    /**
     * A parameter is resolved before the configuration tree reads it, as
     * the kernel resolves it: a port given by a parameter is the integer
     * it holds.
     */
    #[Test]
    public function test_a_port_given_by_a_parameter_is_the_value_it_holds(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_port: 8080\n",
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n        - { path: ^/admin, port: '%admin_port%', roles: ROLE_ADMIN }\n",
        ]);
        $kernel = new SecurityConfigKernel($project);
        $kernel->boot();
        $kernel->shutdown();

        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));

        $this->assertNotNull($config);
        $this->assertSame(8080, $config['accessControl'][0]['port'] ?? null);
        $this->assertSame([], $config['accessControl'][0]['fromEnvironment'] ?? []);
        $this->assertSame(6, $config['accessControl'][0]['line']);
    }

    /**
     * The kernel resolves a parameter before the bundle splits a string of
     * roles or methods: a parameter holding "ROLE_A,ROLE_B" is two roles
     * where it is the whole string, as a file named with --config reads it
     * (SecurityConfigExtractorTest checks that reading against the bundle).
     */
    #[Test]
    public function test_a_list_held_by_a_parameter_is_split_as_the_kernel_splits_it(): void
    {
        $parameters = "parameters:\n    admin_roles: 'ROLE_A,ROLE_B'\n    admin_methods: 'GET, POST'\n";
        $security = "security:\n".self::FIREWALLS."    access_control:\n"
            ."        - { path: ^/a, roles: '%admin_roles%', methods: '%admin_methods%' }\n"
            ."        - { path: ^/b, roles: ['%admin_roles%', ROLE_C] }\n";
        $project = $this->makeProject([
            'config/services.yaml' => $parameters,
            'config/packages/security.yaml' => $security,
            'security.yaml' => $parameters.$security,
        ]);

        $kernel = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
        $file = (new SecurityConfigExtractor())->extract($project.'/security.yaml');

        $this->assertNotNull($kernel);
        $this->assertSame([['ROLE_A', 'ROLE_B'], ['ROLE_A,ROLE_B', 'ROLE_C']], array_column($kernel['accessControl'], 'roles'));
        $this->assertSame(array_column($file['accessControl'], 'roles'), array_column($kernel['accessControl'], 'roles'));
        $this->assertSame(['GET', 'POST'], $kernel['accessControl'][0]['methods']);
    }

    /**
     * A rule is found in the files by its values as written, before the
     * parameters they hold are resolved and split: one written with roles
     * alone is found by them, one with methods alone by them, one with a
     * path by its path. Each is then read with its parameters resolved.
     */
    #[Test]
    public function test_a_rule_written_with_parameters_is_located_at_its_line(): void
    {
        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_roles: 'ROLE_A,ROLE_B'\n    admin_method: post\n",
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n"
                ."        - { roles: '%admin_roles%' }\n"
                ."        - { methods: ['%admin_method%'] }\n"
                ."        - { path: ^/c, roles: '%admin_roles%' }\n",
        ])));

        $this->assertNotNull($config);
        $this->assertSame(
            [[6, ['ROLE_A', 'ROLE_B'], []], [7, [], ['post']], [8, ['ROLE_A', 'ROLE_B'], []]],
            array_map(static fn (array $rule): array => [$rule['line'], $rule['roles'], $rule['methods']], $config['accessControl']),
        );
    }

    /**
     * The kernel resolves a parameter before the configuration tree reads
     * it, which checks what it holds: a port given by a parameter holding
     * the string "8080" is refused, as it is no integer.
     */
    #[Test]
    public function test_a_parameter_is_checked_by_the_configuration_tree_as_what_it_holds(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_port: '8080'\n",
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n        - { path: ^/admin, port: '%admin_port%', roles: ROLE_ADMIN }\n",
        ]);

        try {
            (new SecurityConfigKernel($project))->boot();
            $this->fail('The kernel took a string as a port.');
        } catch (\Exception $e) {
            $refusal = $e->getMessage();
        }

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('The security configuration is invalid: '.$refusal);

        (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
    }

    /**
     * A parameter holding a list gives a list of roles, methods or
     * addresses as the kernel resolves it, each of its items one value; the
     * rule is still found by the parameter it writes.
     */
    #[Test]
    public function test_a_parameter_holding_a_list_gives_its_items(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_roles: [ROLE_A, ROLE_B]\n    admin_methods: [get, post]\n    admin_ips: [10.0.0.1, 10.0.0.2]\n",
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n"
                ."        - { roles: '%admin_roles%' }\n"
                ."        - { path: ^/b, methods: '%admin_methods%', ips: '%admin_ips%', roles: ROLE_C }\n",
        ]);
        $kernel = new SecurityConfigKernel($project);
        $kernel->boot();
        $kernel->shutdown();

        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));

        $this->assertNotNull($config);
        $this->assertSame(
            [[6, ['ROLE_A', 'ROLE_B'], [], []], [7, ['ROLE_C'], ['get', 'post'], ['10.0.0.1', '10.0.0.2']]],
            array_map(static fn (array $rule): array => [$rule['line'], $rule['roles'], $rule['methods'], $rule['ips']], $config['accessControl']),
        );
    }

    /**
     * The security bundle joins a list of firewall patterns into one
     * alternation once their parameters are resolved, as the kernel
     * resolves them; one read from the environment is said to be so.
     */
    #[Test]
    public function test_a_list_of_firewall_patterns_is_one_alternation_with_its_values_resolved(): void
    {
        $config = (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($this->makeProject([
            'config/services.yaml' => "parameters:\n    api_pattern: ^/api\n",
            'config/packages/security.yaml' => "security:\n    firewalls:\n        api: { pattern: ['%api_pattern%', ^/app] }\n        main: { pattern: [^/shop, '%env(PHPREGEX_TEST_PATTERN)%'] }\n",
        ])));

        $this->assertNotNull($config);
        $this->assertSame(['api' => '(?:^/api|^/app)', 'main' => '(?:^/shop|%env(PHPREGEX_TEST_PATTERN)%)'], array_column($config['firewalls'], 'pattern', 'name'));
        $this->assertSame(['api' => false, 'main' => true], array_column($config['firewalls'], 'fromEnvironment', 'name'));
    }

    #[Test]
    public function test_a_parameter_that_is_no_string_is_an_error(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_paths: [^/a, ^/b]\n",
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS."    access_control:\n        - { path: '%admin_paths%' }\n",
        ]);

        // The kernel resolves the parameter first, and the configuration
        // tree refuses the list it holds.
        try {
            (new SecurityConfigKernel($project))->boot();
            $this->fail('The kernel took a list as a path.');
        } catch (\Exception $e) {
            $refusal = $e->getMessage();
        }

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('The security configuration is invalid: '.$refusal);

        (new SecurityConfigExtractor())->extractFromKernel(new SecurityConfigKernel($project));
    }

    /**
     * The configuration is read from a copy of the kernel: the kernel the
     * command runs in keeps its bundles and its container as they were.
     */
    #[Test]
    public function test_the_kernel_read_is_left_untouched(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/security.yaml' => "security:\n".self::FIREWALLS,
        ]));

        (new SecurityConfigExtractor())->extractFromKernel($kernel);

        $this->assertSame([], $kernel->getBundles());
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

    /**
     * The container a named file takes its parameters from is built when the
     * file asks for one: a file that asks for none is read even where the
     * kernel cannot build its container, its cache directory left read only
     * as in production; one that asks for one stops with the kernel's
     * reason.
     */
    #[Test]
    public function test_a_named_file_builds_the_container_of_the_application_only_for_a_parameter_it_asks_for(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_path: ^/admin\n",
            'plain.yaml' => "security:\n    access_control: [{ path: ^/plain }]\n",
            'parameter.yaml' => "security:\n    access_control: [{ path: '%admin_path%' }]\n",
        ]);
        $kernel = new SecurityConfigKernel($project, withSecurityBundle: false);
        $kernel->boot();
        $cache = $kernel->getCacheDir();
        chmod($cache, 0o555);

        try {
            try {
                (\Closure::bind(fn (): mixed => $this->buildContainer(), clone $kernel, SecurityConfigKernel::class))();
                $this->fail('The kernel built its container in a read-only cache directory.');
            } catch (\RuntimeException $e) {
                $reason = $e->getMessage();
            }

            $config = (new SecurityConfigExtractor())->read([$project.'/plain.yaml'], $kernel, new SecurityConfigLocator(), $project, 'test');
            $this->assertNotNull($config);
            $this->assertSame(['^/plain'], array_column($config['accessControl'], 'path'));

            try {
                (new SecurityConfigExtractor())->read([$project.'/parameter.yaml'], $kernel, new SecurityConfigLocator(), $project, 'test');
                $this->fail('A parameter was read from a container the kernel cannot build.');
            } catch (SecurityConfigException $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        } finally {
            chmod($cache, 0o755);
        }
    }

    /**
     * A parameter a compiler pass of the application sets is taken from the
     * container the kernel compiles, as the application holds it: one the
     * pass gives an environment value stays unknown.
     */
    #[Test]
    public function test_a_named_file_takes_a_parameter_a_compiler_pass_sets(): void
    {
        $pass = new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setParameter('admin_path', '^/admin');
                $container->setParameter('admin_host', '%env(PHPREGEX_TEST_ADMIN_HOST)%');
            }
        };
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    unused: ~\n",
            'security.yaml' => "security:\n    access_control: [{ path: '%admin_path%', host: '%admin_host%' }]\n",
        ]);
        $_ENV['PHPREGEX_TEST_ADMIN_HOST'] = $_SERVER['PHPREGEX_TEST_ADMIN_HOST'] = 'admin.example.com';

        try {
            $kernel = new SecurityConfigKernel($project, withSecurityBundle: false, compilerPasses: [$pass]);
            $kernel->boot();
            $this->assertSame('^/admin', $kernel->getContainer()->getParameter('admin_path'));

            $config = (new SecurityConfigExtractor())->read([$project.'/security.yaml'], $kernel, new SecurityConfigLocator(), $project, 'test');
        } finally {
            unset($_ENV['PHPREGEX_TEST_ADMIN_HOST'], $_SERVER['PHPREGEX_TEST_ADMIN_HOST']);
        }

        $this->assertNotNull($config);
        $this->assertSame([['^/admin', '%env(PHPREGEX_TEST_ADMIN_HOST)%', ['host']]], array_map(static fn (array $rule): array => [$rule['path'], $rule['host'], $rule['fromEnvironment'] ?? []], $config['accessControl']));
    }

    /**
     * A parameter the named file lacks is taken from the application as it
     * writes it: one the application reads from the environment stays
     * unknown there too, where the compiled container resolves it from the
     * environment of the machine the command runs on.
     */
    #[Test]
    public function test_a_parameter_the_application_reads_from_the_environment_stays_unknown_in_a_named_file(): void
    {
        $project = $this->makeProject([
            'config/services.yaml' => "parameters:\n    admin_path: '%env(PHPREGEX_TEST_ADMIN_PATH)%'\n    public_path: '%admin_path%/public'\n",
            'security.yaml' => "security:\n    access_control: [{ path: '%admin_path%' }, { path: '%public_path%' }]\n",
        ]);
        $_ENV['PHPREGEX_TEST_ADMIN_PATH'] = $_SERVER['PHPREGEX_TEST_ADMIN_PATH'] = '^/back-office';

        try {
            $kernel = new SecurityConfigKernel($project, withSecurityBundle: false);
            $kernel->boot();
            $this->assertSame('^/back-office', $kernel->getContainer()->getParameter('admin_path'));

            $config = (new SecurityConfigExtractor())->read([$project.'/security.yaml'], $kernel, new SecurityConfigLocator(), $project, 'test');
        } finally {
            unset($_ENV['PHPREGEX_TEST_ADMIN_PATH'], $_SERVER['PHPREGEX_TEST_ADMIN_PATH']);
        }

        $this->assertNotNull($config);
        $this->assertSame(['%env(PHPREGEX_TEST_ADMIN_PATH)%', '%env(PHPREGEX_TEST_ADMIN_PATH)%/public'], array_column($config['accessControl'], 'path'));
        $this->assertSame([['path'], ['path']], array_column($config['accessControl'], 'fromEnvironment'));
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

        try {
            (new SecurityConfigExtractor())->extractFromKernel($kernel);
            $this->fail('A parameter the kernel does not define was read.');
        } catch (SecurityConfigException $e) {
            $this->assertStringStartsWith('The security configuration value "%missing_path%" cannot be resolved: ', $e->getMessage());
            $this->assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
    }

    #[Test]
    public function test_a_configuration_the_security_bundle_refuses_is_an_error(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/security.yaml' => "security:\n    access_control:\n        - { path: ^/admin, unknown_key: true }\n",
        ]));

        try {
            (new SecurityConfigExtractor())->extractFromKernel($kernel);
            $this->fail('A configuration the security bundle refuses was read.');
        } catch (SecurityConfigException $e) {
            $this->assertStringStartsWith('The security configuration is invalid: ', $e->getMessage());
            $this->assertStringContainsString('unknown_key', $e->getMessage());
            $this->assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
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

    /**
     * A rule with no path applies to every path: it shadows the rule after
     * it, and the command names the line of each, the PHP file's too.
     */
    #[Test]
    public function test_the_security_command_names_the_line_of_a_rule_without_a_path(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject([
            'config/packages/security.php' => <<<'PHP'
                <?php

                use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

                return static function (ContainerConfigurator $container): void {
                    $container->extension('security', [
                        'firewalls' => ['main' => ['pattern' => '^/']],
                        'access_control' => [
                            ['roles' => 'ROLE_ADMIN'],
                            ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                        ],
                    ]);
                };
                PHP,
        ]));

        $tester = new CommandTester(self::securityCommand($kernel));
        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertStringContainsString('1 shadowed rules detected.', $display);
        $this->assertStringContainsString('#1 config/packages/security.php:9', $display);
        $this->assertStringContainsString('#2 config/packages/security.php:10', $display);
        $this->assertStringNotContainsString('security configuration  ', $display);
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

    #[Test]
    public function test_the_analyze_command_reports_a_missing_security_configuration_in_the_access_section(): void
    {
        $kernel = new SecurityConfigKernel($this->makeProject(['config/services.yaml' => "parameters: {}\n"]), withSecurityBundle: false);

        $tester = new CommandTester(self::analyzeCommand($kernel));
        $tester->execute(['--format' => 'json', '--only' => ['SECURITY']]);

        $this->assertStringContainsString('"id": "security_access"', $tester->getDisplay());
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

        return new AnalyzeCommand([$analyzer], new ConsoleReportFormatter(), new JsonReportFormatter(), $kernel);
    }
}

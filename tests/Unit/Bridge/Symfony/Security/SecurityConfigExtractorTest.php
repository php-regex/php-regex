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

use PHPRegex\Symfony\Exception\SecurityConfigException;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Tests\Support\SecurityConfigFunctionOverrides;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ExceptionInterface as DependencyInjectionException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestMatcher\IpsRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

final class SecurityConfigExtractorTest extends TestCase
{
    use PhpUnitForwardCompat;
    use TemporaryProject;

    #[Test]
    public function test_extracts_access_control_and_firewalls(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_access_control.yaml';

        $extractor = new SecurityConfigExtractor();
        $result = $extractor->extract($path);

        $this->assertCount(2, $result['accessControl']);
        $this->assertSame($path, $result['accessControl'][0]['file']);
        $this->assertSame(3, $result['accessControl'][0]['line']);
        $this->assertSame('^/api', $result['accessControl'][0]['path']);
        $this->assertSame(['PUBLIC_ACCESS'], $result['accessControl'][0]['roles']);

        $this->assertSame($path, $result['accessControl'][1]['file']);
        $this->assertSame(4, $result['accessControl'][1]['line']);
        $this->assertSame('^/api/admin', $result['accessControl'][1]['path']);
        $this->assertSame(['ROLE_ADMIN'], $result['accessControl'][1]['roles']);
        $this->assertSame(['GET', 'POST'], $result['accessControl'][1]['methods']);

        $this->assertCount(2, $result['firewalls']);
        $this->assertSame('main', $result['firewalls'][0]['name']);
        $this->assertSame($path, $result['firewalls'][0]['file']);
        $this->assertSame(9, $result['firewalls'][0]['line']);
        $this->assertSame('^/api/(a+)+$', $result['firewalls'][0]['pattern']);

        $this->assertSame('dev', $result['firewalls'][1]['name']);
        $this->assertSame($path, $result['firewalls'][1]['file']);
        $this->assertSame(10, $result['firewalls'][1]['line']);
        $this->assertSame('dev.matcher', $result['firewalls'][1]['requestMatcher']);
    }

    #[Test]
    public function test_firewall_patterns_lose_their_yaml_quotes(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_quoted_patterns.yaml';

        $result = (new SecurityConfigExtractor())->extract($path);

        $this->assertSame('^/api/(a+)+$', $result['firewalls'][0]['pattern'], 'single quotes');
        $this->assertSame('^/(dev|config)/', $result['firewalls'][1]['pattern'], 'double quotes');
        $this->assertSame('api.request.matcher', $result['firewalls'][2]['requestMatcher'], 'request_matcher');
    }

    /**
     * The recipe's dev firewall carries "security: false": that key is a
     * firewall option, not the root of the config, and must not hide the
     * firewalls and rules that follow it.
     */
    #[Test]
    public function test_recipe_layout_reads_past_a_firewall_security_key(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_recipe_layout.yaml';

        $result = (new SecurityConfigExtractor())->extract($path);

        $this->assertSame(['dev', 'api', 'main'], array_column($result['firewalls'], 'name'));
        $this->assertSame('^/api/(\w+/?)+$', $result['firewalls'][1]['pattern']);
        $this->assertSame(14, $result['firewalls'][1]['line']);
        $this->assertSame(['^/admin', '^/admin/users'], array_column($result['accessControl'], 'path'));
        $this->assertSame(22, $result['accessControl'][1]['line']);
    }

    #[Test]
    public function test_recipe_layout_nested_under_an_environment_block(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_recipe_when_prod.yaml';

        $extractor = new SecurityConfigExtractor();
        $result = $extractor->extract($path);

        $this->assertSame(['dev', 'api', 'main'], array_column($result['firewalls'], 'name'));
        $this->assertSame(['^/admin', '^/admin/users'], array_column($result['accessControl'], 'path'));

        $prod = $extractor->extract($path, 'prod');
        $this->assertCount(3, $prod['firewalls']);
        $this->assertCount(2, $prod['accessControl']);

        $dev = $extractor->extract($path, 'dev');
        $this->assertSame([], $dev['firewalls']);
        $this->assertSame([], $dev['accessControl']);
    }

    /**
     * Only a top-level "security:" (or one directly under "when@<env>:")
     * is the security config; the same key deeper belongs to something else.
     */
    #[Test]
    public function test_a_nested_security_key_is_not_the_security_config(): void
    {
        $yaml = "framework:\n    security:\n        firewalls:\n            main:\n                pattern: ^/main\n        access_control:\n            - { path: ^/nested }\n"
            ."when@prod:\n    other:\n        security:\n            access_control:\n                - { path: ^/deeper }\n";
        $path = tempnam(sys_get_temp_dir(), 'regex-security-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, $yaml);
            $result = (new SecurityConfigExtractor())->extract($path);
        } finally {
            unlink($path);
        }

        $this->assertSame([], $result['firewalls']);
        $this->assertSame([], $result['accessControl']);
    }

    /**
     * An environment block ends at the next top-level key: a root
     * "security:" after it is read whatever the environment asked.
     */
    #[Test]
    public function test_a_root_security_after_an_environment_block_is_read(): void
    {
        $yaml = "when@dev:\n    framework:\n        security: false\nsecurity:\n    firewalls:\n        main:\n            pattern: ^/main\n    access_control:\n        - { path: ^/admin }\n";
        $path = tempnam(sys_get_temp_dir(), 'regex-security-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, $yaml);
            $result = (new SecurityConfigExtractor())->extract($path, 'prod');
        } finally {
            unlink($path);
        }

        $this->assertSame(['main'], array_column($result['firewalls'], 'name'));
        $this->assertSame(['^/admin'], array_column($result['accessControl'], 'path'));
    }

    /**
     * A list key left empty ("roles:", read by YAML as null) does not take
     * the lines after it: a sibling key at its indent, or an empty flow
     * list on the next line, deeper. Both readings agree with symfony/yaml.
     *
     * @param array{roles: list<string>, methods: list<string>, ips: list<string>} $expected
     */
    #[Test]
    #[DataProvider('provideEmptyListKeys')]
    public function test_access_control_reads_a_list_key_left_empty(string $yaml, array $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'regex-security-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, $yaml);
            $rules = (new SecurityConfigExtractor())->extract($path)['accessControl'];
        } finally {
            unlink($path);
        }

        $this->assertCount(1, $rules);
        $this->assertSame('^/admin', $rules[0]['path']);
        $this->assertSame($expected, ['roles' => $rules[0]['roles'], 'methods' => $rules[0]['methods'], 'ips' => $rules[0]['ips']]);
    }

    /**
     * @return iterable<string, array{yaml: string, expected: array{roles: list<string>, methods: list<string>, ips: list<string>}}>
     */
    public static function provideEmptyListKeys(): iterable
    {
        yield 'sibling key after an empty list key' => [
            'yaml' => "security:\n    access_control:\n        - path: ^/admin\n          roles:\n          methods: [GET, POST]\n",
            'expected' => ['roles' => [], 'methods' => ['GET', 'POST'], 'ips' => []],
        ];
        // A block list: the dash lines under the key are its items, not new
        // rules.
        yield 'block list of roles' => [
            'yaml' => "security:\n    access_control:\n        - path: ^/admin\n          roles:\n              - ROLE_ADMIN\n              - ROLE_EDITOR\n",
            'expected' => ['roles' => ['ROLE_ADMIN', 'ROLE_EDITOR'], 'methods' => [], 'ips' => []],
        ];
        yield 'block lists of methods and addresses' => [
            'yaml' => "security:\n    access_control:\n        - path: ^/admin\n          methods:\n              - GET\n          ips:\n              - 127.0.0.1\n              - '::1'\n",
            'expected' => ['roles' => [], 'methods' => ['GET'], 'ips' => ['127.0.0.1', '::1']],
        ];
        yield 'empty flow list on the line after the key' => [
            'yaml' => "security:\n    access_control:\n        - path: ^/admin\n          ips:\n              []\n          methods: [GET]\n",
            'expected' => ['roles' => [], 'methods' => ['GET'], 'ips' => []],
        ];
    }

    /**
     * The security bundle's MainConfiguration gives a rule a port, request
     * attributes and a route: each restricts the requests the rule takes,
     * so each is read, its parameters resolved.
     *
     * @param array{port: ?int, attributes: array<string, string>, route: ?string, line: int} $expected
     */
    #[Test]
    #[DataProvider('provideRequestRestrictions')]
    public function test_access_control_reads_the_port_the_attributes_and_the_route(string $yaml, array $expected): void
    {
        $path = $this->makeProject(['security.yaml' => $yaml]).'/security.yaml';

        $rules = (new SecurityConfigExtractor())->extract($path)['accessControl'];

        $this->assertCount(2, $rules);
        $rule = $rules[1];
        $this->assertSame($expected, ['port' => $rule['port'] ?? null, 'attributes' => $rule['attributes'] ?? [], 'route' => $rule['route'] ?? null, 'line' => $rule['line']]);
        $this->assertSame(['port' => null, 'attributes' => [], 'route' => null], ['port' => $rules[0]['port'] ?? null, 'attributes' => $rules[0]['attributes'] ?? [], 'route' => $rules[0]['route'] ?? null]);
    }

    /**
     * @return iterable<string, array{yaml: string, expected: array{port: ?int, attributes: array<string, string>, route: ?string, line: int}}>
     */
    public static function provideRequestRestrictions(): iterable
    {
        $first = "security:\n    access_control:\n        - { path: ^/admin, roles: ROLE_ADMIN }\n";

        yield 'a route' => [
            'yaml' => $first."        - { route: app_login, roles: PUBLIC_ACCESS }\n",
            'expected' => ['port' => null, 'attributes' => [], 'route' => 'app_login', 'line' => 4],
        ];
        yield 'a port' => [
            'yaml' => $first."        - { path: ^/admin, port: 8080, roles: PUBLIC_ACCESS }\n",
            'expected' => ['port' => 8080, 'attributes' => [], 'route' => null, 'line' => 4],
        ];
        yield 'a port written as a string' => [
            'yaml' => $first."        - { path: ^/admin, port: '8080', roles: PUBLIC_ACCESS }\n",
            'expected' => ['port' => 8080, 'attributes' => [], 'route' => null, 'line' => 4],
        ];
        yield 'attributes' => [
            'yaml' => $first."        - { path: ^/api, attributes: { _format: json, _locale: fr }, roles: PUBLIC_ACCESS }\n",
            'expected' => ['port' => null, 'attributes' => ['_format' => 'json', '_locale' => 'fr'], 'route' => null, 'line' => 4],
        ];
        yield 'parameters' => [
            'yaml' => "parameters:\n    login_route: app_login\n    admin_port: 8443\n    api_format: json\n"
                .$first."        - { route: '%login_route%', port: '%admin_port%', attributes: { _format: '%api_format%' }, roles: PUBLIC_ACCESS }\n",
            'expected' => ['port' => 8443, 'attributes' => ['_format' => 'json'], 'route' => 'app_login', 'line' => 8],
        ];
    }

    #[Test]
    public function test_a_port_that_is_no_number_is_an_error(): void
    {
        $path = $this->makeProject(['security.yaml' => "security:\n    access_control:\n        - { path: ^/admin, port: http, roles: PUBLIC_ACCESS }\n"]).'/security.yaml';

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('The access_control port "http" is not a port number.');

        (new SecurityConfigExtractor())->extract($path);
    }

    /**
     * A file named with --config is read by symfony/yaml: every shape YAML
     * allows, where the line scanner read the block style only.
     *
     * @param list<array{0: ?string, 1: list<string>, 2: int}> $rules
     * @param array<string, ?string>                           $firewalls
     */
    #[Test]
    #[DataProvider('provideYamlShapes')]
    public function test_a_yaml_file_is_read_whatever_its_shape(string $yaml, array $rules, array $firewalls): void
    {
        $path = $this->makeProject(['security.yaml' => $yaml]).'/security.yaml';

        $result = (new SecurityConfigExtractor())->extract($path, 'prod');

        $this->assertSame($rules, array_map(
            static fn (array $rule): array => [$rule['path'], $rule['roles'], $rule['line']],
            $result['accessControl'],
        ));
        $this->assertSame($firewalls, array_column($result['firewalls'], 'pattern', 'name'));
    }

    /**
     * @return iterable<string, array{yaml: string, rules: list<array{0: ?string, 1: list<string>, 2: int}>, firewalls: array<string, ?string>}>
     */
    public static function provideYamlShapes(): iterable
    {
        yield 'flow sequence on one line' => [
            'yaml' => "security:\n    access_control: [{ path: ^/admin, roles: ROLE_ADMIN }, { path: ^/admin/public, roles: PUBLIC_ACCESS }]\n",
            'rules' => [['^/admin', ['ROLE_ADMIN'], 2], ['^/admin/public', ['PUBLIC_ACCESS'], 2]],
            'firewalls' => [],
        ];

        yield 'flow sequence over several lines' => [
            'yaml' => "security:\n    access_control: [\n        { path: ^/admin, roles: 'ROLE_ADMIN, ROLE_EDITOR' },\n        { path: ^/admin/public, roles: [PUBLIC_ACCESS], methods: 'get, post' },\n    ]\n",
            'rules' => [['^/admin', ['ROLE_ADMIN', 'ROLE_EDITOR'], 3], ['^/admin/public', ['PUBLIC_ACCESS'], 4]],
            'firewalls' => [],
        ];

        yield 'anchors and aliases' => [
            'yaml' => "security:\n    firewalls:\n        main: &main { pattern: ^/ }\n        copy: *main\n    access_control:\n        - &admin { path: ^/admin, roles: ROLE_ADMIN }\n        - *admin\n",
            'rules' => [['^/admin', ['ROLE_ADMIN'], 6], ['^/admin', ['ROLE_ADMIN'], 6]],
            'firewalls' => ['main' => '^/', 'copy' => '^/'],
        ];

        yield 'the file parameters' => [
            'yaml' => "parameters:\n    admin_path: ^/admin\n    public_path: '%admin_path%/public'\nsecurity:\n    firewalls:\n        main: { pattern: ['^/app', '^/api'] }\n    access_control:\n        - { path: '%admin_path%', roles: ROLE_ADMIN }\n        - { path: '%public_path%' }\n",
            'rules' => [['^/admin', ['ROLE_ADMIN'], 8], ['^/admin/public', [], 9]],
            'firewalls' => ['main' => '(?:^/app|^/api)'],
        ];

        yield 'entries that are no rule' => [
            'yaml' => "security:\n    access_control: [~, { path: ^/admin }, ROLE_ADMIN]\n    firewalls: { main: ~ }\n",
            'rules' => [['^/admin', [], 2]],
            'firewalls' => ['main' => null],
        ];

        yield 'the environment block after the root' => [
            'yaml' => "security:\n    firewalls:\n        main: { pattern: ^/ }\nwhen@prod:\n    security:\n        firewalls:\n            main: { pattern: ^/prod }\n        access_control:\n            - { path: ^/prod }\nwhen@dev:\n    security:\n        access_control:\n            - { path: ^/dev }\n",
            'rules' => [['^/prod', [], 9]],
            'firewalls' => ['main' => '^/prod'],
        ];
    }

    /**
     * A rule with no path, host, request matcher or allow_if is located at
     * the line of its entry, read from the roles, addresses, methods or
     * channel it writes, never at a line an earlier rule writes.
     *
     * @param list<int> $lines
     */
    #[Test]
    #[DataProvider('provideRulesWithoutAPath')]
    public function test_a_rule_without_a_path_is_located_at_its_line(string $yaml, array $lines): void
    {
        $path = $this->makeProject(['security.yaml' => $yaml]).'/security.yaml';

        $result = (new SecurityConfigExtractor())->extract($path, 'prod');

        $this->assertSame($lines, array_column($result['accessControl'], 'line'));
        $this->assertSame([$path], array_values(array_unique(array_column($result['accessControl'], 'file'))));
    }

    /**
     * @return iterable<string, array{yaml: string, lines: list<int>}>
     */
    public static function provideRulesWithoutAPath(): iterable
    {
        yield 'a flow entry with roles only' => [
            'yaml' => "security:\n    access_control:\n        - { path: ^/admin, roles: ROLE_ADMIN }\n        - { roles: ROLE_USER }\n",
            'lines' => [3, 4],
        ];
        yield 'a block entry after a block entry writing the same role' => [
            'yaml' => "security:\n    access_control:\n        - path: ^/admin\n          roles: ROLE_ADMIN\n        - roles: ROLE_ADMIN\n          methods: [POST]\n",
            'lines' => [3, 5],
        ];
        yield 'a block entry opening on its methods' => [
            'yaml' => "security:\n    access_control:\n        - path: ^/admin\n          roles: ROLE_ADMIN\n        - methods: [POST]\n          roles: ROLE_ADMIN\n",
            'lines' => [3, 5],
        ];
        yield 'an entry with addresses and a channel' => [
            'yaml' => "security:\n    access_control:\n        - { path: ^/admin, roles: PUBLIC_ACCESS }\n        - { ips: [127.0.0.1, ::1], roles: PUBLIC_ACCESS }\n        - { requires_channel: https }\n",
            'lines' => [3, 4, 5],
        ];
        yield 'a flow sequence over several lines' => [
            'yaml' => "security:\n    access_control: [\n        { path: ^/admin, roles: ROLE_ADMIN },\n        { roles: ROLE_ADMIN },\n    ]\n",
            'lines' => [3, 4],
        ];
        yield 'a flow sequence on one line' => [
            'yaml' => "security:\n    access_control: [{ path: ^/admin, roles: ROLE_ADMIN }, { roles: ROLE_USER }]\n",
            'lines' => [2, 2],
        ];
    }

    /**
     * The security bundle splits a string of roles or methods on its
     * commas, wraps a string of addresses whole, and trims nothing; it
     * gives a rule its allow_if when that value is truthy ("0" is not),
     * and its request_matcher and requires_channel as written. Each rule
     * is read as SecurityExtension::load() hands it to the AccessMap; an
     * allow_if it takes needs symfony/expression-language to load, which
     * the suite does not install.
     */
    #[Test]
    #[DataProvider('provideListsAndFlags')]
    public function test_a_rule_is_read_as_the_security_bundle_loads_it(string $rule): void
    {
        $yaml = "security:\n    access_control:\n        - ".$rule."\n";
        $loaded = self::loadedRule($yaml);

        $read = (new SecurityConfigExtractor())->extract($this->makeProject(['security.yaml' => $yaml]).'/security.yaml')['accessControl'][0];

        $this->assertSame($loaded['roles'], $read['roles']);
        $this->assertSame($loaded['methods'], array_map(strtoupper(...), $read['methods']));
        $this->assertSame($loaded['ips'], $read['ips']);
        $this->assertSame($loaded['allowIf'], null !== $read['allowIf']);
        $this->assertSame($loaded['requestMatcher'], $read['requestMatcher']);
        $this->assertSame($loaded['requiresChannel'], $read['requiresChannel']);
    }

    /**
     * The bundle splits a string of roles or methods once
     * MergeExtensionConfigurationPass has resolved its parameters: a
     * parameter holding "ROLE_A,ROLE_B" is two roles where it is the whole
     * string, and one where it is an item of a list. A string of addresses
     * stays one item, which IpsRequestMatcher splits.
     */
    #[Test]
    #[DataProvider('provideListsHeldByParameters')]
    public function test_a_list_held_by_a_parameter_is_split_as_the_security_bundle_splits_it(string $rule): void
    {
        $yaml = "parameters:\n    admin_roles: 'ROLE_A,ROLE_B'\n    admin_methods: 'GET, POST'\n    admin_ips: '10.0.0.1, 10.0.0.2'\nsecurity:\n    access_control:\n        - ".$rule."\n";
        $loaded = self::loadedRule($yaml);

        $read = (new SecurityConfigExtractor())->extract($this->makeProject(['security.yaml' => $yaml]).'/security.yaml')['accessControl'][0];

        $this->assertSame($loaded['roles'], $read['roles']);
        $this->assertSame($loaded['methods'], array_map(strtoupper(...), $read['methods']));
        $this->assertSame($loaded['ips'], $read['ips']);
    }

    /**
     * The bundle tests an allow_if once MergeExtensionConfigurationPass has
     * resolved its parameters: one holding "0" gives the rule no
     * expression.
     */
    #[Test]
    public function test_an_allow_if_held_by_a_parameter_is_tested_once_resolved(): void
    {
        $yaml = "parameters:\n    access_expr: '0'\nsecurity:\n    access_control:\n        - { path: ^/a, allow_if: '%access_expr%', roles: PUBLIC_ACCESS }\n";
        $this->assertFalse(self::loadedRule($yaml)['allowIf']);

        $read = (new SecurityConfigExtractor())->extract($this->makeProject(['security.yaml' => $yaml]).'/security.yaml')['accessControl'][0];

        $this->assertNull($read['allowIf']);
    }

    /**
     * @return iterable<string, array{rule: string}>
     */
    public static function provideListsHeldByParameters(): iterable
    {
        yield 'roles held by a parameter, the whole string' => ['rule' => "{ path: ^/a, roles: '%admin_roles%' }"];
        yield 'roles held by a parameter beside another role' => ['rule' => "{ path: ^/a, roles: '%admin_roles%, ROLE_C' }"];
        yield 'roles held by a parameter, an item of a list' => ['rule' => "{ path: ^/a, roles: ['%admin_roles%', ROLE_C] }"];
        yield 'methods held by a parameter, the whole string' => ['rule' => "{ path: ^/a, methods: '%admin_methods%', roles: ROLE_A }"];
        yield 'addresses held by a parameter, the whole string' => ['rule' => "{ path: ^/a, ips: '%admin_ips%', roles: ROLE_A }"];
    }

    /**
     * @return iterable<string, array{rule: string}>
     */
    public static function provideListsAndFlags(): iterable
    {
        yield 'roles written as a string, spaces around a comma' => ['rule' => "{ path: ^/a, roles: ' ROLE_A , ROLE_B' }"];
        yield 'roles written as a list, a comma in an item' => ['rule' => "{ path: ^/a, roles: [' ROLE_A', 'ROLE_B,ROLE_C'] }"];
        yield 'roles ending on a comma' => ['rule' => "{ path: ^/a, roles: 'ROLE_A,' }"];
        yield 'methods written as a string' => ['rule' => "{ path: ^/a, methods: 'get , POST', roles: ROLE_A }"];
        yield 'methods written as a list, a comma in an item' => ['rule' => "{ path: ^/a, methods: ['GET,POST', ' put'], roles: ROLE_A }"];
        yield 'addresses written as a string' => ['rule' => "{ path: ^/a, ips: '10.0.0.1, 10.0.0.2', roles: ROLE_A }"];
        yield 'addresses written as a list' => ['rule' => "{ path: ^/a, ips: ['10.0.0.1, 10.0.0.2', '::1'], roles: ROLE_A }"];
        yield 'an allow_if of "0"' => ['rule' => "{ path: ^/a, allow_if: '0', roles: ROLE_A }"];
        yield 'a requires_channel of a space' => ['rule' => "{ path: ^/a, requires_channel: ' ', roles: ROLE_A }"];
        yield 'a request_matcher of a space' => ['rule' => "{ request_matcher: ' ', roles: ROLE_A }"];
    }

    #[Test]
    public function test_a_file_parameter_may_come_from_the_kernel(): void
    {
        $path = $this->makeProject(['security.yaml' => "security:\n    access_control:\n        - { path: '%admin_path%' }\n"]).'/security.yaml';
        $container = new Container(new ParameterBag(['admin_path' => '^/admin']));

        $result = (new SecurityConfigExtractor())->extract($path, null, $container);

        $this->assertSame('^/admin', $result['accessControl'][0]['path']);
        $this->assertSame(3, $result['accessControl'][0]['line']);
    }

    #[Test]
    public function test_a_parameter_nothing_defines_is_an_error(): void
    {
        $path = $this->makeProject(['security.yaml' => "security:\n    access_control:\n        - { path: '%admin_path%' }\n"]).'/security.yaml';

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('admin_path');

        (new SecurityConfigExtractor())->extract($path, null, new Container());
    }

    /**
     * An environment value is known at run time only, in the environment
     * the application runs in: a file gives no value of it, not even its
     * "env()" default, which Symfony takes only when the environment has
     * none. The rule keeps the value as written, and names the field that
     * holds it, whether the file writes it or a parameter holds it.
     *
     * @param array<string, string>|list<string>|string|null $value
     */
    #[Test]
    #[DataProvider('provideFieldsHoldingAnEnvironmentValue')]
    public function test_an_environment_value_is_unknown_whatever_field_holds_it(string $rule, string $field, string $key, array|string|null $value): void
    {
        $path = $this->makeProject(['security.yaml' => "parameters:\n    env(ADMIN_PATH): ^/default\n    admin_host: '%env(ADMIN_HOST)%'\nsecurity:\n    access_control:\n        - ".$rule."\n"]).'/security.yaml';

        $read = (new SecurityConfigExtractor())->extract($path, null, new Container())['accessControl'][0];

        $this->assertSame([$field], $read['fromEnvironment'] ?? []);
        $this->assertSame($value, $read[$key] ?? null);
    }

    /**
     * @return iterable<string, array{rule: string, field: string, key: string, value: array<string, string>|list<string>|string|null}>
     */
    public static function provideFieldsHoldingAnEnvironmentValue(): iterable
    {
        yield 'path, its default left aside' => ['rule' => "{ path: '%env(ADMIN_PATH)%' }", 'field' => 'path', 'key' => 'path', 'value' => '%env(ADMIN_PATH)%'];
        yield 'host' => ['rule' => "{ path: ^/admin, host: '%env(ADMIN_HOST)%' }", 'field' => 'host', 'key' => 'host', 'value' => '%env(ADMIN_HOST)%'];
        yield 'host held by a parameter' => ['rule' => "{ path: ^/admin, host: '%admin_host%' }", 'field' => 'host', 'key' => 'host', 'value' => '%env(ADMIN_HOST)%'];
        yield 'route' => ['rule' => "{ route: '%env(LOGIN_ROUTE)%' }", 'field' => 'route', 'key' => 'route', 'value' => '%env(LOGIN_ROUTE)%'];
        yield 'attribute' => ['rule' => "{ path: ^/api, attributes: { _format: '%env(API_FORMAT)%' } }", 'field' => 'attributes', 'key' => 'attributes', 'value' => ['_format' => '%env(API_FORMAT)%']];
        yield 'roles' => ['rule' => "{ path: ^/admin, roles: '%env(ADMIN_ROLE)%' }", 'field' => 'roles', 'key' => 'roles', 'value' => ['%env(ADMIN_ROLE)%']];
        yield 'addresses' => ['rule' => "{ path: ^/admin, ips: ['%env(ADMIN_IP)%'] }", 'field' => 'ips', 'key' => 'ips', 'value' => ['%env(ADMIN_IP)%']];
    }

    /**
     * The security bundle refuses a port or methods read from the
     * environment, from whatever file (SecurityConfigKernelTest boots an
     * application on it): the reading stops, naming the field and the rule.
     */
    #[Test]
    #[DataProvider('provideFieldsTheBundleRefusesFromTheEnvironment')]
    public function test_a_field_the_security_bundle_refuses_from_the_environment_stops_the_reading(string $rule, string $message): void
    {
        $path = $this->makeProject(['security.yaml' => "parameters:\n    admin_port: '%env(int:ADMIN_PORT)%'\nsecurity:\n    access_control:\n        - ".$rule."\n"]).'/security.yaml';

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf($message, $path.':5'));

        (new SecurityConfigExtractor())->extract($path, null, new Container());
    }

    /**
     * @return iterable<string, array{rule: string, message: string}>
     */
    public static function provideFieldsTheBundleRefusesFromTheEnvironment(): iterable
    {
        $port = 'The security configuration is invalid: the port of access_control rule 1 (%s), "%%env(int:ADMIN_PORT)%%", is read from the environment, which the security bundle refuses: it builds the port matcher when the container is compiled, from an integer only.';
        $methods = 'The security configuration is invalid: the methods of access_control rule 1 (%s), "%s", are read from the environment, which the security bundle refuses: it upper-cases them when the container is compiled, which loses the environment value.';

        yield 'a port' => ['rule' => "{ path: ^/admin, port: '%env(int:ADMIN_PORT)%' }", 'message' => $port];
        yield 'a port held by a parameter' => ['rule' => "{ path: ^/admin, port: '%admin_port%' }", 'message' => $port];
        yield 'methods written as a list' => ['rule' => "{ path: ^/admin, methods: ['%env(ADMIN_METHOD)%'] }", 'message' => \sprintf($methods, '%s', '%%env(ADMIN_METHOD)%%')];
        yield 'methods written as a string' => ['rule' => "{ path: ^/admin, methods: 'GET, %env(ADMIN_METHOD)%' }", 'message' => \sprintf($methods, '%s', 'GET, %%env(ADMIN_METHOD)%%')];
    }

    /**
     * A firewall pattern read from the environment, alone or in a list, is
     * kept as written and said to be so.
     */
    #[Test]
    public function test_a_firewall_pattern_read_from_the_environment_is_said_so(): void
    {
        $path = $this->makeProject(['security.yaml' => "security:\n    firewalls:\n        api: { pattern: '%env(API_PATTERN)%' }\n        main: { pattern: [^/app, '%env(APP_PATTERN)%'] }\n        dev: { pattern: ^/_profiler }\n"]).'/security.yaml';

        $firewalls = (new SecurityConfigExtractor())->extract($path)['firewalls'];

        $this->assertSame(['api' => '%env(API_PATTERN)%', 'main' => '(?:^/app|%env(APP_PATTERN)%)', 'dev' => '^/_profiler'], array_column($firewalls, 'pattern', 'name'));
        $this->assertSame(['api' => true, 'main' => true, 'dev' => false], array_column($firewalls, 'fromEnvironment', 'name'));
    }

    /**
     * An environment variable name the parameter bag refuses stops the
     * reading as any value that cannot be resolved does, with the bag's
     * reason.
     */
    #[Test]
    public function test_an_environment_name_the_parameter_bag_refuses_stops_the_reading(): void
    {
        try {
            (new ContainerBuilder())->getParameterBag()->resolveValue('%env(BAD-NAME)%');
            $this->fail('The parameter bag took a name with a hyphen.');
        } catch (DependencyInjectionException $e) {
            $reason = $e->getMessage();
        }
        $path = $this->makeProject(['security.yaml' => "security:\n    access_control:\n        - { path: '%env(BAD-NAME)%' }\n"]).'/security.yaml';

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('The security configuration value "%env(BAD-NAME)%" cannot be resolved: '.$reason);

        (new SecurityConfigExtractor())->extract($path);
    }

    #[Test]
    public function test_a_file_that_is_no_yaml_is_an_error(): void
    {
        $path = $this->makeProject(['security.yaml' => "security:\n    access_control: [{ path: ^/admin\n"]).'/security.yaml';

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('security.yaml');

        (new SecurityConfigExtractor())->extract($path);
    }

    #[Test]
    public function test_a_file_that_cannot_be_read_is_an_error(): void
    {
        $this->expectException(SecurityConfigException::class);

        (new SecurityConfigExtractor())->extract($this->makeProject().'/missing.yaml');
    }

    #[Test]
    public function test_reading_yaml_needs_symfony_yaml(): void
    {
        SecurityConfigFunctionOverrides::$missingClasses = [Yaml::class];
        $path = $this->makeProject(['security.yaml' => "security: ~\n"]).'/security.yaml';

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('composer require symfony/yaml');

        (new SecurityConfigExtractor())->extract($path);
    }

    #[Test]
    public function test_named_files_none_of_which_exists_are_an_error(): void
    {
        $project = $this->makeProject();

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('None of the provided --config files could be found.');

        (new SecurityConfigExtractor())->read([$project.'/a.yaml', $project.'/b.yaml'], null, new SecurityConfigLocator(), $project, null);
    }

    #[Test]
    public function test_a_named_file_that_is_no_yaml_is_an_error(): void
    {
        $project = $this->makeProject(['config/packages/security.php' => "<?php\n"]);

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('Only YAML files can be read with --config');

        (new SecurityConfigExtractor())->read([$project.'/config/packages/security.php'], null, new SecurityConfigLocator(), $project, null);
    }

    #[Test]
    public function test_a_named_file_that_does_not_exist_is_skipped_beside_one_that_does(): void
    {
        $project = $this->makeProject(['security.yaml' => "security:\n    access_control: [{ path: ^/admin }]\n"]);

        $result = (new SecurityConfigExtractor())->read([$project.'/missing.yaml', $project.'/security.yaml'], null, new SecurityConfigLocator(), $project, null);

        $this->assertNotNull($result);
        $this->assertSame([['file' => $project.'/missing.yaml', 'reason' => 'File not found.']], $result['skippedFiles']);
        $this->assertSame(['^/admin'], array_column($result['accessControl'], 'path'));
    }

    /**
     * Without a kernel the files found in the project are read, the YAML
     * ones: a PHP file needs the kernel that loads it.
     */
    #[Test]
    public function test_without_a_kernel_the_located_yaml_files_are_read(): void
    {
        $project = $this->makeProject([
            'config/packages/security.yaml' => "security:\n    access_control: [{ path: ^/admin }]\n",
            'config/packages/prod/security.php' => "<?php\n",
        ]);

        $result = (new SecurityConfigExtractor())->read([], null, new SecurityConfigLocator(), $project, 'prod');

        $this->assertNotNull($result);
        $this->assertSame(['^/admin'], array_column($result['accessControl'], 'path'));
        $this->assertSame(
            [['file' => $project.'/config/packages/prod/security.php', 'reason' => 'Only YAML files are read without a kernel.']],
            $result['skippedFiles'],
        );
    }

    #[Test]
    public function test_without_a_kernel_or_a_file_there_is_no_security_configuration(): void
    {
        $project = $this->makeProject();

        $this->assertNull((new SecurityConfigExtractor())->read([], null, new SecurityConfigLocator(), $project, null));
    }

    #[Test]
    public function test_a_kernel_of_its_own_making_cannot_be_read(): void
    {
        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('name its files with --config');

        (new SecurityConfigExtractor())->extractFromKernel($this->createStub(KernelInterface::class));
    }

    /**
     * A kernel of its own making builds no container the parameters can be
     * read from as the application writes them: it lends a named file none,
     * rather than values its compiled container may have read from the
     * environment.
     */
    #[Test]
    public function test_a_kernel_of_its_own_making_lends_a_named_file_no_parameter(): void
    {
        $project = $this->makeProject(['security.yaml' => "security:\n    access_control: [{ path: '%admin_path%' }]\n"]);
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getContainer')->willReturn(new Container(new ParameterBag(['admin_path' => '^/admin'])));

        $this->expectException(SecurityConfigException::class);
        $this->expectExceptionMessageIsOrContains('The parameter "admin_path" used in');

        (new SecurityConfigExtractor())->read([$project.'/security.yaml'], $kernel, new SecurityConfigLocator(), $project, null);
    }

    #[After]
    protected function restoreYaml(): void
    {
        SecurityConfigFunctionOverrides::reset();
    }

    /**
     * What SecurityExtension::load() gives the AccessMap for the first rule
     * of a YAML security file, its parameters resolved first, as
     * MergeExtensionConfigurationPass resolves them: its roles, the methods
     * and addresses its request matcher is built with, whether it has an
     * allow_if, the service id of its request_matcher and its channel.
     *
     * @return array{roles: list<string>, methods: list<string>, ips: list<string>, allowIf: bool, requestMatcher: ?string, requiresChannel: ?string}
     */
    private static function loadedRule(string $yaml): array
    {
        $config = Yaml::parse($yaml);
        self::assertIsArray($config);
        self::assertIsArray($config['security']);
        $container = new ContainerBuilder();
        foreach (\is_array($config['parameters'] ?? null) ? $config['parameters'] : [] as $name => $value) {
            self::assertIsString($value);
            $container->setParameter((string) $name, $value);
        }
        $container->registerExtension(new SecurityExtension());
        $container->loadFromExtension('security', ['firewalls' => ['main' => ['security' => false]]] + $config['security']);
        (new MergeExtensionConfigurationPass())->process($container);

        $call = $container->getDefinition('security.access_map')->getMethodCalls()[0] ?? null;
        self::assertIsArray($call);
        self::assertIsArray($call[1]);
        [$matcher, $attributes, $channel] = $call[1];
        self::assertInstanceOf(Reference::class, $matcher);
        self::assertIsArray($attributes);
        self::assertTrue(null === $channel || \is_string($channel));

        $roles = array_values(array_filter($attributes, \is_string(...)));
        $loaded = ['roles' => $roles, 'methods' => [], 'ips' => [], 'allowIf' => \count($roles) !== \count($attributes), 'requestMatcher' => null, 'requiresChannel' => $channel];
        if (!$container->hasDefinition((string) $matcher)) {
            $loaded['requestMatcher'] = (string) $matcher;

            return $loaded;
        }

        $chain = $container->getDefinition((string) $matcher)->getArgument(0);
        self::assertIsArray($chain);
        foreach ($chain as $reference) {
            self::assertInstanceOf(Reference::class, $reference);
            $definition = $container->getDefinition((string) $reference);
            $values = $definition->getArgument(0);
            if (MethodRequestMatcher::class === $definition->getClass()) {
                self::assertIsArray($values);
                $loaded['methods'] = array_values(array_filter($values, \is_string(...)));
            }
            if (IpsRequestMatcher::class === $definition->getClass()) {
                self::assertIsArray($values);
                $loaded['ips'] = array_values(array_filter($values, \is_string(...)));
            }
        }

        return $loaded;
    }
}

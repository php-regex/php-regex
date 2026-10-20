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

use PHPRegex\Symfony\Security\SecurityConfigException;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * What a security file says, read value by value, and the line each entry
 * is written on.
 */
final class SecurityConfigReadingTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_two_rules_with_the_same_path_are_each_located_on_their_own_line(): void
    {
        $rules = $this->extract("security:\n    access_control:\n        - { path: ^/admin, roles: ROLE_A }\n        - { path: ^/admin, roles: ROLE_B }\n")['accessControl'];

        $this->assertSame([3, 4], array_column($rules, 'line'));
    }

    #[Test]
    public function test_a_comment_that_names_a_value_is_not_its_location(): void
    {
        $rules = $this->extract("security:\n    access_control:\n        # path: ^/admin is the back office\n        - { path: ^/admin, roles: ROLE_A }\n")['accessControl'];

        $this->assertSame(4, $rules[0]['line']);
    }

    #[Test]
    public function test_a_double_quoted_value_is_located_with_its_backslashes_doubled(): void
    {
        $rules = $this->extract("security:\n    access_control:\n        - { roles: ROLE_A }\n        - { path: \"^/api/\\\\d+\", roles: ROLE_B }\n")['accessControl'];

        $this->assertSame('^/api/\d+', $rules[1]['path']);
        $this->assertSame(4, $rules[1]['line']);
    }

    /**
     * A value written under another key is found when no line writes it
     * under its own: the firewall pattern takes the line of the pattern.
     */
    #[Test]
    public function test_a_value_is_located_under_its_own_key_first(): void
    {
        $config = $this->extract("security:\n    firewalls:\n        admin:\n            pattern: ^/admin\n    access_control:\n        - { path: ^/admin, roles: ROLE_A }\n");

        $this->assertSame(6, $config['accessControl'][0]['line']);
        $this->assertSame(4, $config['firewalls'][0]['line']);
    }

    #[Test]
    public function test_a_key_in_double_quotes_is_the_key(): void
    {
        $config = $this->extract("security:\n    firewalls:\n        admin: { \"pattern\": ^/admin }\n    access_control:\n        - { \"path\": ^/admin }\n");

        $this->assertSame(5, $config['accessControl'][0]['line']);
        $this->assertSame(3, $config['firewalls'][0]['line']);
    }

    #[Test]
    public function test_a_firewall_is_located_at_its_own_key_and_not_at_a_longer_one(): void
    {
        $firewalls = $this->extract("parameters:\n    main_area: ^/\nsecurity:\n    firewalls:\n        main: { lazy: true }\n")['firewalls'];

        $this->assertSame(5, $firewalls[0]['line']);
    }

    #[Test]
    public function test_a_key_that_ends_a_longer_name_is_not_the_key(): void
    {
        $config = $this->extract("parameters:\n    admin_path: '%%admin'\nsecurity:\n    access_control:\n        - { roles: ROLE_A, allow_if: \"'%%admin' in roles\" }\n        - { path: '%%admin', roles: ROLE_B }\n");

        $this->assertSame(6, $config['accessControl'][1]['line']);
    }

    #[Test]
    public function test_a_firewall_without_pattern_is_located_at_its_key_however_quoted(): void
    {
        $firewalls = $this->extract("security:\n    firewalls:\n        'main': { lazy: true }\n        \"api\": { lazy: true }\n        dev: ~\n")['firewalls'];

        $this->assertSame(['main' => 3, 'api' => 4, 'dev' => 5], array_column($firewalls, 'line', 'name'));
        $this->assertSame([null, null, null], array_column($firewalls, 'pattern'));
    }

    #[Test]
    public function test_lists_and_scalars_are_read_as_the_security_bundle_reads_them(): void
    {
        $rule = $this->extract("security:\n    access_control:\n        - path: ^/admin\n          roles: ' ROLE_A, ROLE_B '\n          methods: get, post\n          ips: '127.0.0.1, ::1'\n          allow_if: ' '\n          requires_channel: https\n        - path: 404\n          ips: [10, 1.5, ~]\n")['accessControl'];

        $this->assertSame(['ROLE_A', 'ROLE_B'], $rule[0]['roles']);
        $this->assertSame(['get', 'post'], $rule[0]['methods']);
        $this->assertSame(['127.0.0.1', '::1'], $rule[0]['ips']);
        $this->assertNull($rule[0]['allowIf']);
        $this->assertSame('https', $rule[0]['requiresChannel']);
        $this->assertSame('404', $rule[1]['path']);
        $this->assertSame(['10', '1.5'], $rule[1]['ips']);
    }

    #[Test]
    public function test_a_later_block_adds_to_a_firewall_an_earlier_one_declared(): void
    {
        $firewalls = $this->extract("security:\n    firewalls:\n        main: { pattern: ^/app }\nwhen@prod:\n    security:\n        firewalls:\n            main: { lazy: true }\n", 'prod')['firewalls'];

        $this->assertSame(['main' => '^/app'], array_column($firewalls, 'pattern', 'name'));
    }

    #[Test]
    public function test_a_list_of_patterns_is_one_alternation_with_its_parameters_resolved(): void
    {
        $firewalls = $this->extract("parameters:\n    api: ^/api\nsecurity:\n    firewalls:\n        main: { pattern: ['%api%', ^/app] }\n")['firewalls'];

        $this->assertSame('(?:^/api|^/app)', $firewalls[0]['pattern']);
        $this->assertSame(5, $firewalls[0]['line']);
    }

    #[Test]
    public function test_a_file_with_constants_and_tags_is_read(): void
    {
        $rules = $this->extract("parameters:\n    limit: !php/const PHP_INT_SIZE\n    handler: !service_closure app.handler\nsecurity:\n    access_control: [{ path: ^/admin }]\n")['accessControl'];

        $this->assertSame(['^/admin'], array_column($rules, 'path'));
    }

    #[Test]
    public function test_a_parameter_that_is_no_string_is_an_error(): void
    {
        $path = $this->makeProject(['security.yaml' => "parameters:\n    admin: true\nsecurity:\n    access_control: [{ path: '%admin%' }]\n"]).'/security.yaml';

        try {
            (new SecurityConfigExtractor())->extract($path);
            $this->fail('A boolean given as a path was read.');
        } catch (SecurityConfigException $e) {
            $this->assertSame('The security configuration value "%admin%" does not resolve to a string.', $e->getMessage());
        }
    }

    #[Test]
    public function test_a_parameter_nothing_defines_names_itself_and_the_file(): void
    {
        $path = $this->makeProject(['security.yaml' => "security:\n    access_control: [{ path: '%admin%' }]\n"]).'/security.yaml';

        try {
            (new SecurityConfigExtractor())->extract($path);
            $this->fail('A parameter nothing defines was read.');
        } catch (SecurityConfigException $e) {
            $this->assertSame(\sprintf('The parameter "admin" used in "%s" is defined neither in the file nor in the application.', $path), $e->getMessage());
            $this->assertInstanceOf(ParameterNotFoundException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function test_a_file_that_does_not_parse_names_itself_and_the_reason(): void
    {
        $path = $this->makeProject(['security.yaml' => "security: [\n"]).'/security.yaml';

        try {
            (new SecurityConfigExtractor())->extract($path);
            $this->fail('A file that does not parse was read.');
        } catch (SecurityConfigException $e) {
            $this->assertStringStartsWith(\sprintf('The security file "%s" cannot be read: ', $path), $e->getMessage());
            $this->assertInstanceOf(ParseException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function test_every_named_file_is_read(): void
    {
        $project = $this->makeProject([
            'a.yaml' => "security:\n    access_control: [{ path: ^/a }]\n    firewalls: { a: { pattern: ^/a } }\n",
            'b.yaml' => "security:\n    access_control: [{ path: ^/b }]\n    firewalls: { b: { pattern: ^/b } }\n",
        ]);

        $config = (new SecurityConfigExtractor())->read([$project.'/a.yaml', $project.'/b.yaml'], null, new SecurityConfigLocator(), $project, null);

        $this->assertNotNull($config);
        $this->assertSame(['^/a', '^/b'], array_column($config['accessControl'], 'path'));
        $this->assertSame(['a', 'b'], array_column($config['firewalls'], 'name'));
    }

    #[Test]
    public function test_the_security_file_at_the_root_of_config_is_found(): void
    {
        $project = $this->makeProject(['config/security.yaml' => "security:\n    access_control: [{ path: ^/root }]\n"]);

        $this->assertSame([$project.'/config/security.yaml'], (new SecurityConfigLocator())->locate($project, 'prod'));
    }

    /**
     * @return array{accessControl: list<array{file: string, line: int, path: ?string, host: ?string, roles: array<int, string>, methods: array<int, string>, ips: array<int, string>, allowIf: ?string, requestMatcher: ?string, requiresChannel: ?string}>, firewalls: list<array{name: string, file: string, line: int, pattern: ?string, requestMatcher: ?string}>}
     */
    private function extract(string $yaml, ?string $environment = null): array
    {
        return (new SecurityConfigExtractor())->extract($this->makeProject(['security.yaml' => $yaml]).'/security.yaml', $environment);
    }
}

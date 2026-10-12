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

use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityConfigExtractorTest extends TestCase
{
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
}

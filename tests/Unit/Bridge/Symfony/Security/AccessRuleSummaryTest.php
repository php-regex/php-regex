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

use PHPRegex\Symfony\Security\AccessRuleSummary;
use PHPRegex\Symfony\Security\SecurityAccessControlReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One access_control rule on one line, as regex:security and
 * regex:analyze both print it.
 *
 * @phpstan-import-type AccessRule from SecurityAccessControlReport
 */
final class AccessRuleSummaryTest extends TestCase
{
    #[Test]
    public function test_a_rule_with_no_scope_says_its_path_and_roles(): void
    {
        $this->assertSame('#2 config/security.yaml:14  path=^/admin  roles=ROLE_ADMIN|ROLE_ROOT', AccessRuleSummary::of(self::rule(index: 2, path: '^/admin', roles: ['ROLE_ADMIN', 'ROLE_ROOT']), 'config/security.yaml:14'));
    }

    #[Test]
    public function test_a_rule_with_no_path_and_no_role_matches_any(): void
    {
        $this->assertSame('#0 a.yaml:1  path=(any)  roles=any', AccessRuleSummary::of(self::rule(path: ''), 'a.yaml:1'));
    }

    #[Test]
    public function test_the_scope_lists_what_restricts_the_rule(): void
    {
        $rule = self::rule(methods: ['GET', 'POST'], host: 'example.com', ips: ['127.0.0.1'], requiresChannel: 'https');

        $this->assertSame('#0 a.yaml:1  path=(any)  roles=any  scope=methods=GET|POST • host=example.com • ips=127.0.0.1 • channel=https', AccessRuleSummary::of($rule, 'a.yaml:1'));
    }

    /**
     * `host: ''` in access_control restricts nothing: no "scope=" with
     * nothing after it.
     */
    #[Test]
    public function test_a_blank_host_or_channel_is_no_scope(): void
    {
        $this->assertSame('#0 a.yaml:1  path=(any)  roles=any', AccessRuleSummary::of(self::rule(host: ''), 'a.yaml:1'));
        $this->assertSame('#0 a.yaml:1  path=(any)  roles=any', AccessRuleSummary::of(self::rule(host: ' ', requiresChannel: "\t"), 'a.yaml:1'));
    }

    #[Test]
    #[DataProvider('provideFiles')]
    public function test_is_yaml_file_reads_the_extension_without_case(string $path, bool $expected): void
    {
        $this->assertSame($expected, AccessRuleSummary::isYamlFile($path));
    }

    /**
     * @return iterable<string, array{path: string, expected: bool}>
     */
    public static function provideFiles(): iterable
    {
        yield 'yaml' => ['path' => 'config/packages/security.yaml', 'expected' => true];
        yield 'yml, upper case' => ['path' => 'security.YML', 'expected' => true];
        yield 'php' => ['path' => 'security.php', 'expected' => false];
        yield 'no extension' => ['path' => 'security', 'expected' => false];
    }

    /**
     * @param array<int, string> $roles
     * @param array<int, string> $methods
     * @param array<int, string> $ips
     *
     * @return AccessRule
     */
    private static function rule(int $index = 0, ?string $path = null, array $roles = [], array $methods = [], ?string $host = null, array $ips = [], ?string $requiresChannel = null): array
    {
        return [
            'index' => $index, 'file' => 'a.yaml', 'line' => 1, 'path' => $path, 'pattern' => '', 'roles' => $roles, 'methods' => $methods,
            'host' => $host, 'hostPattern' => null, 'hostUnsupported' => false, 'ips' => $ips, 'allowIf' => null,
            'requiresChannel' => $requiresChannel, 'accessLevel' => 'roles', 'notes' => [],
        ];
    }
}

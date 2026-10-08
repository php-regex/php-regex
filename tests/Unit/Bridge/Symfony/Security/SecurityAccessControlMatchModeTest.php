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

use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessControlReport;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the security analyzer correctly detects conflicts
 * using FULL match mode with manually wrapped search patterns.
 */
final class SecurityAccessControlMatchModeTest extends TestCase
{
    #[Test]
    public function test_detects_prefix_shadowing_without_anchors(): void
    {
        $rules = $this->buildRules([
            ['^/api', ['PUBLIC_ACCESS']],
            ['^/api/admin', ['ROLE_ADMIN']],
        ]);

        $report = $this->analyze($rules);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['critical']);
    }

    #[Test]
    public function test_detects_shadowing_with_unanchored_paths(): void
    {
        $rules = $this->buildRules([
            ['admin', ['PUBLIC_ACCESS']],
            ['admin.secure', ['ROLE_ADMIN']],
        ]);

        $report = $this->analyze($rules);

        $this->assertSame(1, $report->stats['shadowed']);
    }

    #[Test]
    public function test_detects_shadowing_with_regex_path(): void
    {
        $rules = $this->buildRules([
            ['^/api/.*', ['PUBLIC_ACCESS']],
            ['^/api/admin', ['ROLE_ADMIN']],
        ]);

        $report = $this->analyze($rules);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['critical']);
    }

    #[Test]
    public function test_no_conflict_for_disjoint_paths(): void
    {
        $rules = $this->buildRules([
            ['^/admin', ['ROLE_ADMIN']],
            ['^/public', ['PUBLIC_ACCESS']],
        ]);

        $report = $this->analyze($rules);

        $this->assertSame(0, $report->stats['shadowed']);
        $this->assertSame(0, $report->stats['conflicts']);
    }

    #[Test]
    public function test_no_conflict_for_disjoint_methods(): void
    {
        $rules = $this->buildRules([
            ['^/api', ['PUBLIC_ACCESS'], ['GET']],
            ['^/api', ['ROLE_ADMIN'], ['POST']],
        ]);

        $report = $this->analyze($rules);

        $this->assertSame(0, $report->stats['conflicts']);
    }

    #[Test]
    public function test_detects_equivalent_rules_as_redundant(): void
    {
        $rules = $this->buildRules([
            ['^/admin', ['ROLE_ADMIN']],
            ['^/admin', ['ROLE_ADMIN']],
        ]);

        $report = $this->analyze($rules);

        $this->assertSame(1, $report->stats['equivalent']);
        $this->assertSame(1, $report->stats['redundant']);
    }

    #[Test]
    public function test_empty_path_matches_all_requests(): void
    {
        $rules = $this->buildRules([
            ['', ['PUBLIC_ACCESS']],
            ['^/admin', ['ROLE_ADMIN']],
        ]);

        $report = $this->analyze($rules);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['critical']);
    }

    #[Test]
    public function test_fully_anchored_path_is_precise(): void
    {
        $rules = $this->buildRules([
            ['^/api$', ['PUBLIC_ACCESS']],
            ['^/api/admin', ['ROLE_ADMIN']],
        ]);

        $report = $this->analyze($rules);

        $this->assertSame(0, $report->stats['shadowed']);
    }

    /**
     * @param array<int, array{string, array<string>, array<string>}|array{string, array<string>}> $definitions
     *
     * @return array<int, array{file: string, line: int, path: ?string, host: ?string, roles: array<int, string>, methods: array<int, string>, ips: array<int, string>, allowIf: ?string, requestMatcher: ?string, requiresChannel: ?string}>
     */
    private function buildRules(array $definitions): array
    {
        $rules = [];
        $line = 10;

        foreach ($definitions as $def) {
            $path = $def[0];
            /** @var array<int, string> $roles */
            $roles = array_values($def[1]);
            /** @var array<int, string> $methods */
            $methods = array_values($def[2] ?? []);

            $rules[] = [
                'file' => 'security.yaml',
                'line' => $line,
                'path' => $path,
                'host' => null,
                'roles' => $roles,
                'methods' => $methods,
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ];
            $line += 2;
        }

        return $rules;
    }

    /**
     * @param array<int, array{file: string, line: int, path: ?string, host: ?string, roles: array<int, string>, methods: array<int, string>, ips: array<int, string>, allowIf: ?string, requestMatcher: ?string, requiresChannel: ?string}> $rules
     */
    private function analyze(array $rules): SecurityAccessControlReport
    {
        $analyzer = new SecurityAccessControlAnalyzer(Regex::create());

        return $analyzer->analyze($rules, true);
    }
}

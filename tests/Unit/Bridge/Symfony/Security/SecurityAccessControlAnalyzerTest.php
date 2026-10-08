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
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityAccessControlAnalyzerTest extends TestCase
{
    #[Test]
    public function test_detects_critical_shadowing(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_access_control.yaml';
        $extractor = new SecurityConfigExtractor();
        $result = $extractor->extract($path);

        $analyzer = new SecurityAccessControlAnalyzer(Regex::create());
        $report = $analyzer->analyze($result['accessControl']);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['critical']);
        $this->assertCount(1, $report->conflicts);
        $this->assertSame('shadowed', $report->conflicts[0]['type']);
        $this->assertSame('critical', $report->conflicts[0]['severity']);
    }

    /**
     * Symfony matches a host with preg_match('{'.$host.'}i', ...): two hosts
     * that differ in case only overlap, and the second rule is shadowed.
     */
    #[Test]
    public function test_hosts_are_compared_without_case(): void
    {
        $rules = [
            [
                'file' => 'security.yaml',
                'line' => 10,
                'path' => '^/admin',
                'host' => '^ADMIN\\.example\\.com$',
                'roles' => ['PUBLIC_ACCESS'],
                'methods' => [],
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ],
            [
                'file' => 'security.yaml',
                'line' => 12,
                'path' => '^/admin',
                'host' => '^admin\\.example\\.com$',
                'roles' => ['ROLE_ADMIN'],
                'methods' => [],
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ],
        ];

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze($rules);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame([], $report->skippedRules);
    }

    #[Test]
    public function test_detects_prefix_shadowing_with_search_semantics(): void
    {
        $rules = [
            [
                'file' => 'security.yaml',
                'line' => 10,
                'path' => '^/admin',
                'host' => null,
                'roles' => ['PUBLIC_ACCESS'],
                'methods' => [],
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ],
            [
                'file' => 'security.yaml',
                'line' => 12,
                'path' => '^/admin/secure',
                'host' => null,
                'roles' => ['ROLE_ADMIN'],
                'methods' => [],
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ],
        ];

        $analyzer = new SecurityAccessControlAnalyzer(Regex::create());
        $report = $analyzer->analyze($rules);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['critical']);
        $this->assertSame('shadowed', $report->conflicts[0]['type']);
    }

    /**
     * Symfony matches a path with preg_match('{'.$path.'}s', ...): a path
     * that looks delimited is a fragment like any other, "#^/admin#x"
     * starting with a "#", and the rule is analysed, not skipped.
     */
    #[Test]
    public function test_a_path_that_looks_delimited_is_read_as_a_fragment(): void
    {
        $analyzer = new SecurityAccessControlAnalyzer(Regex::create());
        $report = $analyzer->analyze([[
            'file' => 'security.yaml',
            'line' => 3,
            'path' => '#^/admin#x',
            'host' => null,
            'roles' => ['ROLE_ADMIN'],
            'methods' => [],
            'ips' => [],
            'allowIf' => null,
            'requestMatcher' => null,
            'requiresChannel' => null,
        ]]);

        $this->assertCount(0, $report->skippedRules);
        $this->assertSame(1, $report->stats['rules']);
    }
}

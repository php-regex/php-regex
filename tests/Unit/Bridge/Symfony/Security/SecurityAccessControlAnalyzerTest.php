<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Security;

use PhpRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PhpRegex\Symfony\Security\SecurityConfigExtractor;
use PhpRegex\Toolkit\Regex;
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
     * The automata read "i" and "s" only: a rule whose path needs another
     * flag is skipped, and the report says which flag.
     */
    #[Test]
    public function test_a_path_with_a_flag_the_automata_do_not_read_is_skipped_with_that_flag(): void
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

        $this->assertCount(1, $report->skippedRules);
        $this->assertSame('Unsupported regex flags: x.', $report->skippedRules[0]['reason']);
    }
}

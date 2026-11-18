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

use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;

final class SecurityFirewallAnalyzerTest extends TestCase
{
    #[Test]
    public function test_flags_dangerous_firewall_patterns(): void
    {
        $firewalls = [
            [
                'name' => 'main',
                'file' => 'security.yaml',
                'line' => 1,
                'pattern' => '^/api/(a+)+$',
                'requestMatcher' => null,
            ],
        ];

        $analyzer = new SecurityFirewallAnalyzer(Regex::create());
        $report = $analyzer->analyze($firewalls, RedosSeverity::High);

        $this->assertSame(1, $report->stats['flagged']);
        $this->assertSame('main', $report->findings[0]['name']);
        $this->assertContains($report->findings[0]['severity'], ['high', 'critical']);
    }

    /**
     * A pattern the library refuses comes back as the analysis error: the
     * firewall is left out with it, and the next one is still analysed.
     */
    #[Test]
    public function test_a_pattern_the_library_refuses_skips_its_firewall(): void
    {
        $report = (new SecurityFirewallAnalyzer(Regex::create()))->analyze([
            ['name' => 'broken', 'file' => 'security.yaml', 'line' => 4, 'pattern' => '^/(a', 'requestMatcher' => null],
            ['name' => 'main', 'file' => 'security.yaml', 'line' => 6, 'pattern' => '^/api/(a+)+$', 'requestMatcher' => null],
        ], RedosSeverity::High);

        $this->assertCount(1, $report->skippedFirewalls);
        $this->assertSame('broken', $report->skippedFirewalls[0]['name']);
        $this->assertNotSame('', $report->skippedFirewalls[0]['reason']);
        $this->assertStringNotContainsString('ReDoS analysis failed', $report->skippedFirewalls[0]['reason']);
        $this->assertSame(['main'], array_column($report->findings, 'name'));
    }

    /**
     * The security bundle compiles a firewall pattern as written: one
     * ending on a backslash and a line feed matches that line feed, and
     * its firewall is analysed, not skipped.
     */
    #[Test]
    public function test_a_pattern_ending_on_an_escaped_line_feed_is_analysed(): void
    {
        $pattern = "^/api/(a+)+\\\n";
        $this->assertTrue((new PathRequestMatcher($pattern))->matches(Request::create('/api/aa%0A')));

        $report = (new SecurityFirewallAnalyzer(Regex::create()))->analyze([
            ['name' => 'api', 'file' => 'security.yaml', 'line' => 4, 'pattern' => $pattern, 'requestMatcher' => null],
        ], RedosSeverity::High);

        $this->assertSame([], $report->skippedFirewalls);
        $this->assertSame(['api'], array_column($report->findings, 'name'));
    }

    /**
     * A pattern read from the environment is known at run time only: its
     * firewall is left out of the analysis, and the report says why.
     */
    #[Test]
    public function test_a_pattern_read_from_the_environment_is_not_analysed(): void
    {
        $report = (new SecurityFirewallAnalyzer(Regex::create()))->analyze([
            ['name' => 'api', 'file' => 'security.yaml', 'line' => 4, 'pattern' => '%env(API_PATTERN)%', 'requestMatcher' => null, 'fromEnvironment' => true],
        ], RedosSeverity::High);

        $this->assertSame([['name' => 'api', 'file' => 'security.yaml', 'line' => 4, 'reason' => 'The pattern is read from the environment and is not evaluated.']], $report->skippedFirewalls);
        $this->assertSame([], $report->findings);
    }
}

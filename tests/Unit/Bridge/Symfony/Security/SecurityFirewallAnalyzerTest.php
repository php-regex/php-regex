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
}

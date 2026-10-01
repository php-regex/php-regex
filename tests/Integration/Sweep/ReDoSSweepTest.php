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

namespace PHPRegex\Tests\Integration\Sweep;

use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Redos\Finding;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\AnalysisReport;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through ReDoS.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class ReDoSSweepTest extends TestCase
{
    public function test_analysis_report_optimizations(): void
    {
        $optimizations = new OptimizationResult('/a+b/', '/a+b/', []);
        $redos = new RedosAnalysis(RedosSeverity::Safe, 0);

        $report = new AnalysisReport(
            isValid: true,
            errors: [],
            lintIssues: [],
            redos: $redos,
            optimizations: $optimizations,
            explain: 'Test explanation',
            highlighted: '<span>Test</span>',
        );

        $this->assertSame($optimizations, $report->optimizations());
        $this->assertSame('Test explanation', $report->explain());
        $this->assertSame('<span>Test</span>', $report->highlighted());
    }

    public function test_redos_analysis_get_vulnerable_subpattern_with_both(): void
    {
        $analysis = new RedosAnalysis(
            RedosSeverity::High,
            8,
            vulnerablePart: '(a+)+',
            vulnerableSubpattern: 'nested',
            trigger: 'aaaaaaaaab',
            confidence: RedosConfidence::High,
            findings: [],
        );

        $this->assertSame('nested', $analysis->getVulnerableSubpattern());
    }

    public function test_redos_analysis_get_vulnerable_subpattern_with_part_only(): void
    {
        $analysis = new RedosAnalysis(
            RedosSeverity::High,
            8,
            vulnerablePart: '(a+)+',
            vulnerableSubpattern: null,
            trigger: 'aaaaaaaaab',
            confidence: RedosConfidence::High,
            findings: [],
        );

        $this->assertSame('(a+)+', $analysis->getVulnerableSubpattern());
    }

    public function test_redos_analysis_get_vulnerable_subpattern_null(): void
    {
        $analysis = new RedosAnalysis(
            RedosSeverity::Safe,
            0,
            vulnerablePart: null,
            vulnerableSubpattern: null,
        );

        $this->assertNull($analysis->getVulnerableSubpattern());
    }

    public function test_redos_analysis_with_findings(): void
    {
        $findings = [
            new Finding(
                RedosSeverity::High,
                'nested quantifiers',
                '(a+)+',
                'aaaaaaaaab',
            ),
        ];

        $analysis = new RedosAnalysis(
            RedosSeverity::High,
            8,
            vulnerablePart: '(a+)+',
            findings: $findings,
            confidence: RedosConfidence::High,
        );

        $this->assertCount(1, $analysis->findings);
        $this->assertSame('nested quantifiers', $analysis->findings[0]->message);
    }

    public function test_redos_analysis_with_suggested_rewrite(): void
    {
        $analysis = new RedosAnalysis(
            RedosSeverity::High,
            8,
            vulnerablePart: '(a+)+',
            suggestedRewrite: '(?:a+)+',
            recommendations: ['Use atomic group'],
        );

        $this->assertSame('(?:a+)+', $analysis->suggestedRewrite);
        $this->assertSame(['Use atomic group'], $analysis->recommendations);
    }
}

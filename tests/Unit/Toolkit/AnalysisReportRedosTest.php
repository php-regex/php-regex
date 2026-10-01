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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regex::analyze() hands the proven verdict through its report: the
 * report holds no ReDoS wording of its own, it carries the analysis.
 */
final class AnalysisReportRedosTest extends TestCase
{
    /**
     * a…a! makes preg_match() fail at n=19 (PCRE2 10.49).
     */
    #[Test]
    public function test_analysis_report_carries_the_proven_verdict(): void
    {
        $redos = Regex::create()->analyze('/(a+)+$/')->redos();

        $this->assertSame(RedosProof::Proven, $redos->proof);
        $this->assertSame(RedosComplexity::Exponential, $redos->complexity);
        $this->assertSame(RedosSeverity::Critical, $redos->severity);
        $this->assertInstanceOf(RedosWitness::class, $redos->witness);
        $this->assertSame('a', $redos->witness->pump);
    }

    #[Test]
    public function test_analysis_report_of_an_ignored_pattern_is_not_analyzed(): void
    {
        $redos = Regex::create(['redos_ignored_patterns' => ['/(a+)+$/']])->analyze('/(a+)+$/')->redos();

        $this->assertSame(RedosProof::NotAnalyzed, $redos->proof);
        $this->assertSame(RedosComplexity::Unknown, $redos->complexity);
        $this->assertNull($redos->witness);
        $this->assertFalse($redos->isProvenSafe());
    }
}

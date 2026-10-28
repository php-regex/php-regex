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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern the caller asked not to analyse, ignored or with the analysis
 * off, reads as safe, as asked, and says it was not analysed; a pattern
 * the analysis failed on is unknown, scored as a medium risk, never safe.
 */
final class RedosNotAnalyzedTest extends TestCase
{
    #[Test]
    public function test_a_pattern_not_analysed_as_asked_reads_safe_and_says_so(): void
    {
        foreach ([(new RedosAnalyzer(ignoredPatterns: ['(a+)+b']))->analyze('/(a+)+b/'), (new RedosAnalyzer())->analyze('/(a+)+b/', null, RedosMode::Off)] as $analysis) {
            $this->assertTrue($analysis->isSafe());
            $this->assertFalse($analysis->isProvenSafe());
            $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);
        }
    }

    #[Test]
    public function test_a_failed_analysis_is_unknown_never_safe(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a/');

        $this->assertSame(RedosSeverity::Unknown, $analysis->severity);
        $this->assertFalse($analysis->isSafe());
        $this->assertSame(5, $analysis->score);
    }
}

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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one headline every consumer prints: it names the class and who
 * decided it, never a bare "safe".
 */
final class RedosHeadlineTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAnalyses')]
    public function test_headline_rule(RedosAnalysis $analysis, string $headline): void
    {
        $this->assertSame($headline, $analysis->headline());
    }

    /**
     * @return iterable<string, array{analysis: RedosAnalysis, headline: string}>
     */
    public static function provideAnalyses(): iterable
    {
        // Proven.
        yield 'proven linear' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, complexity: RedosComplexity::Linear, proof: RedosProof::Proven),
            'headline' => 'safe (proven)',
        ];
        yield 'proven exponential' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 10, complexity: RedosComplexity::Exponential, proof: RedosProof::Proven),
            'headline' => 'Exponential backtracking (proven)',
        ];
        yield 'proven polynomial of degree 2' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Medium, 5, complexity: RedosComplexity::Polynomial, degree: 2, proof: RedosProof::Proven),
            'headline' => 'Polynomial backtracking, degree 2 (proven)',
        ];
        yield 'proven polynomial of degree 3' => [
            'analysis' => new RedosAnalysis(RedosSeverity::High, 8, complexity: RedosComplexity::Polynomial, degree: 3, proof: RedosProof::Proven),
            'headline' => 'Polynomial backtracking, degree 3 (proven)',
        ];
        yield 'proven polynomial without a degree' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Medium, 5, complexity: RedosComplexity::Polynomial, proof: RedosProof::Proven),
            'headline' => 'Polynomial backtracking (proven)',
        ];
        // Proven with no class never says safe: the heuristic rule decides.
        yield 'proven without a class, severity safe' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, complexity: RedosComplexity::Unknown, proof: RedosProof::Proven),
            'headline' => 'no risk found (heuristic)',
        ];
        yield 'proven without a class, severity high' => [
            'analysis' => new RedosAnalysis(RedosSeverity::High, 8, complexity: RedosComplexity::Unknown, proof: RedosProof::Proven),
            'headline' => 'Potential backtracking (heuristic)',
        ];

        // Heuristic: anything above safe, low included, is a finding.
        yield 'heuristic, severity safe' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, proof: RedosProof::Heuristic),
            'headline' => 'no risk found (heuristic)',
        ];
        foreach ([RedosSeverity::Low, RedosSeverity::Unknown, RedosSeverity::Medium, RedosSeverity::High, RedosSeverity::Critical] as $severity) {
            yield 'heuristic, severity '.$severity->value => [
                'analysis' => new RedosAnalysis($severity, 1, proof: RedosProof::Heuristic),
                'headline' => 'Potential backtracking (heuristic)',
            ];
        }

        // Budget exceeded: a heuristic finding wins over the budget.
        yield 'budget exceeded, severity safe' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, proof: RedosProof::BudgetExceeded),
            'headline' => 'not analyzed (budget exceeded)',
        ];
        yield 'budget exceeded, severity low' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Low, 2, proof: RedosProof::BudgetExceeded),
            'headline' => 'Potential backtracking (heuristic)',
        ];
        yield 'budget exceeded, severity critical' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 10, proof: RedosProof::BudgetExceeded),
            'headline' => 'Potential backtracking (heuristic)',
        ];

        // Not analysed.
        yield 'not analyzed without error' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, proof: RedosProof::NotAnalyzed),
            'headline' => 'not analyzed',
        ];
        yield 'not analyzed, mode off' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, mode: RedosMode::Off, proof: RedosProof::NotAnalyzed),
            'headline' => 'not analyzed',
        ];
        yield 'not analyzed after an error' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Unknown, 0, error: 'boom', proof: RedosProof::NotAnalyzed),
            'headline' => 'not analyzed (analysis error)',
        ];
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_headline_of_an_analysed_pattern(string $pattern, string $headline): void
    {
        $this->assertSame($headline, (new RedosAnalyzer())->analyze($pattern)->headline());
    }

    /**
     * @return iterable<string, array{pattern: string, headline: string}>
     */
    public static function providePatterns(): iterable
    {
        // a…a! fails at n=19 (PCRE2 10.49, JIT on and off).
        yield 'nested plus' => ['pattern' => '/(a+)+$/', 'headline' => 'Exponential backtracking (proven)'];
        // a…a! fails at n=1411 with the JIT (PCRE2 10.49).
        yield 'three stars' => ['pattern' => '/a*a*a*$/', 'headline' => 'Polynomial backtracking, degree 3 (proven)'];
        // A literal: one way to read any input.
        yield 'anchored literal' => ['pattern' => '/^abc$/', 'headline' => 'safe (proven)'];
    }
}

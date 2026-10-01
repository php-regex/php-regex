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

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProfiler;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where no proof is given: constructs outside the model, a pattern over the
 * budget, and patterns not analysed at all. The heuristics keep deciding the
 * severity, and the result says it was not proven.
 */
final class RedosProofFallbackTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOutOfModelPatterns')]
    public function test_out_of_model_pattern_falls_back_to_heuristics(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertNull($analysis->error, $pattern);
        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity, $pattern);
        $this->assertNull($analysis->degree, $pattern);
        $this->assertNull($analysis->witness, $pattern);
        $this->assertSame(self::heuristicSeverity($pattern), $analysis->severity, $pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOutOfModelPatterns(): iterable
    {
        yield 'backreference' => ['/(a)\1+/'];
        // A bare (?(1)a|b) is refused by PCRE ("reference to non-existent subpattern"):
        // the group is defined here so the pattern compiles.
        yield 'conditional' => ['/(a)?(?(1)a|b)/'];
        // A bare (?R) compiles, then every match fails on 10.49: "JIT stack limit exhausted".
        yield 'bare recursion' => ['/(?R)/'];
        yield 'balanced recursion' => ['/a(?R)?b/'];
        yield 'subroutine call' => ['/(a+)(?1)$/'];
        yield 'backtracking verb' => ['/a+(*SKIP)b/'];
        yield 'callout' => ['/(?C1)a+/'];
        // \X is out of the model; (\X+)+$ never fails up to n=64 on 10.49.
        yield 'extended grapheme cluster' => ['/(\X+)+$/u'];
        // Out of the model even with a nested loop next to the backreference.
        yield 'nested loop with a backreference' => ['/(a+)+\1$/'];
    }

    #[Test]
    #[DataProvider('provideOverBudgetPatterns')]
    public function test_pattern_over_the_budget_falls_back_to_heuristics(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertNull($analysis->error);
        $this->assertSame(RedosProof::BudgetExceeded, $analysis->proof);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity);
        $this->assertNull($analysis->degree);
        $this->assertNull($analysis->witness);
        $this->assertSame(self::heuristicSeverity($pattern), $analysis->severity);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOverBudgetPatterns(): iterable
    {
        // 16 x 16 x 16 = 4,096 unrolled states, every bound at the cutoff.
        yield 'nested bounded repeats' => ['/^(?:(?:a{16}){16}){16}$/'];
        // The same, followed by a nested loop the heuristics judge critical.
        yield 'nested bounded repeats before a nested loop' => ['/^(?:(?:a{16}){16}){16}(a+)+$/'];
        // 200 alternatives of 20 states each.
        yield 'huge alternation of bounded repeats' => ['/^(?:'.implode('|', array_map(
            static fn (int $i): string => \sprintf('k%03d\d{16}', $i),
            range(0, 199),
        )).')$/'];
    }

    #[Test]
    public function test_budget_verdict_is_deterministic(): void
    {
        $pattern = '/^(?:(?:a{16}){16}){16}(a+)+$/';

        $first = (new RedosAnalyzer())->analyze($pattern);
        $second = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::BudgetExceeded, $first->proof);
        $this->assertSame(json_encode($first, \JSON_THROW_ON_ERROR), json_encode($second, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function test_step_budget_comes_from_the_options(): void
    {
        $analyzer = new RedosAnalyzer(options: new RedosOptions(maxSteps: 1));

        $analysis = $analyzer->analyze('/(a+)+$/');

        $this->assertSame(RedosProof::BudgetExceeded, $analysis->proof);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity);
        $this->assertSame(self::heuristicSeverity('/(a+)+$/'), $analysis->severity);
    }

    #[Test]
    public function test_state_budget_comes_from_the_options(): void
    {
        $analyzer = new RedosAnalyzer(options: new RedosOptions(maxStates: 1));

        $analysis = $analyzer->analyze('/(a+)+$/');

        $this->assertSame(RedosProof::BudgetExceeded, $analysis->proof);
        $this->assertSame(self::heuristicSeverity('/(a+)+$/'), $analysis->severity);
    }

    #[Test]
    public function test_mode_off_is_not_analyzed(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a+)+$/', null, RedosMode::Off);

        $this->assertNotAnalyzed($analysis);
        $this->assertSame(RedosSeverity::Safe, $analysis->severity);
    }

    #[Test]
    public function test_ignored_pattern_is_not_analyzed(): void
    {
        $analysis = (new RedosAnalyzer(null, ['/(a+)+$/']))->analyze('/(a+)+$/');

        $this->assertNotAnalyzed($analysis);
        $this->assertSame(RedosSeverity::Safe, $analysis->severity);
    }

    #[Test]
    public function test_analysis_error_is_not_analyzed(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(unclosed/');

        $this->assertNotAnalyzed($analysis);
        $this->assertSame(RedosSeverity::Unknown, $analysis->severity);
        $this->assertNotNull($analysis->error);
    }

    private function assertNotAnalyzed(RedosAnalysis $analysis): void
    {
        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity);
        $this->assertNull($analysis->degree);
        $this->assertNull($analysis->witness);
        $this->assertNull($analysis->replayed);
        $this->assertFalse($analysis->isProvenSafe());
    }

    /**
     * What the structural heuristics alone say about a pattern: the verdict
     * a result not proven must keep.
     */
    private static function heuristicSeverity(string $pattern): RedosSeverity
    {
        $ast = RegexParser::create()->parse($pattern);
        $profiler = new RedosProfiler(new CharSetAnalyzer($ast->flags));
        $ast->accept($profiler);

        return $profiler->getResult()['severity'];
    }
}

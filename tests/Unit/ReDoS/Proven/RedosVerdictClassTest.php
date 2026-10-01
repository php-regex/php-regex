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

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The complexity class of one match attempt, proven on the pattern as a
 * backtracking engine following PCRE's order sees it.
 *
 * Every row is grounded in the engine (PHP 8.4.26, PCRE2 10.49,
 * pcre.backtrack_limit 1000000): "fails at n" is the smallest number of
 * pumped characters, followed by "!", for which preg_match() returns false
 * with "Backtrack limit exhausted", JIT on / JIT off.
 */
final class RedosVerdictClassTest extends TestCase
{
    #[Test]
    #[DataProvider('provideExponentialPatterns')]
    public function test_proven_verdict_exponential_patterns(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $this->assertNull($analysis->degree, $pattern);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity, $pattern);
        $this->assertNotNull($analysis->witness, $pattern);
        $this->assertNotSame('', $analysis->witness->pump, $pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExponentialPatterns(): iterable
    {
        // The table from the literature.
        yield 'nested plus, fails at n=19/19' => ['/(a+)+$/'];
        yield 'identical alternatives, fails at n=19/18' => ['/(a|a)*$/'];
        yield 'overlapping alternatives, fails at n=28/26' => ['/(a|aa)*$/'];
        yield 'word then optional space, fails at n=19/19' => ['/(\w+\s?)+$/'];
        yield 'anchored digits, fails at n=19/19 on 1…1!' => ['/^(\d+)*$/'];
        // a…a! never fails (required "b" absent); a…a!b fails at n=19 (length 21).
        yield 'required literal after the loop' => ['/(a+)+b/'];
        // Lookaround body as its own sub-search: a…a!b fails at n=19; a…a! never fails.
        yield 'lookahead body' => ['/(?=(a+)+b)/'];
        // Lazy order does not change the class: fails at n=19/19.
        yield 'lazy inner quantifier' => ['/(a+?)+$/'];
        // Bounded repeats: {1,5} unrolled (fails at n=20/19), {1,20} abstracted (fails at n=19/19).
        yield 'bounded repeat unrolled' => ['/(a{1,5})+$/'];
        yield 'bounded repeat abstracted' => ['/(a{1,20})+$/'];
        // Unicode: é is in \w under /u (preg_match('/^\w$/u', 'é') === 1); é…é! fails at n=19/18,
        // a…a! never fails: the ambiguity is on é only.
        yield 'word class and e acute under u' => ['/(\w|é)+$/u'];
        // Case folding under /iu: é…é! and É…É! fail at n=19.
        yield 'case folded e acute under iu' => ['/(é|É)+$/iu'];
        // The Kelvin sign U+212A folds to k under /iu: k…k! fails at n=19.
        yield 'kelvin sign folded under iu' => ['/(k|\x{212A})+$/iu'];
        yield 'ascii case folded under i' => ['/(a|A)+$/i'];
        // \w ∩ \d = [0-9]: 1…1! fails at n=19.
        yield 'digit inside word class' => ['/(\w|\d)+$/'];
        // Edge-case walk: groups, classes, flags.
        yield 'named group, fails at n=19' => ['/(?<x>a+)+$/'];
        yield 'branch reset, fails at n=19' => ['/(?|(a+)|(b+))+$/'];
        yield 'posix class inside class, fails at n=19' => ['/([[:alpha:]]+)+$/'];
        yield 'x mode with comment, fails at n=19' => ["/( a+ )+ \$ # comment\n/x"];
        yield 'm mode dollar, fails at n=19' => ['/(a+)+$/m'];
        yield 'three nested loops, fails at n=13' => ['/((a+)+)+$/'];
        // The dot matches "!": a…a! matches; a…a\n! fails at n=19 (a…a\n alone matches, $ before a final \n).
        yield 'dot, fails at n=19 before a newline' => ['/(.+)+$/'];
    }

    #[Test]
    #[DataProvider('providePolynomialPatterns')]
    public function test_proven_verdict_polynomial_patterns(string $pattern, int $degree, RedosSeverity $severity): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Polynomial, $analysis->complexity, $pattern);
        $this->assertSame($degree, $analysis->degree, $pattern);
        $this->assertSame($severity, $analysis->severity, $pattern);
        $this->assertNotNull($analysis->witness, $pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern);
    }

    /**
     * @return iterable<string, array{string, int, RedosSeverity}>
     */
    public static function providePolynomialPatterns(): iterable
    {
        // (*NO_JIT): 0.69 s at n=2000, 5.4 s at 4000, 43.9 s at 8000 (cubic over every start
        // position, quadratic per attempt); the backtrack limit never trips.
        yield 'two stars, degree 2' => ['/a*a*$/', 2, RedosSeverity::Medium];
        // Same timings as a*a*$ on 1…1!.
        yield 'two digit runs, degree 2' => ['/\d+\d+$/', 2, RedosSeverity::Medium];
        // Fails at n=1411 (JIT, 10.49).
        yield 'three stars, degree 3' => ['/a*a*a*$/', 3, RedosSeverity::High];
    }

    #[Test]
    #[DataProvider('provideLinearPatterns')]
    public function test_proven_verdict_linear_patterns(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertNull($analysis->degree, $pattern);
        $this->assertNull($analysis->witness, $pattern);
        $this->assertSame(RedosSeverity::Safe, $analysis->severity, $pattern);
        $this->assertSame(RedosConfidence::High, $analysis->confidenceLevel(), $pattern);
        $this->assertTrue($analysis->isProvenSafe(), $pattern);
        $this->assertTrue($analysis->isSafe(), $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLinearPatterns(): iterable
    {
        // None of these fails up to n=3000 on the engine.
        yield 'anchored plus' => ['/^a+$/'];
        yield 'plus then literal' => ['/a+b/'];
        // Linear per attempt; over every start position the time grows x4 per doubling
        // (1.2 ms at n=2000, 17 ms at 8000, JIT off): the unanchored retries the guarantee leaves out.
        yield 'atomic body' => ['/(?>a+)+$/'];
        yield 'possessive body' => ['/(a++)+$/'];
        yield 'disjoint alternatives' => ['/^(a|b)*$/'];
        yield 'hostname label, abstracted bound' => ['/[a-z0-9-]{1,63}/'];
        yield 'word run, abstracted bound' => ['/\w{2,64}/'];
        yield 'lookahead with a linear body' => ['/(?=a+)/'];
        // \p{L} and \d share no code point under /u (\d is ASCII only without UCP):
        // a…a!, 1…1! and U+0660…! never fail.
        yield 'letters or digits under u' => ['/(\p{L}|\d)+$/u'];
        yield 'e acute and E acute without i' => ['/(é|É)+$/u'];
        yield 'kelvin sign without i' => ['/(k|\x{212A})+$/u'];
        yield 'ascii cases without i' => ['/(a|A)+$/'];
        // Edge-case walk: boundaries and nesting.
        yield 'empty pattern' => ['//'];
        yield 'one character' => ['/a/'];
        yield 'vulnerable group repeated zero times' => ['/(?:(a+)+){0}$/'];
        yield 'vulnerable group repeated zero to zero times' => ['/(?:(a+)+){0,0}$/'];
        yield 'atomic group inside a lookahead' => ['/(?=(?>a+)+b)/'];
        // Under /s the dot takes every character: no continuation rejects, so nothing backtracks
        // (a…a!, a…a\n! and a…a\n never fail).
        yield 'dot under s accepts every continuation' => ['/(.+)+$/s'];
        // Unanchored: any continuation is accepted after the longest run (a…a! matches at once).
        yield 'nested plus without an end constraint' => ['/(a+)+/'];
        yield 'deep nesting of single characters' => ['/'.str_repeat('(', 200).'a'.str_repeat(')', 200).'/'];
    }

    #[Test]
    public function test_bounded_repeat_up_to_the_cutoff_is_unrolled_without_abstraction(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a{1,5})+$/');

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertSame([], $analysis->abstractions);
    }

    /**
     * The cutoff boundary: {1,16} is unrolled, {1,17} is abstracted; both
     * fail at n=19 on 10.49.
     */
    #[Test]
    public function test_bounded_repeat_cutoff_boundary(): void
    {
        $atCutoff = (new RedosAnalyzer())->analyze('/(a{1,16})+$/');
        $pastCutoff = (new RedosAnalyzer())->analyze('/(a{1,17})+$/');

        $this->assertSame(RedosComplexity::Exponential, $atCutoff->complexity);
        $this->assertSame([], $atCutoff->abstractions);
        $this->assertSame(RedosComplexity::Exponential, $pastCutoff->complexity);
        $this->assertCount(1, $pastCutoff->abstractions);
    }

    #[Test]
    public function test_bounded_repeat_above_the_cutoff_is_listed_as_an_abstraction(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a{1,20})+$/');

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertCount(1, $analysis->abstractions);
        $this->assertContainsOnlyString($analysis->abstractions);
    }

    #[Test]
    public function test_bounded_repeat_abstraction_keeps_a_linear_verdict(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/\w{2,64}/');

        $this->assertSame(RedosComplexity::Linear, $analysis->complexity);
        $this->assertNotSame([], $analysis->abstractions);
    }

    #[Test]
    public function test_bounded_repeat_cutoff_comes_from_the_options(): void
    {
        $analyzer = new RedosAnalyzer(options: new RedosOptions(boundedRepeatCutoff: 4));

        $analysis = $analyzer->analyze('/(a{1,5})+$/');

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertCount(1, $analysis->abstractions);
    }

    #[Test]
    public function test_linear_pattern_has_no_abstraction(): void
    {
        $this->assertSame([], (new RedosAnalyzer())->analyze('/^a+$/')->abstractions);
    }

    #[Test]
    public function test_theoretical_vulnerable_verdict_is_medium_confidence_until_replayed(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a+)+$/');

        $this->assertObjectHasProperty('replayed', $analysis);
        $this->assertNull($analysis->replayed);
        $this->assertSame(RedosConfidence::Medium, $analysis->confidenceLevel());
    }

    #[Test]
    public function test_options_defaults_are_the_documented_budget(): void
    {
        $options = new RedosOptions();

        $this->assertSame(2000, $options->maxStates);
        $this->assertSame(250_000, $options->maxSteps);
        $this->assertSame(16, $options->boundedRepeatCutoff);
    }

    #[Test]
    public function test_analysis_carries_the_versions_it_was_computed_with(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a+)+$/');

        $this->assertSame(RedosAnalyzer::ANALYSIS_VERSION, $analysis->analysisVersion);
        $this->assertNotSame('', $analysis->pcreVersion);
        $this->assertStringStartsWith($analysis->pcreVersion, \PCRE_VERSION);
    }
}

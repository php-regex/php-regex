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

namespace PHPRegex\Tests\Unit\ReDoS;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReDoSEnterpriseAnalyzerTest extends TestCase
{
    private RedosAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new RedosAnalyzer();
    }

    #[DataProvider('provideSafePatterns')]
    public function test_safe_patterns_are_safe(string $pattern): void
    {
        $analysis = $this->analyzer->analyze($pattern);

        $this->assertTrue($analysis->isSafe(), "Expected safe/low severity for pattern: {$pattern}");
    }

    #[DataProvider('provideMediumPatterns')]
    public function test_medium_patterns_exceed_medium_threshold(string $pattern): void
    {
        $analysis = $this->analyzer->analyze($pattern);

        $this->assertTrue($analysis->exceedsThreshold(RedosSeverity::Medium), "Expected at least MEDIUM for pattern: {$pattern}");
        $this->assertFalse($analysis->exceedsThreshold(RedosSeverity::High), "Expected below HIGH for pattern: {$pattern}");
    }

    #[DataProvider('provideHighPatterns')]
    public function test_high_patterns_exceed_high_threshold(string $pattern): void
    {
        $analysis = $this->analyzer->analyze($pattern);

        $this->assertTrue($analysis->exceedsThreshold(RedosSeverity::High), "Expected HIGH+ severity for pattern: {$pattern}");
    }

    public function test_empty_match_quantifier_is_reported(): void
    {
        // (a?)+ never fails on the engine; (a*)* before the end fails on a…a! at n=18.
        $analysis = $this->analyzer->analyze('/(a*)*$/');

        $this->assertTrue($analysis->exceedsThreshold(RedosSeverity::High));
        $this->assertTrue($this->containsRecommendation($analysis->recommendations, 'match empty'));
    }

    #[DataProvider('provideAdjacentQuantifierPatterns')]
    public function test_adjacent_quantifiers_are_reported(string $pattern): void
    {
        $analysis = $this->analyzer->analyze($pattern);

        $this->assertTrue($analysis->exceedsThreshold(RedosSeverity::Medium));
        $this->assertTrue($this->containsRecommendation($analysis->recommendations, 'Adjacent quantified tokens'));
    }

    #[DataProvider('provideDisjointAdjacentPatterns')]
    public function test_adjacent_quantifiers_disjoint_are_not_reported(string $pattern): void
    {
        $analysis = $this->analyzer->analyze($pattern);

        $this->assertFalse($this->containsRecommendation($analysis->recommendations, 'Adjacent quantified tokens'));
    }

    public static function provideSafePatterns(): \Iterator
    {
        yield 'exact literal' => ['/^hello$/'];
        yield 'date format' => ['/^\d{4}-\d{2}-\d{2}$/'];
        yield 'slug' => ['/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        yield 'hex color' => ['/^#[0-9a-f]{6}$/i'];
        yield 'hex string' => ['/^#[0-9a-f]+$/i'];
        yield 'iso code' => ['/^(?:[A-Z]{2}\d{2})$/'];
        yield 'ipv4' => ['/^(?:\d{1,3}\.){3}\d{1,3}$/'];
        yield 'atomic repetition' => ['/(?>a+)+/'];
        yield 'possessive quantifier' => ['/a++b/'];
        yield 'fixed alternation' => ['/^(?:foo|bar|baz)$/'];
        yield 'bounded list' => ['/^[^,]{1,10}(?:,[^,]{1,10}){0,3}$/'];
        // Pinned medium (unbounded quantifier) by the heuristics. Proven linear: no input up to
        // 64 bytes trips the backtrack limit and the time stays linear from 4,000 to 8,000
        // characters with JIT off (PCRE2 10.49).
        yield 'simple plus' => ['/a+/'];
        yield 'digits' => ['/\d+/'];
        yield 'char class plus' => ['/([a-z])+/'];
        yield 'dot star with suffix' => ['/.*ok/'];
        yield 'non-space' => ['/[^\s]+/'];
        yield 'word chars' => ['/\w+/'];
        yield 'url-ish' => ['/^https?:\/\/\S+$/'];
        yield 'disjoint alternation' => ['/(?:foo|bar)+/'];
        yield 'unicode class' => ['/\\p{L}+/u'];
        // Pinned high by the heuristics. Without an end constraint every continuation is
        // accepted after the first run, so nothing backtracks: a…a! matches at once on the engine.
        yield 'nested plus without an end constraint' => ['/(a+)+/'];
        yield 'nested word chars without an end constraint' => ['/(\\w+)+/'];
        yield 'overlapping alternation without an end constraint' => ['/(a|aa)+/'];
        yield 'overlap with star without an end constraint' => ['/(a|a)*/'];
        yield 'prefix overlap without an end constraint' => ['/(?:foo|foobar)+/'];
        yield 'dot overlap without an end constraint' => ['/(?:a|.)*/'];
        yield 'nested empty repeat without an end constraint' => ['/(a*)*/'];
        // Pinned high by the heuristics; PCRE never fails on a…a! up to 64 bytes, anchored with $
        // or not: the empty iteration is cut by the engine and \b consumes nothing.
        yield 'empty repeat plus' => ['/(a?)+/'];
        yield 'zero-width repeat' => ['/(?:\\b)+/'];
    }

    public static function provideMediumPatterns(): \Iterator
    {
        // Proven polynomial of degree 2 (quadratic per attempt): with JIT off a…a! takes
        // 5.6 s at 4,000 characters and 43 s at 8,000 over every start position (PCRE2 10.49).
        yield 'adjacent plus before the end' => ['/a+a+$/'];
        yield 'adjacent word runs before the end' => ['/(?:\\w+)(?:\\w+)$/'];
    }

    public static function provideHighPatterns(): \Iterator
    {
        // The shapes with an end constraint, so that the engine really backtracks: each makes
        // preg_match() fail with "Backtrack limit exhausted" (PCRE2 10.49, JIT on and off).
        // The unanchored forms are linear: see provideSafePatterns().
        yield 'nested plus' => ['/(a+)+$/']; // a…a! fails at n=19
        yield 'nested word chars' => ['/(\\w+)+$/']; // 0…0! fails at n=19
        yield 'overlapping alternation' => ['/(a|aa)+$/']; // aaa…aaa! fails at 10 pumps
        yield 'overlap with star' => ['/(a|a)*$/']; // a…a! fails at n=19
        yield 'backref loop' => ['/(?:([a-z]+)\\1)+/'];
        // (?:foo|foobar)+ has no ambiguity; foo…foo! fails at 28 pumps with foofoo.
        yield 'prefix overlap' => ['/(?:foo|foofoo)+$/'];
        yield 'dot overlap' => ['/(?:a|.)*$/']; // aa…aa\n! fails at 10 pumps
        yield 'nested empty repeat' => ['/(a*)*$/']; // a…a! fails at n=18
        yield 'variable backref' => ['/(?:([0-9]{2,4})\\1)+/'];
    }

    public static function provideAdjacentQuantifierPatterns(): \Iterator
    {
        // Unanchored, the second run takes the last character and the match ends: linear.
        // Before the end they are quadratic per attempt (see provideMediumPatterns()).
        yield 'direct adjacent' => ['/a+a+$/'];
        yield 'grouped adjacent' => ['/(?:\\w+)(?:\\w+)$/'];
    }

    public static function provideDisjointAdjacentPatterns(): \Iterator
    {
        yield 'digit then non-digit' => ['/\\d+\\D+/'];
        yield 'alpha then digits' => ['/([a-z])+\\d+/'];
    }

    /**
     * @param array<string> $recommendations
     */
    private function containsRecommendation(array $recommendations, string $needle): bool
    {
        foreach ($recommendations as $recommendation) {
            if (str_contains($recommendation, $needle)) {
                return true;
            }
        }

        return false;
    }
}

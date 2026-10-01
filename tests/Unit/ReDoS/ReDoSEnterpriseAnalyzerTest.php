<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\ReDoS;

use PhpRegex\Redos\RedosAnalyzer;
use PhpRegex\Redos\RedosSeverity;
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
        $analysis = $this->analyzer->analyze('/(a?)+/');

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
    }

    public static function provideMediumPatterns(): \Iterator
    {
        yield 'simple plus' => ['/a+/'];
        yield 'digits' => ['/\d+/'];
        yield 'char class plus' => ['/([a-z])+/'];
        yield 'dot star with suffix' => ['/.*ok/'];
        yield 'non-space' => ['/[^\s]+/'];
        yield 'word chars' => ['/\w+/'];
        yield 'url-ish' => ['/^https?:\/\/\S+$/'];
        yield 'disjoint alternation' => ['/(?:foo|bar)+/'];
        yield 'unicode class' => ['/\\p{L}+/u'];
    }

    public static function provideHighPatterns(): \Iterator
    {
        yield 'nested plus' => ['/(a+)+/'];
        yield 'nested word chars' => ['/(\\w+)+/'];
        yield 'overlapping alternation' => ['/(a|aa)+/'];
        yield 'overlap with star' => ['/(a|a)*/'];
        yield 'backref loop' => ['/(?:([a-z]+)\\1)+/'];
        yield 'prefix overlap' => ['/(?:foo|foobar)+/'];
        yield 'dot overlap' => ['/(?:a|.)*/'];
        yield 'empty repeat plus' => ['/(a?)+/'];
        yield 'nested empty repeat' => ['/(a*)*/'];
        yield 'variable backref' => ['/(?:([0-9]{2,4})\\1)+/'];
        yield 'zero-width repeat' => ['/(?:\\b)+/'];
    }

    public static function provideAdjacentQuantifierPatterns(): \Iterator
    {
        yield 'direct adjacent' => ['/a+a+/'];
        yield 'grouped adjacent' => ['/(?:\\w+)(?:\\w+)/'];
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

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

use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ReDoSEdgeCasesTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    public function test_safe_pattern_single_quantifier(): void
    {
        $analysis = $this->regex->redos('/a+b/');

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotSame(RedosSeverity::High, $analysis->severity);
    }

    public function test_safe_pattern_character_class_with_literal(): void
    {
        $analysis = $this->regex->redos('/[a-z]+test/');

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotSame(RedosSeverity::High, $analysis->severity);
    }

    public function test_safe_pattern_simple_alternation(): void
    {
        $analysis = $this->regex->redos('/(a|b)+c/');

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotSame(RedosSeverity::High, $analysis->severity);
    }

    public function test_safe_pattern_non_capturing_alternation(): void
    {
        $analysis = $this->regex->redos('/(?:foo|bar)*baz/');

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotSame(RedosSeverity::High, $analysis->severity);
    }

    public function test_safe_pattern_word_chars(): void
    {
        $analysis = $this->regex->redos('/\w+@\w+/');

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotSame(RedosSeverity::High, $analysis->severity);
    }

    public function test_safe_pattern_bounded_nested_quantifiers(): void
    {
        $analysis = $this->regex->redos('/(a{1,5})+/');

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
    }

    public function test_safe_pattern_anchored_quantifier(): void
    {
        $analysis = $this->regex->redos('/^[a-z]*$/');

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotSame(RedosSeverity::High, $analysis->severity);
    }

    public function test_dangerous_pattern_nested_plus_quantifiers(): void
    {
        $analysis = $this->regex->redos('/(a+)+b/');

        $this->assertContains($analysis->severity, [RedosSeverity::High, RedosSeverity::Critical],
            'Nested quantifiers (a+)+ should be flagged as dangerous');
    }

    public function test_dangerous_pattern_nested_star_quantifiers(): void
    {
        $analysis = $this->regex->redos('/(a*)*b/');

        $this->assertContains($analysis->severity, [RedosSeverity::High, RedosSeverity::Critical],
            'Nested quantifiers (a*)* should be flagged as dangerous');
    }

    public function test_dangerous_pattern_overlapping_alternation(): void
    {
        // Unanchored, (a|a)* accepts after the first run and never backtracks; before the end,
        // a…a! makes preg_match() fail at n=19 (PCRE2 10.49).
        $analysis = $this->regex->redos('/(a|a)*$/');

        $this->assertContains($analysis->severity, [RedosSeverity::High, RedosSeverity::Critical],
            'Overlapping alternation (a|a)*$ should be flagged as dangerous');
    }

    public function test_dangerous_pattern_overlapping_alternation_patterns(): void
    {
        // (?:a|ab)*c reads every input one way and never fails on the engine; with "b" as its
        // own branch, "ab" is read two ways: ab…ab!c fails at 19 pumps (PCRE2 10.49).
        $analysis = $this->regex->redos('/(?:a|ab|b)*c/');

        $this->assertContains($analysis->severity, [RedosSeverity::Medium, RedosSeverity::High, RedosSeverity::Critical],
            'Overlapping alternation (?:a|ab|b)* should be flagged');
    }

    public function test_dangerous_pattern_nested_non_capturing(): void
    {
        $analysis = $this->regex->redos('/(?:a+)+b/');

        $this->assertContains($analysis->severity, [RedosSeverity::High, RedosSeverity::Critical],
            'Nested quantifiers (?:a+)+ should be flagged as dangerous');
    }

    public function test_edge_case_with_anchors(): void
    {
        $withoutAnchors = $this->regex->redos('/(a+)+b/');
        $withAnchors = $this->regex->redos('/^(a+)+b$/');

        $this->assertContains($withoutAnchors->severity, [RedosSeverity::High, RedosSeverity::Critical]);
        $this->assertContains($withAnchors->severity, [RedosSeverity::High, RedosSeverity::Critical]);
    }

    public function test_edge_case_bounded_nested_quantifiers(): void
    {
        // A bounded inner repeat is still ambiguous: a…a!b makes preg_match() fail at n=22
        // (PCRE2 10.49), so the verdict is critical, not below it.
        $analysis = $this->regex->redos('/(a{1,3})+b/');

        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
    }

    public function test_edge_case_triple_nesting(): void
    {
        $analysis = $this->regex->redos('/(?:(?:a+)+)+b/');

        $this->assertContains($analysis->severity, [RedosSeverity::High, RedosSeverity::Critical],
            'Triple nested quantifiers should be flagged as dangerous');
    }

    public function test_dangerous_pattern_alternative_quantifiers_nested(): void
    {
        // Unanchored it accepts after the first run; before the end, a…a! fails at n=18 (PCRE2 10.49).
        $analysis = $this->regex->redos('/(a*|b*)+$/');

        $this->assertContains($analysis->severity, [RedosSeverity::High, RedosSeverity::Critical],
            'Alternation with quantifiers nested should be flagged');
    }

    public function test_dangerous_pattern_double_nested_quantifiers(): void
    {
        $analysis = $this->regex->redos('/(x+x+)+y/');

        $this->assertContains($analysis->severity, [RedosSeverity::High, RedosSeverity::Critical],
            'Double nested quantifiers should be flagged');
    }

    public function test_backreference_loop_inside_quantifier_is_critical(): void
    {
        $analysis = $this->regex->redos('/((a+)\\1)+/');

        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
    }

    public function test_unknown_severity_on_analysis_failure(): void
    {
        $analysis = $this->regex->redos('/[a-+/');

        $this->assertSame(RedosSeverity::Unknown, $analysis->severity);
        $this->assertFalse($analysis->isSafe());
        $this->assertNotNull($analysis->error);
    }

    public function test_threshold_allows_contextual_pass_fail(): void
    {
        // (a+)+ alone matches a…a! at once (linear); before the end it fails at n=19 (PCRE2 10.49).
        $analysis = $this->regex->redos('/(a+)+$/');

        $this->assertTrue($analysis->exceedsThreshold(RedosSeverity::High));
        $this->assertSame(
            RedosSeverity::Critical === $analysis->severity,
            $analysis->exceedsThreshold(RedosSeverity::Critical),
        );
    }
}

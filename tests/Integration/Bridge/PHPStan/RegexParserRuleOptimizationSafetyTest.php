<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Bridge\PHPStan;

use PhpRegex\PHPStan\RegexPatternRule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RegexPatternRule's optimization safety checks.
 */
final class RegexParserRuleOptimizationSafetyTest extends TestCase
{
    private RegexPatternRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RegexPatternRule(config: [
            'checks' => [
                'redos' => ['enabled' => false, 'threshold' => 'high'],
                'optimizations' => ['enabled' => true],
            ],
        ]);
    }

    public function test_rejects_effectively_empty_patterns(): void
    {
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/abc/', '##'));
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/abc/i', '//i'));
        $this->assertFalse($this->rule->isOptimizationFormatSafe('#test#', '##'));
    }

    public function test_rejects_missing_closing_delimiter(): void
    {
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/abc/', '/abc'));
    }

    public function test_rejects_broken_anchors(): void
    {
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/abc$/', '$/'));
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/^abc/', '/^/'));
    }

    public function test_rejects_patterns_removing_newlines(): void
    {
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/\r?\n/', '/\r?/'));
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/-- (.+)\n/', '/-- (.+) /'));
    }

    public function test_rejects_drastic_length_reduction(): void
    {
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/abcd/', '/a/'));
        $this->assertFalse($this->rule->isOptimizationFormatSafe('#longpattern#', '#x#'));
    }

    public function test_accepts_patterns_with_trailing_newline_escape(): void
    {
        $this->assertTrue($this->rule->isOptimizationFormatSafe('/A\\n/', '/A\\n/'));
    }

    public function test_accepts_patterns_with_leading_newline_escape(): void
    {
        $this->assertTrue($this->rule->isOptimizationFormatSafe('/\\nA/', '/\\nA/'));
    }

    public function test_rejects_optimizations_removing_leading_newline_escape(): void
    {
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/\\nAB/', '/AB/'));
    }

    public function test_ignores_newline_escape_outside_the_pattern(): void
    {
        $this->assertTrue($this->rule->isOptimizationFormatSafe('/ab/\\n', '/ab/'));
    }

    public function test_accepts_valid_optimizations(): void
    {
        $this->assertTrue($this->rule->isOptimizationFormatSafe('/[0-9]+/', '/\d+/'));
        $this->assertTrue($this->rule->isOptimizationFormatSafe('/abc/', '/abc/')); // Same
        $this->assertTrue($this->rule->isOptimizationFormatSafe('/a+/', '/a+/')); // Not shorter
    }

    public function test_accepts_short_patterns_if_original_is_short(): void
    {
        $this->assertFalse($this->rule->isOptimizationFormatSafe('/ab/', '/a/'));
    }

    /**
     * Test that optimization safety checks are format-based, not semantic.
     */
    public function test_optimization_safety_is_format_based(): void
    {
        // This should pass format checks even if semantically unsafe (length preserved, no newlines removed)
        $this->assertTrue($this->rule->isOptimizationFormatSafe('/(a)(a)/', '/(a){2}/'));
    }
}

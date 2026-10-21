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

namespace PHPRegex\Tests\Unit\Bridge\PHPStan;

use PHPRegex\PHPStan\OptimizationFormat;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPUnit\Framework\TestCase;

/**
 * The format checks a rewrite of the optimizer passes before the PHPStan
 * rule offers it: a rewrite that reads as no pattern, loses the newline
 * escape of the original or comes out too short is not shown.
 */
final class OptimizationFormatTest extends TestCase
{
    /**
     * The check is the rule's own: its public surface is the constructor
     * and what PHPStan calls.
     */
    public function test_the_rule_offers_no_public_hook_for_the_check(): void
    {
        $public = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(RegexPatternRule::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($public);

        $this->assertSame(['__construct', 'getNodeType', 'processNode'], $public);
    }

    public function test_rejects_effectively_empty_patterns(): void
    {
        $this->assertFalse(OptimizationFormat::isSafe('/abc/', '##'));
        $this->assertFalse(OptimizationFormat::isSafe('/abc/i', '//i'));
        $this->assertFalse(OptimizationFormat::isSafe('#test#', '##'));
    }

    public function test_rejects_missing_closing_delimiter(): void
    {
        $this->assertFalse(OptimizationFormat::isSafe('/abc/', '/abc'));
    }

    public function test_rejects_broken_anchors(): void
    {
        $this->assertFalse(OptimizationFormat::isSafe('/abc$/', '$/'));
        $this->assertFalse(OptimizationFormat::isSafe('/^abc/', '/^/'));
    }

    public function test_rejects_patterns_removing_newlines(): void
    {
        $this->assertFalse(OptimizationFormat::isSafe('/\r?\n/', '/\r?/'));
        $this->assertFalse(OptimizationFormat::isSafe('/-- (.+)\n/', '/-- (.+) /'));
    }

    public function test_rejects_drastic_length_reduction(): void
    {
        $this->assertFalse(OptimizationFormat::isSafe('/abcd/', '/a/'));
        $this->assertFalse(OptimizationFormat::isSafe('#longpattern#', '#x#'));
    }

    public function test_accepts_patterns_with_trailing_newline_escape(): void
    {
        $this->assertTrue(OptimizationFormat::isSafe('/A\\n/', '/A\\n/'));
    }

    public function test_accepts_patterns_with_leading_newline_escape(): void
    {
        $this->assertTrue(OptimizationFormat::isSafe('/\\nA/', '/\\nA/'));
    }

    public function test_rejects_optimizations_removing_leading_newline_escape(): void
    {
        $this->assertFalse(OptimizationFormat::isSafe('/\\nAB/', '/AB/'));
    }

    public function test_ignores_newline_escape_outside_the_pattern(): void
    {
        $this->assertTrue(OptimizationFormat::isSafe('/ab/\\n', '/ab/'));
    }

    public function test_accepts_valid_optimizations(): void
    {
        $this->assertTrue(OptimizationFormat::isSafe('/[0-9]+/', '/\d+/'));
        $this->assertTrue(OptimizationFormat::isSafe('/abc/', '/abc/')); // Same
        $this->assertTrue(OptimizationFormat::isSafe('/a+/', '/a+/')); // Not shorter
    }

    public function test_accepts_short_patterns_if_original_is_short(): void
    {
        $this->assertFalse(OptimizationFormat::isSafe('/ab/', '/a/'));
    }

    /**
     * Test that optimization safety checks are format-based, not semantic.
     */
    public function test_optimization_safety_is_format_based(): void
    {
        // This should pass format checks even if semantically unsafe (length preserved, no newlines removed)
        $this->assertTrue(OptimizationFormat::isSafe('/(a)(a)/', '/(a){2}/'));
    }
}

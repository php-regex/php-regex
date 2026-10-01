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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class OptimizerNodeVisitorCharClassMergingTest extends TestCase
{
    private Regex $regex;

    private Rewriter $optimizer;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->optimizer = new Rewriter();
    }

    public function test_basic_adjacent_char_class_merging(): void
    {
        // Test that [a-z]|[0-9] becomes [a-z0-9]
        // Digit optimization doesn't apply after merging because there are multiple parts
        $ast = $this->regex->parse('/[a-z]|[0-9]/');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[a-z0-9]/', $result);
    }

    public function test_multiple_adjacent_char_class_merging(): void
    {
        // Test that [a-z]|[A-Z]|[0-9] becomes [a-zA-Z0-9]
        $ast = $this->regex->parse('/[a-z]|[A-Z]|[0-9]/');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[a-zA-Z0-9]/', $result);
    }

    public function test_no_merging_with_negated_classes(): void
    {
        // Test that [a-z]|[^0-9] does not merge, even if [^0-9] is optimized to \D
        $ast = $this->regex->parse('/[a-z]|[^0-9]/');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[a-z]|\D/', $result);
    }

    public function test_no_char_type_merging_in_unicode_mode(): void
    {
        $ast = $this->regex->parse('/\d|[0-9]/u');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/\d|[0-9]/u', $result);
    }

    public function test_no_merging_with_non_adjacent_classes(): void
    {
        // Test that [a-z]foo|[0-9] only optimizes the [0-9] part to \d
        $ast = $this->regex->parse('/[a-z]foo|[0-9]/');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        // The [0-9] gets optimized to \d, but [a-z]fo{2} remains unchanged
        $this->assertSame('/[a-z]foo|\d/', $result);
    }

    public function test_single_char_classes_merge(): void
    {
        // Test that [a]|[b]|[c] becomes [abc]
        $ast = $this->regex->parse('/[a]|[b]|[c]/');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[abc]/', $result);
    }

    public function test_merge_preserves_order(): void
    {
        // Test that [c]|[a]|[b] becomes [cab] (preserves original order)
        $ast = $this->regex->parse('/[c]|[a]|[b]/');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[cab]/', $result);
    }

    public function test_merging_with_literals_and_ranges(): void
    {
        // Test that [a]|[0-9] becomes [a0-9]
        $ast = $this->regex->parse('/[a]|[0-9]/');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[a0-9]/', $result);
    }

    public function test_no_merging_with_quantifier(): void
    {
        // Test that [a-z]|[0-9]+ - no merging because [0-9]+ has a quantifier
        $ast = $this->regex->parse('/[a-z]|[0-9]+/');

        $optimized = $ast->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        // The digit optimization should apply to the standalone [0-9]+
        $this->assertSame('/[a-z]|\d+/', $result);
    }

    public function test_adjacent_char_class_merging_without_digit_optimization(): void
    {
        // Test with digit optimization disabled to see pure merging behavior
        $ast = $this->regex->parse('/[a-z]|[0-9]/');
        $optimizer = new Rewriter(optimizeDigits: false);

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        // With digit optimization disabled, we should see [a-z0-9]
        $this->assertSame('/[a-z0-9]/', $result);
    }

    public function test_pure_adjacent_char_class_merging(): void
    {
        // Test with disabled digit optimization to see pure merging behavior
        // Pattern: [a-z]|[0-9]|[A-Z] -> should become [a-z0-9A-Z]
        $ast = $this->regex->parse('/[a-z]|[0-9]|[A-Z]/');
        $optimizer = new Rewriter(optimizeDigits: false);

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[a-z0-9A-Z]/', $result);
    }

    public function test_semantics_preserved_after_merging(): void
    {
        // Test that merged regex has same matching behavior
        $originalPattern = '/[a-z]|[0-9]/';
        $ast = $this->regex->parse($originalPattern);
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        // Both patterns should match the same strings
        $testStrings = ['a', 'z', '0', '9', '5', 'm'];
        foreach ($testStrings as $test) {
            $this->assertMatchesRegularExpression($originalPattern, $test, "Original should match '$test'");
            $this->assertMatchesRegularExpression($result, $test, "Optimized should match '$test'");
        }

        // Both patterns should reject the same strings
        $invalidStrings = ['.', '@', 'A', 'Z'];
        foreach ($invalidStrings as $test) {
            $this->assertDoesNotMatchRegularExpression($originalPattern, $test, "Original should not match '$test'");
            $this->assertDoesNotMatchRegularExpression($result, $test, "Optimized should not match '$test'");
        }
    }
}

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

namespace PhpRegex\Tests\Integration;

use PhpRegex\Explain\MermaidRenderer;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for v1.0 upgrade features.
 *
 * Tests:
 * 1. ParserOptions with resource limits
 * 2. MermaidVisitor visualization
 */
final class FeaturesUpgradeTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    /**
     * Test MermaidVisitor generates valid Mermaid syntax.
     */
    public function test_mermaid_visitor_generates_valid_syntax(): void
    {
        $mermaidOutput = $this->visualize('/abc/');

        // Should start with "graph TD;"
        $this->assertStringStartsWith('graph TD;', $mermaidOutput);

        // Should contain node definitions
        $this->assertStringContainsString('node', $mermaidOutput);

        // Should contain connection arrows
        $this->assertStringContainsString('-->', $mermaidOutput);
    }

    /**
     * Test MermaidVisitor output for simple pattern.
     */
    public function test_mermaid_visitor_simple_pattern(): void
    {
        $mermaidOutput = $this->visualize('/test/');

        $this->assertStringStartsWith('graph TD;', $mermaidOutput);
        $this->assertStringContainsString('Regex:', $mermaidOutput);
        $this->assertStringContainsString('Literal:', $mermaidOutput);
    }

    /**
     * Test MermaidVisitor with complex pattern (group + quantifier).
     */
    public function test_mermaid_visitor_complex_pattern(): void
    {
        $mermaidOutput = $this->visualize('/(abc)+/');

        $this->assertStringStartsWith('graph TD;', $mermaidOutput);
        $this->assertStringContainsString('Group:', $mermaidOutput);
        $this->assertStringContainsString('Quantifier:', $mermaidOutput);
    }

    /**
     * Test MermaidRenderer integrates with parser.
     */
    public function test_regex_visualize_integration(): void
    {
        $visualization = $this->visualize('/[a-z]+/');

        $this->assertIsString($visualization);
        $this->assertStringStartsWith('graph TD;', $visualization);
        $this->assertStringContainsString('CharClass', $visualization);
    }

    /**
     * Test MermaidRenderer with multiple alternatives.
     */
    public function test_regex_visualize_alternation(): void
    {
        $visualization = $this->visualize('/(cat|dog|bird)/');

        $this->assertStringStartsWith('graph TD;', $visualization);
        $this->assertStringContainsString('Alternation', $visualization);
    }

    /**
     * Test MermaidVisitor handles all node types.
     */
    public function test_mermaid_visitor_comprehensive_node_types(): void
    {
        // Complex pattern with multiple node types
        $patterns = [
            '/^test$/',                     // Anchors
            '/[a-z]+/',                     // CharClass
            '/\d{2,5}/',                    // Quantifier with range
            '/(abc)/',                      // Group
            '/(a|b)/',                      // Alternation
            '/(?<=abc)def/',                // Lookbehind
            '/test(?!ing)/',                // Lookahead
            '/(?P<name>\w+)/',              // Named group
        ];

        foreach ($patterns as $pattern) {
            $visualization = $this->visualize($pattern);

            $this->assertStringStartsWith('graph TD;', $visualization);
            $this->assertStringContainsString('-->', $visualization);
            $this->assertStringContainsString('node', $visualization);
        }
    }

    private function visualize(string $pattern): string
    {
        return $this->regexService->parse($pattern)->accept(new MermaidRenderer());
    }
}

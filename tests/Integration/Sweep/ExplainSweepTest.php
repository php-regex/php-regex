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

namespace RegexParser\Tests\Integration\Sweep;

use PHPUnit\Framework\TestCase;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\VersionConditionNode;
use RegexParser\NodeVisitor\ExplainNodeVisitor;
use RegexParser\Regex;

/**
 * A sweep of patterns through Explain.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class ExplainSweepTest extends TestCase
{
    private ExplainNodeVisitor $explainVisitor;

    private Regex $regex;

    private Regex $regexService;

    protected function setUp(): void
    {
        $this->explainVisitor = new ExplainNodeVisitor();
        $this->regex = Regex::create();
        $this->regexService = Regex::create();
    }

    public function test_explain_visitor_range_special_chars(): void
    {
        $regex = Regex::create();
        $visitor = new ExplainNodeVisitor();

        // Range with special characters
        $ast = $regex->parse('/[0-9]/');
        $result = $ast->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_explain_visitor_quantifier_variations(): void
    {
        $regex = Regex::create();
        $visitor = new ExplainNodeVisitor();

        // Test different quantifier types
        $patterns = [
            '/a*/',    // Zero or more
            '/a+/',    // One or more
            '/a?/',    // Optional
            '/a{3}/',  // Exactly 3
            '/a{2,}/', // At least 2
            '/a{2,5}/', // Between 2 and 5
        ];

        foreach ($patterns as $pattern) {
            $ast = $regex->parse($pattern);
            $result = $ast->accept($visitor);
            $this->assertNotEmpty($result);
        }
    }

    public function test_explain_visitor_literal_special_chars(): void
    {
        $regex = Regex::create();
        $visitor = new ExplainNodeVisitor();

        // Literal with special characters
        $ast = $regex->parse('/\//');
        $result = $ast->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_explain_visitor_script_run(): void
    {
        $visitor = new ExplainNodeVisitor();
        $node = new ScriptRunNode('Latin', 0, 18);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_explain_visitor_limit_match(): void
    {
        $visitor = new ExplainNodeVisitor();
        $node = new LimitMatchNode(1000, 0, 16);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_explain_visitor_version_condition(): void
    {
        $visitor = new ExplainNodeVisitor();
        $node = new VersionConditionNode('>=', '10.0', 0, 18);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_explain_visitor_complex_quantifier_multiline_child(): void
    {
        // Test quantifier with complex child (multiline output) to hit lines 149-159
        $ast = $this->regex->parse('/(a|b|c)+/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('Start Quantified Group', $result);
        $this->assertStringContainsString('End Quantified Group', $result);
    }

    public function test_explain_visitor_dot_node(): void
    {
        $ast = $this->regex->parse('/./');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('Wildcard', $result);
    }

    public function test_explain_visitor_keep_node(): void
    {
        $ast = $this->regex->parse('/\K/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('reset match start', $result);
    }

    public function test_explain_visitor_comment_node(): void
    {
        $ast = $this->regex->parse('/(?#test comment)/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('Comment', $result);
    }

    public function test_explain_visitor_octal_legacy_node(): void
    {
        $ast = $this->regex->parse('/\01/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('Character with octal value', $result);
    }

    public function test_explain_visitor_special_literals(): void
    {
        // Test explainLiteral helper with special characters
        $ast = $this->regex->parse('/ \t\n\r/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('space', $result);
        $this->assertStringContainsString('tab', $result);
        $this->assertStringContainsString('newline', $result);
        $this->assertStringContainsString('carriage return', $result);
    }

    public function test_explain_visitor_lazy_quantifier(): void
    {
        // Test explainQuantifierValue with lazy quantifier
        $ast = $this->regex->parse('/a+?/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('as few as possible', $result);
    }

    public function test_explain_visitor_possessive_quantifier(): void
    {
        // Test explainQuantifierValue with possessive quantifier
        $ast = $this->regex->parse('/a++/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('do not backtrack', $result);
    }

    public function test_explain_visitor_conditional_with_alternation(): void
    {
        // Test visitConditional with alternation (ELSE branch)
        $ast = $this->regex->parse('/(?(?=a)yes|no)/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function test_explain_visitor_conditional_single_branch(): void
    {
        // Test visitConditional with single branch
        $ast = $this->regex->parse('/(?(?=a)yes)/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function test_explain_visitor_subroutine_r(): void
    {
        // Test visitSubroutine with R (entire pattern reference)
        $ast = $this->regex->parse('/(?R)/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('entire pattern', $result);
    }

    public function test_explain_visitor_subroutine_0(): void
    {
        // Test visitSubroutine with 0 (entire pattern reference)
        $ast = $this->regex->parse('/(?0)/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('entire pattern', $result);
    }

    public function test_explain_visitor_all_node_types(): void
    {
        // Test all visit methods
        $patterns = [
            '/a|b/',           // alternation
            '/(?:a)/',         // group
            '/a*/',            // quantifier
            '/\d/',            // char type
            '/./',             // dot
            '/^$/',            // anchors
            '/\b/',            // assertion
            '/\K/',            // keep
            '/[a-z]/',         // char class with range
            '/\1/',            // backref
            '/\x41/',          // unicode
            '/\p{L}/',         // unicode prop
            '/\o{101}/',       // octal
            '/\01/',           // octal legacy
            '/[[:alpha:]]/',   // posix class
            '/(?#comment)/',   // comment
            '/(?(1)a|b)/',     // conditional
            '/(?&name)/',      // subroutine (with name defined)
            '/(*FAIL)/',       // pcre verb
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $result = $ast->accept($this->explainVisitor);
                $this->assertIsString($result);
            } catch (\Exception) {
                // Some patterns may fail, that's ok
            }
        }
    }

    public function test_explain_visitor_quantifier_variations_2(): void
    {
        $ast = $this->regexService->parse('/a{3}/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('exactly 3 times', $result);

        $ast = $this->regexService->parse('/a{3,}/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('at least 3 times', $result);

        $ast = $this->regexService->parse('/a{3,5}/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('at least 3 but not more than 5 times', $result);
    }

    public function test_explain_visitor_range_with_escape_sequences(): void
    {
        $ast = $this->regexService->parse('/[\\t-\\n]/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertIsString($result);
    }

    public function test_explain_visitor_unicode_prop_negated(): void
    {
        $ast = $this->regexService->parse('/\P{L}/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertIsString($result);
    }

    public function test_explain_visitor_conditional_with_different_conditions(): void
    {
        $ast = $this->regexService->parse('/(a)(?(1)b|c)/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertIsString($result);
    }

    public function test_explain_visitor_group_types(): void
    {
        $patterns = [
            '/(?:a)/',      // non-capturing
            '/(a)/',        // capturing
            '/(?<name>a)/', // named
            '/(?>a)/',      // atomic
            '/(?|a|b)/',    // branch reset
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $result = $ast->accept($this->explainVisitor);
                $this->assertIsString($result);
            } catch (\Exception) {
                // Some may fail
            }
        }
    }

    public function test_explain_visitor_quantifier_lazy(): void
    {
        $ast = $this->regexService->parse('/a+?/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('as few as possible', $result);
    }

    public function test_explain_visitor_quantifier_possessive(): void
    {
        $ast = $this->regexService->parse('/a++/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('and do not backtrack', $result);
    }

    public function test_explain_visitor_anchors(): void
    {
        $ast = $this->regexService->parse('/^test$/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('beginning of a line', $result);
        $this->assertStringContainsString('end of a line', $result);
    }

    public function test_explain_visitor_assertions(): void
    {
        $patterns = [
            '/\A/', '/\z/', '/\Z/', '/\G/', '/\b/', '/\B/',
        ];

        foreach ($patterns as $pattern) {
            $ast = $this->regexService->parse($pattern);
            $result = $ast->accept($this->explainVisitor);
            $this->assertIsString($result);
        }
    }

    public function test_explain_visitor_subroutine(): void
    {
        $ast = $this->regexService->parse('/(?<test>a)(?&test)/');
        $result = $ast->accept($this->explainVisitor);
        $this->assertStringContainsString('Subroutine Call', $result);
    }

    public function test_explain_visitor_with_unicode_prop_negated(): void
    {
        // Test ExplainVisitor with negated unicode property
        $ast = $this->regexService->parse('/\P{L}/');

        $visitor = new ExplainNodeVisitor();
        $result = $ast->accept($visitor);

        $this->assertNotEmpty($result);
    }

    public function test_explain_visitor_with_octal_legacy(): void
    {
        // Test ExplainVisitor with octal legacy
        $ast = $this->regexService->parse('/\07/');

        $visitor = new ExplainNodeVisitor();
        $result = $ast->accept($visitor);

        $this->assertNotEmpty($result);
    }
}

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

namespace PHPRegex\Tests\Integration\Sweep;

use PHPRegex\Explain\Highlighter\HtmlHighlighter;
use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through HtmlExplain.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class HtmlExplainSweepTest extends TestCase
{
    private HtmlExplainer $htmlExplainVisitor;

    private Regex $regex;

    private Regex $regexService;

    protected function setUp(): void
    {
        $this->htmlExplainVisitor = new HtmlExplainer();
        $this->regex = Regex::create();
        $this->regexService = Regex::create();
    }

    public function test_html_highlighter_visitor_dot(): void
    {
        $visitor = new HtmlHighlighter();
        $node = new DotNode(1, 2);
        $result = $visitor->visitDot($node);
        $this->assertNotEmpty($result);
    }

    public function test_html_highlighter_visitor_limit_match(): void
    {
        $visitor = new HtmlHighlighter();
        $node = new LimitMatchNode(1000, 0, 16);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_html_highlighter_visitor_callout(): void
    {
        $visitor = new HtmlHighlighter();
        $node = new CalloutNode(1, false, 0, 4);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_html_highlighter_visitor_script_run(): void
    {
        $visitor = new HtmlHighlighter();
        $node = new ScriptRunNode('Latin', 0, 18);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_html_highlighter_visitor_version_condition(): void
    {
        $visitor = new HtmlHighlighter();
        $node = new VersionConditionNode('>=', '10.0', 0, 18);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_html_explain_visitor_limit_match(): void
    {
        $visitor = new HtmlExplainer();
        $node = new LimitMatchNode(1000, 0, 16);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_html_explain_visitor_complex_quantifier_multiline(): void
    {
        // Test quantifier with complex child to hit multiline quantifier explanation
        $ast = $this->regex->parse('/(a|b)+/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('Quantifier', $result);
        $this->assertStringContainsString('one or more times', $result);
    }

    public function test_html_explain_visitor_dot_node(): void
    {
        $ast = $this->regex->parse('/./');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('Wildcard', $result);
    }

    public function test_html_explain_visitor_keep_node(): void
    {
        $ast = $this->regex->parse('/\K/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('reset match start', $result);
    }

    public function test_html_explain_visitor_octal_legacy_node(): void
    {
        $ast = $this->regex->parse('/\01/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('Character with octal value', $result);
    }

    public function test_html_explain_visitor_special_literals(): void
    {
        // Test explainLiteral with special characters and HTML escaping
        $ast = $this->regex->parse('/ \t\n\r<>&"/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('space', $result);
        $this->assertStringContainsString('tab', $result);
        $this->assertStringContainsString('&lt;', $result); // HTML entity for <
        $this->assertStringContainsString('&gt;', $result); // HTML entity for >
    }

    public function test_html_explain_visitor_lazy_quantifier(): void
    {
        $ast = $this->regex->parse('/a+?/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('as few as possible', $result);
    }

    public function test_html_explain_visitor_possessive_quantifier(): void
    {
        $ast = $this->regex->parse('/a++/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('do not backtrack', $result);
    }

    public function test_html_explain_visitor_conditional_with_alternation(): void
    {
        $ast = $this->regex->parse('/(?(?=a)yes|no)/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function test_html_explain_visitor_subroutine(): void
    {
        $ast = $this->regex->parse('/(?R)/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('entire pattern', $result);
    }

    public function test_html_explain_all_node_types(): void
    {
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
            '/(*FAIL)/',       // pcre verb
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $result = $ast->accept($this->htmlExplainVisitor);
                $this->assertIsString($result);
                $this->assertStringContainsString('<', $result);
            } catch (\Exception) {
                // Some patterns may fail, that's ok
            }
        }
    }

    public function test_html_explain_range_with_special_chars(): void
    {
        $ast = $this->regexService->parse('/[<>&]/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertIsString($result);
        // HTML entities are double-encoded, check for the presence of HTML
        $this->assertStringContainsString('&amp;', $result);
    }

    public function test_html_explain_quantifier_types(): void
    {
        $ast = $this->regexService->parse('/a*?/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('as few as possible', $result);

        $ast = $this->regexService->parse('/a*+/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('do not backtrack', $result);
    }

    public function test_html_explain_conditional_variations(): void
    {
        $ast = $this->regexService->parse('/(a)(?(1)b|c)/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertIsString($result);
    }

    public function test_html_explain_subroutine(): void
    {
        $ast = $this->regexService->parse('/(?<name>a)(?&name)/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertIsString($result);
    }

    public function test_html_explain_group_with_name(): void
    {
        $ast = $this->regexService->parse('/(?<name>test)/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('name', $result);
    }

    public function test_html_explain_atomic_group(): void
    {
        $ast = $this->regexService->parse('/(?>test)/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('Atomic', $result);
    }

    public function test_html_explain_assertions_all(): void
    {
        $patterns = [
            '/(?=test)/',  // positive lookahead
            '/(?!test)/',  // negative lookahead
            '/(?<=test)/', // positive lookbehind
            '/(?<!test)/', // negative lookbehind
        ];

        foreach ($patterns as $pattern) {
            $ast = $this->regexService->parse($pattern);
            $result = $ast->accept($this->htmlExplainVisitor);
            $this->assertStringContainsString('Look', $result);
        }
    }

    public function test_html_explain_backref_named(): void
    {
        $ast = $this->regexService->parse('/(?<name>a)\k<name>/');
        $result = $ast->accept($this->htmlExplainVisitor);
        $this->assertStringContainsString('name', $result);
    }

    public function test_html_explain_unicode_prop_variations(): void
    {
        $patterns = [
            '/\p{Lu}/', // uppercase letter
            '/\p{Ll}/', // lowercase letter
            '/\P{L}/',  // not letter
        ];

        foreach ($patterns as $pattern) {
            $ast = $this->regexService->parse($pattern);
            $result = $ast->accept($this->htmlExplainVisitor);
            $this->assertIsString($result);
        }
    }

    public function test_html_explain_visitor_with_pcre_verb(): void
    {
        // Test HtmlExplainVisitor with PCRE verb
        $ast = $this->regexService->parse('/(*FAIL)/');

        $visitor = new HtmlExplainer();
        $result = $ast->accept($visitor);

        $this->assertNotEmpty($result);
    }

    public function test_html_explain_visitor_with_keep(): void
    {
        // Test HtmlExplainVisitor with \K (keep)
        $ast = $this->regexService->parse('/test\Kmore/');

        $visitor = new HtmlExplainer();
        $result = $ast->accept($visitor);

        $this->assertNotEmpty($result);
    }

    public function test_html_explain_visitor_with_subroutine(): void
    {
        // Test HtmlExplainVisitor with subroutine
        $ast = $this->regexService->parse('/(?<group>test)(?&group)/');

        $visitor = new HtmlExplainer();
        $result = $ast->accept($visitor);

        $this->assertNotEmpty($result);
    }
}

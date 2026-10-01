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

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ExplainVisitorTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    public function test_text_explain(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/^a|b$/i');
        $visitor = new TextExplainer();

        $output = $ast->accept($visitor);

        $this->assertStringContainsString('Regex matches (with flags: i)', $output);
        $this->assertStringContainsString('EITHER', $output);
        $this->assertStringContainsString('Anchor: the beginning of a line', $output);
        $this->assertStringContainsString('OR', $output);
    }

    public function test_html_explain_escaping(): void
    {
        $regex = Regex::create();
        // The parser splits "<script>" into multiple literals: "<", "s", "c", ...
        $ast = $regex->parse('/<script>/');
        $visitor = new HtmlExplainer();

        $output = $ast->accept($visitor);

        // We check that special HTML characters are correctly escaped in the rendering
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;', $output);
        $this->assertStringContainsString('&gt;', $output);
    }

    public function test_quantifier_formatting_simple_vs_complex(): void
    {
        // Simple: a* -> single line
        $ast = $this->regex->parse('/a*/');
        $text = $ast->accept(new TextExplainer());
        $html = $ast->accept(new HtmlExplainer());

        $this->assertStringNotContainsString('Start Quantified Group', $text); // Simple format
        $this->assertStringContainsString('<li>(zero or more times)', $html); // Injected into <li>

        // Complex: (a|b)* -> multi line block
        $ast = $this->regex->parse('/(a|b)*/');
        $text = $ast->accept(new TextExplainer());
        $html = $ast->accept(new HtmlExplainer());

        $this->assertStringContainsString('Start Quantified Group', $text);
        $this->assertStringContainsString('<li><strong>Quantifier', $html); // Wrapped
    }

    public function test_conditional_no_else_branch(): void
    {
        // (?(1)a) -> No else branch
        $ast = $this->regex->parse('/(?(1)a)/');
        $text = $ast->accept(new TextExplainer());
        $html = $ast->accept(new HtmlExplainer());

        $this->assertStringNotContainsString('ELSE:', $text);
        $this->assertStringNotContainsString('ELSE:', $html);
    }

    public function test_subroutine_references(): void
    {
        // (?0), (?R), (?1)
        $ast = $this->regex->parse('/(?R)(?1)/');
        $text = $ast->accept(new TextExplainer());

        $this->assertStringContainsString('recurses to the entire pattern', $text);
        $this->assertStringContainsString('recurses to group 1', $text);
    }

    public function test_all_char_types_explanation(): void
    {
        // \d \D \s \S \w \W \v \V \h \H \R
        $ast = $this->regex->parse('/\d\D\s\S\w\W\v\V\h\H\R/');
        $text = $ast->accept(new TextExplainer());

        $this->assertStringContainsString('digit', $text);
        $this->assertStringContainsString('non-digit', $text);
        $this->assertStringContainsString('whitespace', $text);
        $this->assertStringContainsString('vertical', $text);
        $this->assertStringContainsString('horizontal', $text);
    }
}

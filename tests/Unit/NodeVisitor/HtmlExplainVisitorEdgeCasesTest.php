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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Explain\HtmlExplainer;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class HtmlExplainVisitorEdgeCasesTest extends TestCase
{
    private Regex $regex;

    private HtmlExplainer $visitor;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->visitor = new HtmlExplainer();
    }

    public function test_visit_conditional_with_else_branch(): void
    {
        $regex = '/(?(?<=a)b|c)/'; // Conditional with alternation in YES branch (parser treats b|c as one branch)
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        $this->assertStringContainsString('<strong>Conditional: IF</strong>', $output);
        $this->assertStringContainsString('<strong>THEN:</strong>', $output);
        $this->assertStringContainsString('<span title="Literal: &#039;b&#039;">Literal: <strong>&#039;b&#039;</strong></span>', $output);
        $this->assertStringContainsString('<span title="Literal: &#039;c&#039;">Literal: <strong>&#039;c&#039;</strong></span>', $output);
        $this->assertStringContainsString('<strong>ELSE:</strong>', $output);
    }

    public function test_visit_group_types_and_named_group_escaping(): void
    {
        $regex = '/(?<tag>a)(?i:b)(?<!c)(?>d)/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        // Named group (tests escaping for name and type)
        $this->assertStringContainsString('Start Capturing Group (named: &#039;tag&#039;)', $output);
        // Inline flags (tests flag value)
        $this->assertStringContainsString('Start Group (with flags: &#039;i&#039;)', $output);
        // Negative Lookbehind
        $this->assertStringContainsString('Start Negative Lookbehind', $output);
        // Atomic Group
        $this->assertStringContainsString('Start Atomic Group', $output);
    }

    public function test_visit_complex_char_class_parts(): void
    {
        $regex = '/[\d\P{L}\o{77}[[:alnum:]]]/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        // Unicode Prop (\P{L} handled by logic) - note: inside char class, tags are stripped
        $this->assertStringContainsString('Unicode Property: \P{L}', $output);
        // Octal (\o{77} handled by logic) - note: inside char class, tags are stripped
        $this->assertStringContainsString('Octal: \o{77}', $output);
        // POSIX Class
        $this->assertStringContainsString('POSIX Class: [[:alnum:]]', $output);
    }

    public function test_visit_complex_literal_types(): void
    {
        $regex = "/\t\r\n /"; // Tab, CR, NL, Space
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        $this->assertStringContainsString('Literal: <strong>&#039;\\t&#039; (tab)</strong>', $output);
        $this->assertStringContainsString('Literal: <strong>&#039;\\r&#039; (carriage return)</strong>', $output);
        $this->assertStringContainsString('Literal: <strong>&#039;\\n&#039; (newline)</strong>', $output);
        $this->assertStringContainsString('Literal: <strong>&#039; &#039; (space)</strong>', $output);
    }

    public function test_visit_complex_quantified_group(): void
    {
        // A complex sequence that will trigger the multi-line quantifier formatting
        $regex = '/(a|b){2}/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        // This ensures the logic that wraps complex children in a new <ul>/<li> is hit
        $this->assertStringContainsString('<li><strong>Quantifier (exactly 2 times):</strong>', $output);
        $this->assertStringContainsString('<ul><li><strong>EITHER:</strong>', $output);
    }

    public function test_visit_simple_quantified_literal(): void
    {
        // A simple literal that should trigger the single-line quantifier formatting
        $regex = '/a{2}/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        // This ensures the logic that injects the quantifier into the child's <li> is hit
        $this->assertStringContainsString('<li>(exactly 2 times) <span title="Literal: &#039;a&#039;">Literal: <strong>&#039;a&#039;</strong></span>', $output);
    }

    public function test_visit_range_with_non_literal_bounds(): void
    {
        $range = new RangeNode(new CharTypeNode('d', 0, 0), new LiteralNode('z', 0, 0), 0, 0);
        $output = $range->accept($this->visitor);

        $this->assertStringContainsString('Range: from', $output);
        $this->assertStringContainsString('Character Type', $output);
    }

    public function test_visit_range_with_non_literal_end(): void
    {
        $range = new RangeNode(new LiteralNode('a', 0, 0), new CharTypeNode('w', 0, 0), 0, 0);
        $output = $range->accept($this->visitor);

        $this->assertStringContainsString('Range: from', $output);
        $this->assertStringContainsString('Character Type', $output);
    }

    public function test_question_quantifier_explains_once_or_not_at_all(): void
    {
        $regex = '/a?/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        $this->assertStringContainsString('once or not at all', $output);
    }
}

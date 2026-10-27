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
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function test_visit_conditional_with_else_branch(): void
    {
        $regex = '/(?(?<=a)b|c)/'; // Conditional with alternation in YES branch (parser treats b|c as one branch)
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        $this->assertStringContainsString('<strong>IF:</strong>', $output);
        $this->assertStringContainsString('<strong>THEN:</strong>', $output);
        $this->assertStringContainsString('<span title="&#039;b&#039;"><strong>&#039;b&#039;</strong></span>', $output);
        $this->assertStringContainsString('<span title="&#039;c&#039;"><strong>&#039;c&#039;</strong></span>', $output);
        $this->assertStringContainsString('<strong>ELSE:</strong>', $output);
    }

    #[Test]
    public function test_visit_group_types_and_named_group_escaping(): void
    {
        $regex = '/(?<tag>a)(?i:b)(?<!c)(?>d)/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        // Named group (tests escaping for name and type)
        $this->assertStringContainsString('<strong>Capturing group (named: &#039;tag&#039;):</strong>', $output);
        // Inline flags (tests flag value)
        $this->assertStringContainsString('<strong>Inline flags &#039;i&#039;:</strong>', $output);
        // Negative Lookbehind
        $this->assertStringContainsString('<strong>Negative lookbehind:</strong>', $output);
        // Atomic Group
        $this->assertStringContainsString('<strong>Atomic group (no backtracking):</strong>', $output);
    }

    #[Test]
    public function test_visit_complex_char_class_parts(): void
    {
        $regex = '/[\d\P{L}\o{77}[[:alnum:]]]/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        // A class names the meaning of each of its parts.
        $this->assertStringContainsString('Unicode Property: any character non-matching &quot;L&quot;', $output);
        $this->assertStringContainsString('Character with octal value 077', $output);
        // POSIX Class
        $this->assertStringContainsString('POSIX Class: [[:alnum:]]', $output);
    }

    #[Test]
    public function test_visit_complex_literal_types(): void
    {
        $regex = "/\t\r\n /"; // Tab, CR, NL, Space
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        $this->assertStringContainsString('<strong>&#039;\\t&#039; (tab)</strong>', $output);
        $this->assertStringContainsString('<strong>&#039;\\r&#039; (carriage return)</strong>', $output);
        $this->assertStringContainsString('<strong>&#039;\\n&#039; (newline)</strong>', $output);
        $this->assertStringContainsString('<strong>&#039; &#039; (space)</strong>', $output);
    }

    #[Test]
    public function test_visit_complex_quantified_group(): void
    {
        // A complex sequence that will trigger the multi-line quantifier formatting
        $regex = '/(a|b){2}/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        // This ensures the logic that wraps complex children in a new <ul>/<li> is hit
        $this->assertStringContainsString('<li><strong>Quantified group (exactly 2 times):</strong>', $output);
        $this->assertStringContainsString('<ul><li><strong>EITHER:</strong>', $output);
    }

    #[Test]
    public function test_visit_simple_quantified_literal(): void
    {
        // A simple literal that should trigger the single-line quantifier formatting
        $regex = '/a{2}/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        // The count follows the literal in its item, and in its tooltip.
        $this->assertStringContainsString('<li><span title="&#039;a&#039; (exactly 2 times)"><strong>&#039;a&#039;</strong></span> (exactly 2 times)</li>', $output);
    }

    #[Test]
    public function test_visit_range_with_non_literal_bounds(): void
    {
        $range = new RangeNode(new CharTypeNode('d', 0, 0), new LiteralNode('z', 0, 0), 0, 0);
        $output = $range->accept($this->visitor);

        $this->assertStringContainsString('Range: from', $output);
        $this->assertStringContainsString('Character Type', $output);
    }

    #[Test]
    public function test_visit_range_with_non_literal_end(): void
    {
        $range = new RangeNode(new LiteralNode('a', 0, 0), new CharTypeNode('w', 0, 0), 0, 0);
        $output = $range->accept($this->visitor);

        $this->assertStringContainsString('Range: from', $output);
        $this->assertStringContainsString('Character Type', $output);
    }

    #[Test]
    public function test_question_quantifier_explains_once_or_not_at_all(): void
    {
        $regex = '/a?/';
        $ast = $this->regex->parse($regex);
        $output = $ast->accept($this->visitor);

        $this->assertStringContainsString('once or not at all', $output);
    }

    /**
     * A byte of invalid UTF-8 the explanation does not spell itself, in a
     * callout argument, is shown as the replacement character: the rest of
     * the text stays. A literal byte, quoted or not, is spelled "\xHH".
     */
    #[Test]
    public function test_a_byte_of_invalid_utf8_keeps_the_text_around_it(): void
    {
        $callout = $this->regex->parse("/(?C\"a\xE9b\")x/")->accept($this->visitor);
        $quoted = $this->regex->parse("/\\Qa\xE9b\\E/")->accept($this->visitor);

        $this->assertStringContainsString("Callout: <strong>(?C&quot;a\u{FFFD}b&quot;)</strong>", $callout);
        $this->assertStringContainsString('<strong>&#039;a\\xE9b&#039;</strong>', $quoted);
    }

    /**
     * A class and a condition describe their parts as text, escaped once:
     * a quote shows as a quote, not as the entity that escapes it.
     */
    #[Test]
    public function test_a_class_and_a_condition_escape_their_parts_once(): void
    {
        $class = (string) $this->regex->parse('/[a-f<]/')->accept($this->visitor);
        $condition = (string) $this->regex->parse('/(?(?=a)b|c)/')->accept($this->visitor);

        $this->assertStringContainsString('Character Class: <strong>any character in [ Range: from &#039;a&#039; to &#039;f&#039;, &#039;&lt;&#039; ]</strong>', $class);
        $this->assertStringContainsString('title="Character Class: any character in [ Range: from &#039;a&#039; to &#039;f&#039;, &#039;&lt;&#039; ]"', $class);
        $this->assertStringContainsString("<li><strong>IF:</strong>\n<ul><li><span title=\"Positive lookahead\"><strong>Positive lookahead:</strong></span>\n<ul><li><span title=\"&#039;a&#039;\"><strong>&#039;a&#039;</strong></span></li></ul>", $condition);
        $this->assertStringNotContainsString('&amp;', $class.$condition);
    }
}

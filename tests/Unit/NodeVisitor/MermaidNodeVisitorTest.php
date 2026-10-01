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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Explain\MermaidRenderer;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class MermaidNodeVisitorTest extends TestCase
{
    public function test_simple_literal(): void
    {
        $ast = Regex::create()->parse('/abc/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('graph TD', $mermaid);
        $this->assertStringContainsString('Literal:', $mermaid);
    }

    public function test_alternation(): void
    {
        $ast = Regex::create()->parse('/(a|b)/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Alternation', $mermaid);
    }

    public function test_sequence(): void
    {
        $ast = Regex::create()->parse('/ab+c/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Sequence', $mermaid);
    }

    public function test_group(): void
    {
        $ast = Regex::create()->parse('/(abc)/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Group:', $mermaid);
    }

    public function test_named_group(): void
    {
        $ast = Regex::create()->parse('/(?P<name>abc)/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('name)', $mermaid);
    }

    public function test_quantifier(): void
    {
        $ast = Regex::create()->parse('/a+/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Quantifier:', $mermaid);
    }

    public function test_quantifier_range(): void
    {
        $ast = Regex::create()->parse('/a{2,5}/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Quantifier:', $mermaid);
        $this->assertStringContainsString('{2,5}', $mermaid);
    }

    public function test_dot(): void
    {
        $ast = Regex::create()->parse('/./');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Dot: any char', $mermaid);
    }

    public function test_anchor_start(): void
    {
        $ast = Regex::create()->parse('/^a/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Anchor: ^', $mermaid);
    }

    public function test_anchor_end(): void
    {
        $ast = Regex::create()->parse('/a$/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Anchor: $', $mermaid);
    }

    public function test_assertion_word_boundary(): void
    {
        $ast = Regex::create()->parse('/\b/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Assertion:', $mermaid);
    }

    public function test_keep(): void
    {
        $ast = Regex::create()->parse('/a\K/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Keep: \\K', $mermaid);
    }

    public function test_char_class(): void
    {
        $ast = Regex::create()->parse('/[abc]/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('CharClass', $mermaid);
    }

    public function test_negated_char_class(): void
    {
        $ast = Regex::create()->parse('/[^abc]/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('CharClass [NOT]', $mermaid);
    }

    public function test_range(): void
    {
        $ast = Regex::create()->parse('/[a-z]/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Range', $mermaid);
    }

    public function test_backreference(): void
    {
        $ast = Regex::create()->parse('/(a)\1/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Backref:', $mermaid);
    }

    public function test_unicode(): void
    {
        $ast = Regex::create()->parse('/\x{0041}/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Unicode:', $mermaid);
    }

    public function test_unicode_property(): void
    {
        $ast = Regex::create()->parse('/\p{L}/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('UnicodeProp:', $mermaid);
    }

    public function test_posix_class(): void
    {
        $ast = Regex::create()->parse('/[[:alpha:]]/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('PosixClass:', $mermaid);
    }

    public function test_comment(): void
    {
        $ast = Regex::create()->parse('/(?#comment)a/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Comment:', $mermaid);
    }

    public function test_conditional(): void
    {
        $ast = Regex::create()->parse('/(?(condition)yes|no)/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Conditional', $mermaid);
    }

    public function test_subroutine(): void
    {
        $ast = Regex::create()->parse('/(?1)/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Subroutine:', $mermaid);
    }

    public function test_pcre_verb(): void
    {
        $ast = Regex::create()->parse('/(*FAIL)a/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('PcreVerb:', $mermaid);
    }

    public function test_define(): void
    {
        $ast = Regex::create()->parse('/(?(DEFINE)...)/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('DEFINE Block', $mermaid);
    }

    public function test_limit_match(): void
    {
        $ast = Regex::create()->parse('/(*LIMIT_MATCH=100)a/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('LimitMatch:', $mermaid);
    }

    public function test_callout(): void
    {
        $ast = Regex::create()->parse('/(?C)a/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Callout:', $mermaid);
    }

    public function test_callout_with_identifier(): void
    {
        $ast = Regex::create()->parse('/(?C0)a/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Callout:', $mermaid);
    }

    public function test_regex_with_flags(): void
    {
        $ast = Regex::create()->parse('/abc/i');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('Regex:', $mermaid);
        $this->assertStringContainsString('i', $mermaid);
    }

    public function test_escaping_special_chars(): void
    {
        $ast = Regex::create()->parse('/<test>/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('&lt;', $mermaid);
    }

    public function test_empty_literal(): void
    {
        $ast = Regex::create()->parse('/(?:)/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringContainsString('(empty)', $mermaid);
    }

    public function test_graph_structure(): void
    {
        $ast = Regex::create()->parse('/(a|b)c/');
        $mermaid = $ast->accept(new MermaidRenderer());

        $this->assertStringStartsWith('graph TD;', $mermaid);
        $this->assertMatchesRegularExpression('/node\d+/', $mermaid);
        $this->assertMatchesRegularExpression('/-->/', $mermaid);
    }
}

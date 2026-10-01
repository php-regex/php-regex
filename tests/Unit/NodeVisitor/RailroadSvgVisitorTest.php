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

use PhpRegex\Explain\RailroadSvgRenderer;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class RailroadSvgVisitorTest extends TestCase
{
    public function test_svg_renders_basic_diagram(): void
    {
        $ast = Regex::create()->parse('/^a+$/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertStringContainsString('class="node literal"', $svg);
        $this->assertStringContainsString('class="node anchor"', $svg);
        $this->assertStringContainsString('class="quantifier-label"', $svg);
    }

    public function test_svg_renders_alternation(): void
    {
        $ast = Regex::create()->parse('/a|b|c/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('class="path"', $svg);
    }

    public function test_svg_renders_group_capturing(): void
    {
        $ast = Regex::create()->parse('/(abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('class="group-box"', $svg);
        $this->assertStringContainsString('Group #1', $svg);
    }

    public function test_svg_renders_group_named(): void
    {
        $ast = Regex::create()->parse('/(?<name>abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group #1 (name)', $svg);
    }

    public function test_svg_renders_group_non_capturing(): void
    {
        $ast = Regex::create()->parse('/(?:abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (non-capturing)', $svg);
    }

    public function test_svg_renders_lookahead(): void
    {
        $ast = Regex::create()->parse('/(?=abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (positive lookahead)', $svg);
    }

    public function test_svg_renders_negative_lookahead(): void
    {
        $ast = Regex::create()->parse('/(?!abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (negative lookahead)', $svg);
    }

    public function test_svg_renders_lookbehind(): void
    {
        $ast = Regex::create()->parse('/(?<=abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (positive lookbehind)', $svg);
    }

    public function test_svg_renders_negative_lookbehind(): void
    {
        $ast = Regex::create()->parse('/(?<!abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (negative lookbehind)', $svg);
    }

    public function test_svg_renders_optional_quantifier(): void
    {
        $ast = Regex::create()->parse('/a?/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('path bypass', $svg);
        $this->assertStringContainsString('0 or 1 time', $svg);
    }

    public function test_svg_renders_star_quantifier(): void
    {
        $ast = Regex::create()->parse('/a*/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('path loop', $svg);
        $this->assertStringContainsString('0 or more times', $svg);
    }

    public function test_svg_renders_plus_quantifier(): void
    {
        $ast = Regex::create()->parse('/a+/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('path loop', $svg);
        $this->assertStringContainsString('1 or more times', $svg);
    }

    public function test_svg_renders_range_quantifier(): void
    {
        $ast = Regex::create()->parse('/a{2,5}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('2 to 5 times', $svg);
    }

    public function test_svg_renders_exact_quantifier(): void
    {
        $ast = Regex::create()->parse('/a{3}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('3 times', $svg);
    }

    public function test_svg_renders_at_least_quantifier(): void
    {
        $ast = Regex::create()->parse('/a{2,}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('2 or more times', $svg);
    }

    public function test_svg_renders_at_most_quantifier(): void
    {
        // "{,3}" repeats from PCRE2 10.43, which PHP bundles from 8.4.
        $ast = Regex::create(['php_version' => '8.4'])->parse('/a{,3}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('0 to 3 times', $svg);
    }

    public function test_svg_renders_character_class(): void
    {
        $ast = Regex::create()->parse('/[abc]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharClass', $svg);
        $this->assertStringContainsString('class="node class-positive"', $svg);
    }

    public function test_svg_renders_negated_character_class(): void
    {
        $ast = Regex::create()->parse('/[^abc]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharClass (negated)', $svg);
        $this->assertStringContainsString('class="node class-negated"', $svg);
    }

    public function test_svg_renders_range_in_class(): void
    {
        $ast = Regex::create()->parse('/[a-z]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Range', $svg);
    }

    public function test_svg_renders_dot(): void
    {
        $ast = Regex::create()->parse('/./');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Dot (.)', $svg);
        $this->assertStringContainsString('class="node anychar"', $svg);
    }

    public function test_svg_renders_anchor_start(): void
    {
        $ast = Regex::create()->parse('/^/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Anchor (^)', $svg);
        $this->assertStringContainsString('class="node anchor"', $svg);
    }

    public function test_svg_renders_anchor_end(): void
    {
        $ast = Regex::create()->parse('/$/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Anchor ($)', $svg);
    }

    public function test_svg_renders_anchor_word_boundary(): void
    {
        $ast = Regex::create()->parse('/\b/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Assertion (\\b)', $svg);
    }

    public function test_svg_renders_char_type_digit(): void
    {
        $ast = Regex::create()->parse('/\d/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharType (\\d)', $svg);
    }

    public function test_svg_renders_char_type_word(): void
    {
        $ast = Regex::create()->parse('/\w/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharType (\\w)', $svg);
    }

    public function test_svg_renders_char_type_space(): void
    {
        $ast = Regex::create()->parse('/\s/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharType (\\s)', $svg);
    }

    public function test_svg_renders_unicode_escape(): void
    {
        $ast = Regex::create()->parse('/\x41/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharLiteral (\\x41)', $svg);
    }

    public function test_svg_renders_backref(): void
    {
        $ast = Regex::create()->parse('/(a)\1/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Backref (\\1)', $svg);
    }

    public function test_svg_renders_backref_named(): void
    {
        $ast = Regex::create()->parse('/(?<x>a)\k<x>/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Backref (\\k&lt;x&gt;)', $svg);
    }

    public function test_svg_renders_subroutine(): void
    {
        $ast = Regex::create()->parse('/(?1)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Subroutine (1)', $svg);
    }

    public function test_svg_renders_unicode_property(): void
    {
        $ast = Regex::create()->parse('/\p{L}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('UnicodeProperty (\\p{L})', $svg);
    }

    public function test_svg_renders_posix_class(): void
    {
        $ast = Regex::create()->parse('/[[:alpha:]]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PosixClass ([:alpha:])', $svg);
    }

    public function test_svg_renders_comment(): void
    {
        $ast = Regex::create()->parse('/(?#comment)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Comment', $svg);
    }

    public function test_svg_renders_callout_no_id(): void
    {
        $ast = Regex::create()->parse('/(?C)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Callout (?C)', $svg);
    }

    public function test_svg_renders_callout_numeric_id(): void
    {
        $ast = Regex::create()->parse('/(?C123)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Callout (?C123)', $svg);
    }

    public function test_svg_renders_callout_string_id(): void
    {
        $ast = Regex::create()->parse('/(?C"test")/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Callout (?C="test")', $svg);
    }

    public function test_svg_renders_assertion(): void
    {
        $ast = Regex::create()->parse('/\A/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Assertion (\\A)', $svg);
    }

    public function test_svg_renders_keep(): void
    {
        $ast = Regex::create()->parse('/\K/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Keep (\\K)', $svg);
    }

    public function test_svg_renders_pcre_verb_fail(): void
    {
        $ast = Regex::create()->parse('/(*FAIL)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PCREVerb (*FAIL)', $svg);
    }

    public function test_svg_renders_pcre_verb_accept(): void
    {
        $ast = Regex::create()->parse('/(*ACCEPT)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PCREVerb (*ACCEPT)', $svg);
    }

    public function test_svg_renders_version_condition(): void
    {
        $ast = Regex::create()->parse('/(*IF:7.0.0)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PCREVerb (*IF:7.0.0)', $svg);
    }

    public function test_svg_renders_define(): void
    {
        $ast = Regex::create()->parse('/(*DEFINE)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PCREVerb (*DEFINE)', $svg);
    }

    public function test_svg_renders_control_char(): void
    {
        $ast = Regex::create()->parse('/\cA/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('ControlChar (\\cA)', $svg);
    }

    public function test_svg_renders_script_run(): void
    {
        $ast = Regex::create()->parse('/\h/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharType (\\h)', $svg);
    }

    public function test_svg_renders_flags_in_svg(): void
    {
        $ast = Regex::create()->parse('/a/i');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('flags: i', $svg);
    }

    public function test_svg_renders_empty_alternation_branch(): void
    {
        $ast = Regex::create()->parse('/a||c/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_svg_renders_conditional(): void
    {
        $ast = Regex::create()->parse('/(?(1)a|b)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Conditional', $svg);
    }

    public function test_svg_reads_ampersands_as_class_members(): void
    {
        // PHP has no class intersection: "[[:alpha:]&&[a-z]" is one class
        // holding "&" and "[", and preg_match() finds "&]".
        $ast = Regex::create()->parse('/[[:alpha:]&&[a-z]]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringNotContainsString('ClassOperation', $svg);
        $this->assertStringNotContainsString('(intersection)', $svg);
    }

    public function test_svg_renders_sequence_with_mixed_nodes(): void
    {
        $ast = Regex::create()->parse('/a\d+/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('class="node literal"', $svg);
        $this->assertStringContainsString('class="node control"', $svg);
    }

    public function test_svg_renders_empty_literal(): void
    {
        $ast = Regex::create()->parse('//');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString("Literal ('(empty)')", $svg);
    }

    public function test_svg_renders_flags_with_spaces(): void
    {
        $ast = Regex::create()->parse('/a/imsx');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('flags: imsx', $svg);
    }

    public function test_svg_renders_complex_pattern(): void
    {
        $ast = Regex::create()->parse('/^(?<foo>\w+)@(?<bar>\w+)\.(?<baz>\w+)$/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('Group #1 (foo)', $svg);
        $this->assertStringContainsString('Group #2 (bar)', $svg);
        $this->assertStringContainsString('Group #3 (baz)', $svg);
        $this->assertStringContainsString('class="node anchor"', $svg);
    }

    public function test_svg_renders_quantifier_with_named_group(): void
    {
        $ast = Regex::create()->parse('/(?<word>\w+)+/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('path loop', $svg);
        $this->assertStringContainsString('Group #1 (word)', $svg);
    }

    public function test_svg_renders_backref_in_sequence(): void
    {
        $ast = Regex::create()->parse('/(a)(b)\1\2/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Backref (\\1)', $svg);
        $this->assertStringContainsString('Backref (\\2)', $svg);
    }

    public function test_svg_renders_nested_groups(): void
    {
        $ast = Regex::create()->parse('/((a)(b))/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('class="group-box"', $svg);
    }

    public function test_svg_renders_inline_flags_group(): void
    {
        $ast = Regex::create()->parse('/(?i:a)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (inline flags)', $svg);
        $this->assertStringContainsString('flags: i', $svg);
    }

    public function test_svg_renders_atomic_group(): void
    {
        $ast = Regex::create()->parse('/(?>a)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (atomic)', $svg);
    }

    public function test_svg_renders_branch_reset_group(): void
    {
        $ast = Regex::create()->parse('/(?|(a)|(b)|(c))/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (branch reset)', $svg);
    }
}

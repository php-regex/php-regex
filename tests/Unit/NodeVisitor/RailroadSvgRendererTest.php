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

use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RailroadSvgRendererTest extends TestCase
{
    #[Test]
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

    #[Test]
    public function test_svg_renders_alternation(): void
    {
        $ast = Regex::create()->parse('/a|b|c/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('class="path"', $svg);
    }

    #[Test]
    public function test_svg_renders_group_capturing(): void
    {
        $ast = Regex::create()->parse('/(abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('class="group-box"', $svg);
        $this->assertStringContainsString('Group #1', $svg);
    }

    #[Test]
    public function test_svg_renders_group_named(): void
    {
        $ast = Regex::create()->parse('/(?<name>abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group #1 (name)', $svg);
    }

    #[Test]
    public function test_svg_renders_group_non_capturing(): void
    {
        $ast = Regex::create()->parse('/(?:abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (non-capturing)', $svg);
    }

    #[Test]
    public function test_svg_renders_lookahead(): void
    {
        $ast = Regex::create()->parse('/(?=abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (positive lookahead)', $svg);
    }

    #[Test]
    public function test_svg_renders_negative_lookahead(): void
    {
        $ast = Regex::create()->parse('/(?!abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (negative lookahead)', $svg);
    }

    #[Test]
    public function test_svg_renders_lookbehind(): void
    {
        $ast = Regex::create()->parse('/(?<=abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (positive lookbehind)', $svg);
    }

    #[Test]
    public function test_svg_renders_negative_lookbehind(): void
    {
        $ast = Regex::create()->parse('/(?<!abc)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (negative lookbehind)', $svg);
    }

    #[Test]
    public function test_svg_renders_optional_quantifier(): void
    {
        $ast = Regex::create()->parse('/a?/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('path bypass', $svg);
        $this->assertStringContainsString('0 or 1 time', $svg);
    }

    #[Test]
    public function test_svg_renders_star_quantifier(): void
    {
        $ast = Regex::create()->parse('/a*/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('path loop', $svg);
        $this->assertStringContainsString('0 or more times', $svg);
    }

    #[Test]
    public function test_svg_renders_plus_quantifier(): void
    {
        $ast = Regex::create()->parse('/a+/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('path loop', $svg);
        $this->assertStringContainsString('1 or more times', $svg);
    }

    #[Test]
    public function test_svg_renders_range_quantifier(): void
    {
        $ast = Regex::create()->parse('/a{2,5}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('2 to 5 times', $svg);
    }

    #[Test]
    public function test_svg_renders_exact_quantifier(): void
    {
        $ast = Regex::create()->parse('/a{3}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('3 times', $svg);
    }

    #[Test]
    public function test_svg_renders_at_least_quantifier(): void
    {
        $ast = Regex::create()->parse('/a{2,}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('2 or more times', $svg);
    }

    #[Test]
    public function test_svg_renders_at_most_quantifier(): void
    {
        // "{,3}" repeats from PCRE2 10.43, which PHP bundles from 8.4.
        $ast = Regex::create(['php_version' => '8.4'])->parse('/a{,3}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('0 to 3 times', $svg);
    }

    #[Test]
    public function test_svg_renders_character_class(): void
    {
        $ast = Regex::create()->parse('/[abc]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharClass', $svg);
        $this->assertStringContainsString('class="node class-positive"', $svg);
    }

    #[Test]
    public function test_svg_renders_negated_character_class(): void
    {
        $ast = Regex::create()->parse('/[^abc]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharClass (negated)', $svg);
        $this->assertStringContainsString('class="node class-negated"', $svg);
    }

    #[Test]
    public function test_svg_renders_range_in_class(): void
    {
        $ast = Regex::create()->parse('/[a-z]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Range', $svg);
    }

    #[Test]
    public function test_svg_renders_dot(): void
    {
        $ast = Regex::create()->parse('/./');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Dot (.)', $svg);
        $this->assertStringContainsString('class="node anychar"', $svg);
    }

    #[Test]
    public function test_svg_renders_anchor_start(): void
    {
        $ast = Regex::create()->parse('/^/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Anchor (^)', $svg);
        $this->assertStringContainsString('class="node anchor"', $svg);
    }

    #[Test]
    public function test_svg_renders_anchor_end(): void
    {
        $ast = Regex::create()->parse('/$/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Anchor ($)', $svg);
    }

    #[Test]
    public function test_svg_renders_anchor_word_boundary(): void
    {
        $ast = Regex::create()->parse('/\b/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Assertion (\\b)', $svg);
    }

    #[Test]
    public function test_svg_renders_char_type_digit(): void
    {
        $ast = Regex::create()->parse('/\d/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharType (\\d)', $svg);
    }

    #[Test]
    public function test_svg_renders_char_type_word(): void
    {
        $ast = Regex::create()->parse('/\w/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharType (\\w)', $svg);
    }

    #[Test]
    public function test_svg_renders_char_type_space(): void
    {
        $ast = Regex::create()->parse('/\s/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharType (\\s)', $svg);
    }

    #[Test]
    public function test_svg_renders_unicode_escape(): void
    {
        $ast = Regex::create()->parse('/\x41/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        // A box holds the escape as written, not the node's class.
        $this->assertStringContainsString('>\\x41</text>', $svg);
        $this->assertStringNotContainsString('CharLiteral', $svg);
    }

    #[Test]
    public function test_svg_renders_backref(): void
    {
        $ast = Regex::create()->parse('/(a)\1/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Backref (\\1)', $svg);
    }

    #[Test]
    public function test_svg_renders_backref_named(): void
    {
        $ast = Regex::create()->parse('/(?<x>a)\k<x>/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Backref (\\k&lt;x&gt;)', $svg);
    }

    #[Test]
    public function test_svg_renders_subroutine(): void
    {
        $ast = Regex::create()->parse('/(?1)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Subroutine (1)', $svg);
    }

    #[Test]
    public function test_svg_renders_unicode_property(): void
    {
        $ast = Regex::create()->parse('/\p{L}/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('UnicodeProperty (\\p{L})', $svg);
    }

    #[Test]
    public function test_svg_renders_posix_class(): void
    {
        $ast = Regex::create()->parse('/[[:alpha:]]/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PosixClass ([:alpha:])', $svg);
    }

    #[Test]
    public function test_svg_renders_comment(): void
    {
        $ast = Regex::create()->parse('/(?#comment)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Comment', $svg);
    }

    #[Test]
    public function test_svg_renders_callout_no_id(): void
    {
        $ast = Regex::create()->parse('/(?C)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Callout (?C)', $svg);
    }

    #[Test]
    public function test_svg_renders_callout_numeric_id(): void
    {
        $ast = Regex::create()->parse('/(?C123)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Callout (?C123)', $svg);
    }

    #[Test]
    public function test_svg_renders_callout_string_id(): void
    {
        $ast = Regex::create()->parse('/(?C"test")/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Callout (?C=&quot;test&quot;)', $svg);
    }

    #[Test]
    public function test_svg_renders_assertion(): void
    {
        $ast = Regex::create()->parse('/\A/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Assertion (\\A)', $svg);
    }

    #[Test]
    public function test_svg_renders_keep(): void
    {
        $ast = Regex::create()->parse('/\K/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Keep (\\K)', $svg);
    }

    #[Test]
    public function test_svg_renders_pcre_verb_fail(): void
    {
        $ast = Regex::create()->parse('/(*FAIL)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PCREVerb (*FAIL)', $svg);
    }

    #[Test]
    public function test_svg_renders_pcre_verb_accept(): void
    {
        $ast = Regex::create()->parse('/(*ACCEPT)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PCREVerb (*ACCEPT)', $svg);
    }

    #[Test]
    public function test_svg_renders_version_condition(): void
    {
        $ast = Regex::create()->parse('/(*IF:7.0.0)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PCREVerb (*IF:7.0.0)', $svg);
    }

    #[Test]
    public function test_svg_renders_define(): void
    {
        $ast = Regex::create()->parse('/(*DEFINE)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('PCREVerb (*DEFINE)', $svg);
    }

    #[Test]
    public function test_svg_renders_control_char(): void
    {
        $ast = Regex::create()->parse('/\cA/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        // A control character matches one character: its box shows it as
        // written, as a literal's does, not the name of its node.
        $this->assertStringContainsString('>\\cA</text>', $svg);
        $this->assertStringNotContainsString('ControlChar', $svg);
    }

    #[Test]
    public function test_svg_renders_script_run(): void
    {
        $ast = Regex::create()->parse('/\h/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('CharType (\\h)', $svg);
    }

    #[Test]
    public function test_svg_renders_flags_in_svg(): void
    {
        $ast = Regex::create()->parse('/a/i');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('flags: i', $svg);
    }

    #[Test]
    public function test_svg_renders_empty_alternation_branch(): void
    {
        $ast = Regex::create()->parse('/a||c/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('<svg', $svg);
    }

    #[Test]
    public function test_svg_renders_conditional(): void
    {
        $ast = Regex::create()->parse('/(?(1)a|b)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Conditional', $svg);
    }

    #[Test]
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

    #[Test]
    public function test_svg_renders_sequence_with_mixed_nodes(): void
    {
        $ast = Regex::create()->parse('/a\d+/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('class="node literal"', $svg);
        $this->assertStringContainsString('class="node control"', $svg);
    }

    #[Test]
    public function test_svg_renders_empty_literal(): void
    {
        $ast = Regex::create()->parse('//');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        // A box holds the text it matches, none here, not the node's class.
        $this->assertStringContainsString('>(empty)</text>', $svg);
        $this->assertStringNotContainsString('Literal', $svg);
    }

    #[Test]
    public function test_svg_renders_flags_with_spaces(): void
    {
        $ast = Regex::create()->parse('/a/imsx');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('flags: imsx', $svg);
    }

    #[Test]
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

    #[Test]
    public function test_svg_renders_quantifier_with_named_group(): void
    {
        $ast = Regex::create()->parse('/(?<word>\w+)+/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('path loop', $svg);
        $this->assertStringContainsString('Group #1 (word)', $svg);
    }

    #[Test]
    public function test_svg_renders_backref_in_sequence(): void
    {
        $ast = Regex::create()->parse('/(a)(b)\1\2/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Backref (\\1)', $svg);
        $this->assertStringContainsString('Backref (\\2)', $svg);
    }

    #[Test]
    public function test_svg_renders_nested_groups(): void
    {
        $ast = Regex::create()->parse('/((a)(b))/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('class="group-box"', $svg);
    }

    #[Test]
    public function test_svg_renders_inline_flags_group(): void
    {
        $ast = Regex::create()->parse('/(?i:a)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (inline flags)', $svg);
        $this->assertStringContainsString('flags: i', $svg);
    }

    #[Test]
    public function test_svg_renders_atomic_group(): void
    {
        $ast = Regex::create()->parse('/(?>a)/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (atomic)', $svg);
    }

    #[Test]
    public function test_svg_renders_branch_reset_group(): void
    {
        $ast = Regex::create()->parse('/(?|(a)|(b)|(c))/');
        /** @var string $svg */
        $svg = $ast->accept(new RailroadSvgRenderer());

        $this->assertStringContainsString('Group (branch reset)', $svg);
    }

    /**
     * A word longer than a line of a label, the name of a reference, is
     * cut into lines of 28 bytes at most, nothing of it lost or moved.
     */
    #[Test]
    public function test_svg_cuts_a_word_longer_than_a_label_line(): void
    {
        $name = 'averyveryverylongnameofthirtychars';
        $pattern = '/(?<'.$name.'>a)\\k<'.$name.'>/';
        $this->assertSame(1, preg_match($pattern, 'aa'), 'Oracle: PCRE reads the long name.');

        /** @var string $svg */
        $svg = Regex::create()->parse($pattern)->accept(new RailroadSvgRenderer());
        preg_match_all('#<text[^>]*>(.*?)</text>#s', $svg, $texts);
        $lines = array_map(static fn (string $text): string => html_entity_decode($text, \ENT_QUOTES | \ENT_XML1), $texts[1]);
        $start = array_search('Backref', $lines, true);
        $this->assertIsInt($start);

        $this->assertSame(['Backref', '(\\k<averyveryverylongnameoft', 'hirtychars>)'], \array_slice($lines, $start, 3));
        foreach (\array_slice($lines, $start, 3) as $line) {
            $this->assertLessThanOrEqual(28, \strlen($line), $line);
        }
    }
}

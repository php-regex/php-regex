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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\DelimitedPattern;
use PhpRegex\Parser\ErrorCode;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Internal\InlineFlags;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    #[Test]
    public function test_parse_returns_regex_node_with_flags(): void
    {
        $ast = $this->parse('/foo/imsU');

        $this->assertSame('imsU', $ast->flags);
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
    }

    #[Test]
    public function test_parse_literal(): void
    {
        $ast = $this->parse('/foo/');
        $pattern = $ast->pattern;

        // "foo" is a SEQUENCE of 3 literals
        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(3, $pattern->children);
    }

    #[Test]
    public function test_parse_char_class(): void
    {
        $ast = $this->parse('/[a-z\d-]/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(CharClassNode::class, $pattern);
        $this->assertFalse($pattern->isNegated);
        $this->assertInstanceOf(AlternationNode::class, $pattern->expression);
        $this->assertCount(3, $pattern->expression->alternatives);

        // 1. RangeNode
        $this->assertInstanceOf(RangeNode::class, $pattern->expression->alternatives[0]);
        $this->assertInstanceOf(LiteralNode::class, $pattern->expression->alternatives[0]->start);
        $this->assertSame('a', $pattern->expression->alternatives[0]->start->value);
        $this->assertInstanceOf(LiteralNode::class, $pattern->expression->alternatives[0]->end);
        $this->assertSame('z', $pattern->expression->alternatives[0]->end->value);

        // 2. CharTypeNode
        $this->assertInstanceOf(CharTypeNode::class, $pattern->expression->alternatives[1]);
        $this->assertSame('d', $pattern->expression->alternatives[1]->value);

        // 3. LiteralNode
        $this->assertInstanceOf(LiteralNode::class, $pattern->expression->alternatives[2]);
        $this->assertSame('-', $pattern->expression->alternatives[2]->value);
    }

    #[Test]
    public function test_parse_nested_char_class_union(): void
    {
        $ast = $this->parse('/[a-d[m-p]]/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(2, $pattern->children);

        $this->assertInstanceOf(CharClassNode::class, $pattern->children[0]);
        $this->assertInstanceOf(LiteralNode::class, $pattern->children[1]);
        $this->assertSame(']', $pattern->children[1]->value);

        $class = $pattern->children[0];
        $this->assertInstanceOf(AlternationNode::class, $class->expression);
        $this->assertCount(3, $class->expression->alternatives);

        $this->assertInstanceOf(RangeNode::class, $class->expression->alternatives[0]);
        $this->assertInstanceOf(LiteralNode::class, $class->expression->alternatives[1]);
        $this->assertSame('[', $class->expression->alternatives[1]->value);
        $this->assertInstanceOf(RangeNode::class, $class->expression->alternatives[2]);
    }

    #[Test]
    public function test_parse_reads_ampersands_and_a_bracket_as_class_members(): void
    {
        // PHP has no class intersection: "[a-z&&[def]" is one class and the
        // last "]" a literal, so preg_match() finds "&]" and not "d".
        $ast = $this->parse('/[a-z&&[def]]/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(2, $pattern->children);
        $this->assertInstanceOf(CharClassNode::class, $pattern->children[0]);
        $this->assertInstanceOf(LiteralNode::class, $pattern->children[1]);
        $this->assertSame(']', $pattern->children[1]->value);

        $class = $pattern->children[0];
        $this->assertInstanceOf(AlternationNode::class, $class->expression);
        $this->assertCount(7, $class->expression->alternatives);
        $this->assertInstanceOf(RangeNode::class, $class->expression->alternatives[0]);
        $members = array_map(
            static fn (NodeInterface $node): string => $node instanceof LiteralNode ? $node->value : '',
            \array_slice($class->expression->alternatives, 1),
        );
        $this->assertSame(['&', '&', '[', 'd', 'e', 'f'], $members);
    }

    #[Test]
    public function test_parse_char_class_literal_bracket_at_start(): void
    {
        $ast = $this->parse('/[[]/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(CharClassNode::class, $pattern);
        $this->assertInstanceOf(LiteralNode::class, $pattern->expression);
        $this->assertSame('[', $pattern->expression->value);
    }

    #[Test]
    public function test_parse_char_class_literal_bracket_inside(): void
    {
        $ast = $this->parse('/[.[]/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(CharClassNode::class, $pattern);
        $this->assertInstanceOf(AlternationNode::class, $pattern->expression);

        $values = array_map(
            static fn (NodeInterface $node): string => $node instanceof LiteralNode ? $node->value : '',
            $pattern->expression->alternatives,
        );

        $this->assertSame(['.', '['], $values);
    }

    #[Test]
    public function test_parse_negated_char_class(): void
    {
        $ast = $this->parse('/[^a-z]/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(CharClassNode::class, $pattern);
        $this->assertTrue($pattern->isNegated);
        $this->assertInstanceOf(RangeNode::class, $pattern->expression);
    }

    #[Test]
    public function test_parse_group_with_quantifier(): void
    {
        $ast = $this->parse('/(bar)?/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(QuantifierNode::class, $pattern);
        $this->assertSame('?', $pattern->quantifier);

        // The quantified node is a group
        $this->assertInstanceOf(GroupNode::class, $pattern->node);
        $groupNode = $pattern->node;

        // The group's child is a sequence "bar"
        $this->assertInstanceOf(SequenceNode::class, $groupNode->child);
        $this->assertCount(3, $groupNode->child->children);

        $sequenceChild = $groupNode->child->children[0];
        $this->assertInstanceOf(LiteralNode::class, $sequenceChild);
        $this->assertSame('b', $sequenceChild->value);
    }

    #[Test]
    public function test_parse_alternation(): void
    {
        $ast = $this->parse('/foo|bar/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(AlternationNode::class, $pattern);
        $this->assertCount(2, $pattern->alternatives);

        // Alt 1 is a sequence "foo"
        $this->assertInstanceOf(SequenceNode::class, $pattern->alternatives[0]);
        $this->assertCount(3, $pattern->alternatives[0]->children);

        $alt1Child = $pattern->alternatives[0]->children[0];
        $this->assertInstanceOf(LiteralNode::class, $alt1Child);
        $this->assertSame('f', $alt1Child->value);

        // Alt 2 is a sequence "bar"
        $this->assertInstanceOf(SequenceNode::class, $pattern->alternatives[1]);
        $this->assertCount(3, $pattern->alternatives[1]->children);

        $alt2Child = $pattern->alternatives[1]->children[0];
        $this->assertInstanceOf(LiteralNode::class, $alt2Child);
        $this->assertSame('b', $alt2Child->value);
    }

    #[Test]
    public function test_parse_operator_precedence(): void
    {
        $ast = $this->parse('/ab*c/');
        $pattern = $ast->pattern;

        // The AST must be a Sequence
        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(3, $pattern->children);

        // Child 1: Literal 'a'
        $childA = $pattern->children[0];
        $this->assertInstanceOf(LiteralNode::class, $childA);
        $this->assertSame('a', $childA->value);

        // Child 2: Quantifier '*'
        $this->assertInstanceOf(QuantifierNode::class, $pattern->children[1]);
        $this->assertSame('*', $pattern->children[1]->quantifier);

        // ... which quantifies a Literal 'b'
        $quantifiedNode = $pattern->children[1]->node;
        $this->assertInstanceOf(LiteralNode::class, $quantifiedNode);
        $this->assertSame('b', $quantifiedNode->value);

        // Child 3: Literal 'c'
        $childC = $pattern->children[2];
        $this->assertInstanceOf(LiteralNode::class, $childC);
        $this->assertSame('c', $childC->value);
    }

    #[Test]
    public function test_parse_char_types_and_dot(): void
    {
        $ast = $this->parse('/.\d\S/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(3, $pattern->children);

        $this->assertInstanceOf(DotNode::class, $pattern->children[0]);
        $this->assertInstanceOf(CharTypeNode::class, $pattern->children[1]);
        $this->assertSame('d', $pattern->children[1]->value);
        $this->assertInstanceOf(CharTypeNode::class, $pattern->children[2]);
        $this->assertSame('S', $pattern->children[2]->value);
    }

    #[Test]
    public function test_parse_pcre2_char_types(): void
    {
        $ast = $this->parse('/\X\C/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(2, $pattern->children);

        $this->assertInstanceOf(CharTypeNode::class, $pattern->children[0]);
        $this->assertSame('X', $pattern->children[0]->value);
        $this->assertInstanceOf(CharTypeNode::class, $pattern->children[1]);
        $this->assertSame('C', $pattern->children[1]->value);
    }

    #[Test]
    public function test_parse_unicode_four_digit_escape(): void
    {
        $ast = $this->parse('/\u0041/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(CharLiteralNode::class, $pattern);
        $this->assertSame(CharLiteralType::Unicode, $pattern->type);
        $this->assertSame('\u0041', $pattern->originalRepresentation);
    }

    #[Test]
    public function test_parse_anchors(): void
    {
        $ast = $this->parse('/^foo$/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(5, $pattern->children); // ^, f, o, o, $

        $this->assertInstanceOf(AnchorNode::class, $pattern->children[0]);
        $this->assertSame('^', $pattern->children[0]->value);

        $this->assertInstanceOf(LiteralNode::class, $pattern->children[1]); // "f"
        $this->assertInstanceOf(LiteralNode::class, $pattern->children[2]); // "o"
        $this->assertInstanceOf(LiteralNode::class, $pattern->children[3]); // "o"

        $this->assertInstanceOf(AnchorNode::class, $pattern->children[4]);
        $this->assertSame('$', $pattern->children[4]->value);
    }

    #[Test]
    public function test_parse_assertions(): void
    {
        $ast = $this->parse('/\Afoo\b/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(5, $pattern->children); // \A, f, o, o, \b

        $this->assertInstanceOf(AssertionNode::class, $pattern->children[0]);
        $this->assertSame('A', $pattern->children[0]->value);

        $this->assertInstanceOf(AssertionNode::class, $pattern->children[4]);
        $this->assertSame('b', $pattern->children[4]->value);
    }

    #[Test]
    public function test_parse_word_boundary_before_brace_text(): void
    {
        // PCRE2 has no "\b{g}": it reads a word boundary, then the text "{g}"
        // (preg_match('/^a\b{g}$/', 'a{g}') === 1).
        $ast = $this->parse('/\b{g}foo\B{g}/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(11, $pattern->children); // \b, {, g, }, f, o, o, \B, {, g, }

        $this->assertInstanceOf(AssertionNode::class, $pattern->children[0]);
        $this->assertSame('b', $pattern->children[0]->value);

        $this->assertInstanceOf(AssertionNode::class, $pattern->children[7]);
        $this->assertSame('B', $pattern->children[7]->value);
    }

    #[Test]
    public function test_brace_text_after_a_word_boundary_can_be_repeated(): void
    {
        // The "+" repeats the "}" (preg_match('/^\B{g}+$/', '{g}}}') === 1).
        $result = Regex::create()->validate('/^\B{g}+$/');

        $this->assertTrue($result->isValid, (string) $result->error);
    }

    #[Test]
    public function test_parse_unicode_prop(): void
    {
        $ast = $this->parse('/\p{L}/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(UnicodePropNode::class, $pattern);
    }

    #[Test]
    public function test_parse_unicode_named(): void
    {
        $ast = $this->parse('/\N{LATIN CAPITAL LETTER A}/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(CharLiteralNode::class, $pattern);
        $this->assertSame(CharLiteralType::UnicodeNamed, $pattern->type);
        $this->assertSame('\N{LATIN CAPITAL LETTER A}', $pattern->originalRepresentation);
        // A character name is resolved with intl; without it, it stays unknown.
        $this->assertSame(\extension_loaded('intl') ? 65 : -1, $pattern->codePoint);
    }

    #[Test]
    public function test_parse_comment(): void
    {
        $ast = $this->parse('/(?#test)/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(CommentNode::class, $pattern);
        $this->assertSame('test', $pattern->comment);
    }

    #[Test]
    public function test_parse_conditional(): void
    {
        $ast = $this->parse('/(?(1)a|b)/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(ConditionalNode::class, $pattern);
        $this->assertInstanceOf(BackrefNode::class, $pattern->condition);
        $this->assertSame('1', $pattern->condition->ref);

        // The "yes" and "no" branches are split properly
        $this->assertInstanceOf(LiteralNode::class, $pattern->yes);
        $this->assertSame('a', $pattern->yes->value);
        $this->assertInstanceOf(LiteralNode::class, $pattern->no);
        $this->assertSame('b', $pattern->no->value);
    }

    #[Test]
    public function test_throws_on_unmatched_group(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected )');
        $this->parse('/(foo/');
    }

    #[Test]
    public function test_throws_on_missing_closing_delimiter(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('No closing delimiter "/" found. You opened with "/"; expected closing "/". Tip: escape "/" inside the pattern (\\/) or use a different delimiter, e.g. #foo#.');
        $this->parse('/foo');
    }

    #[Test]
    public function test_parse_escaped_chars(): void
    {
        $ast = $this->parse('/a\*b/');
        $pattern = $ast->pattern;

        // Sequence of 3 : 'a', '*', 'b'
        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertCount(3, $pattern->children);

        $childStar = $pattern->children[1];
        $this->assertInstanceOf(LiteralNode::class, $childStar);
        $this->assertSame('*', $childStar->value);
    }

    #[Test]
    public function test_parse_inline_flags(): void
    {
        $ast = $this->parse('/(?i:foo)/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(GroupNode::class, $pattern);
        $this->assertSame(GroupType::InlineFlags, $pattern->type);
        $this->assertSame('i', $pattern->flags);
    }

    #[Test]
    public function test_parse_inline_flags_unset_all(): void
    {
        $ast = $this->parse('/(?^i:foo)/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(GroupNode::class, $pattern);
        $this->assertSame(GroupType::InlineFlags, $pattern->type);
        $this->assertSame('^i', $pattern->flags);
    }

    #[Test]
    public function test_parse_inline_flags_turning_a_flag_on_and_off(): void
    {
        // PCRE reads the letters in order, so the "-i" wins: every release
        // compiles it, and preg_match('/(?i-i:foo)/', 'FOO') === 0.
        $ast = $this->parse('/(?i-i:foo)/');
        $pattern = $ast->pattern;

        $this->assertInstanceOf(GroupNode::class, $pattern);
        $this->assertSame('i-i', $pattern->flags);
        $this->assertTrue(Regex::create()->validate('/(?i-i:foo)/')->isValid);
        $this->assertFalse(InlineFlags::read('i-i')?->inForce('i', true));
    }

    #[Test]
    public function test_validate_rejects_unsupported_inline_flags(): void
    {
        $result = $this->regex->validate('/(?A:a)/');

        $this->assertFalse($result->isValid());
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Invalid group modifier syntax', (string) $result->error);
    }

    #[Test]
    public function test_validate_inline_flags_reset_is_valid(): void
    {
        $result = $this->regex->validate('/(?^i:a)/');

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function test_validate_inline_no_auto_capture_flag_is_valid(): void
    {
        $result = $this->regex->validate('/(?n:(a)(b))/');

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function test_inline_modifier_r_respects_target_php_version(): void
    {
        $regex83 = Regex::create(['php_version' => '8.3']);
        $result83 = $regex83->validate('/(?r:foo)/');

        $this->assertFalse($result83->isValid());
        $this->assertStringContainsString('Invalid group modifier syntax', (string) $result83->error);

        $regex84 = Regex::create(['php_version' => '8.4']);
        $result84 = $regex84->validate('/(?r:foo)/');

        $this->assertTrue($result84->isValid());
    }

    #[Test]
    public function test_validate_duplicate_named_groups_without_j(): void
    {
        $result = $this->regex->validate('/(?<a>.) (?<a>.)/');

        $this->assertFalse($result->isValid());
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Duplicate group name "a"', (string) $result->error);
    }

    #[Test]
    public function test_validate_duplicate_named_groups_with_j(): void
    {
        $result = $this->regex->validate('/(?J)(?<a>.) (?<a>.)/');

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function test_validate_unknown_named_group_suggestions(): void
    {
        $result = $this->regex->validate('/(?<name>.) \k<nam>/');

        $this->assertFalse($result->isValid());
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Did you mean: name?', (string) $result->error);
    }

    #[Test]
    public function test_validate_lookbehind_unbounded(): void
    {
        $result = $this->regex->validate('/(?<=a*)b/');

        $this->assertFalse($result->isValid());
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Lookbehind is unbounded', (string) $result->error);
    }

    #[Test]
    public function test_parse_named_group_with_single_quote(): void
    {
        // preg_match() on PCRE2 10.48: "unrecognized character after (?P at offset 4".
        $this->expectException(ParserException::class);

        $this->parse("/(?P'name'a)/");
    }

    #[Test]
    public function test_parse_named_group_with_double_quote(): void
    {
        // preg_match() on PCRE2 10.48: "unrecognized character after (?P at offset 4".
        $this->expectException(ParserException::class);

        $this->parse('/(?P"name"a)/');
    }

    #[Test]
    public function test_parse_g_references_as_backref(): void
    {
        $ast = $this->parse('/a\g{1}b\g{-1}c/');
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $this->assertInstanceOf(BackrefNode::class, $ast->pattern->children[1]);
        $this->assertInstanceOf(BackrefNode::class, $ast->pattern->children[3]);
        $this->assertSame('\g{1}', $ast->pattern->children[1]->ref);
        $this->assertSame('\g{-1}', $ast->pattern->children[3]->ref);
    }

    #[Test]
    public function test_parse_g_references_as_subroutine(): void
    {
        $ast = $this->parse('/(a)\g<name>/');
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern->children[1]);
        $this->assertSame('name', $ast->pattern->children[1]->reference);
        $this->assertSame('g', $ast->pattern->children[1]->syntax);
    }

    #[Test]
    public function test_parse_conditional_with_group_ref(): void
    {
        // (?(1)a|b)
        $ast = $this->parse('/(?(1)a|b)/');
        $this->assertInstanceOf(ConditionalNode::class, $ast->pattern);
        $this->assertInstanceOf(BackrefNode::class, $ast->pattern->condition);
        $this->assertSame('1', $ast->pattern->condition->ref);
        $this->assertInstanceOf(LiteralNode::class, $ast->pattern->yes);
        $this->assertSame('a', $ast->pattern->yes->value);
        $this->assertInstanceOf(LiteralNode::class, $ast->pattern->no);
        $this->assertSame('b', $ast->pattern->no->value);
    }

    #[Test]
    public function test_parse_conditional_with_multiple_else_alternatives(): void
    {
        $ast = $this->parse('/(?(1)a|b|c)/');

        $this->assertInstanceOf(ConditionalNode::class, $ast->pattern);
        $this->assertInstanceOf(LiteralNode::class, $ast->pattern->yes);
        $this->assertSame('a', $ast->pattern->yes->value);

        $this->assertInstanceOf(AlternationNode::class, $ast->pattern->no);
        $this->assertCount(2, $ast->pattern->no->alternatives);
        $this->assertInstanceOf(LiteralNode::class, $ast->pattern->no->alternatives[0]);
        $this->assertInstanceOf(LiteralNode::class, $ast->pattern->no->alternatives[1]);
        $this->assertSame('b', $ast->pattern->no->alternatives[0]->value);
        $this->assertSame('c', $ast->pattern->no->alternatives[1]->value);
    }

    #[Test]
    public function test_parse_conditional_without_else_defaults_to_empty_literal(): void
    {
        $ast = $this->parse('/(?(1)a)/');

        $this->assertInstanceOf(ConditionalNode::class, $ast->pattern);
        $this->assertInstanceOf(LiteralNode::class, $ast->pattern->yes);
        $this->assertSame('a', $ast->pattern->yes->value);

        $this->assertInstanceOf(LiteralNode::class, $ast->pattern->no);
        $this->assertSame('', $ast->pattern->no->value);
    }

    #[Test]
    public function test_parse_conditional_with_named_group_ref(): void
    {
        // (?(<name>)a|b)
        $ast = $this->parse('/(?<name>x)(?(<name>)a|b)/');
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $conditional = $ast->pattern->children[1];
        $this->assertInstanceOf(ConditionalNode::class, $conditional);
        $this->assertInstanceOf(BackrefNode::class, $conditional->condition);
        $this->assertSame('name', $conditional->condition->ref);
    }

    #[Test]
    public function test_parse_conditional_lookaround_tracks_branch_and_offsets(): void
    {
        $pattern = '(?(?=a)b|c)';
        $ast = $this->parse('/'.$pattern.'/');

        $this->assertInstanceOf(ConditionalNode::class, $ast->pattern);
        /** @var \PhpRegex\Parser\Node\ConditionalNode $conditional */
        $conditional = $ast->pattern;
        $condition = $conditional->condition;
        $this->assertInstanceOf(GroupNode::class, $condition);
        $this->assertSame('(?=', substr($pattern, $condition->getStartPosition(), 3));
        $this->assertSame('(?=a)', substr($pattern, $condition->getStartPosition(), $condition->getEndPosition() - $condition->getStartPosition()));

        $this->assertInstanceOf(LiteralNode::class, $conditional->yes);
        $this->assertSame('b', $conditional->yes->value);
        $this->assertInstanceOf(LiteralNode::class, $conditional->no);
        $this->assertSame('c', $conditional->no->value);
    }

    #[Test]
    public function test_parse_conditional_with_recursion_condition_variants(): void
    {
        $ast = $this->parse('/(?(R)a|b)/');
        $this->assertInstanceOf(ConditionalNode::class, $ast->pattern);
        /** @var \PhpRegex\Parser\Node\ConditionalNode $cond */
        $cond = $ast->pattern;
        $this->assertInstanceOf(SubroutineNode::class, $cond->condition);
        $this->assertSame('R', $cond->condition->reference);

        // "(?(R" takes a number or "&name": PCRE reads the name "R", and
        // refuses the sign where the ")" belongs.
        $refusal = null;

        try {
            $this->parse('/(?(R-1)a|b)/');
        } catch (ParserException $refusal) {
        }
        $this->assertInstanceOf(ParserException::class, $refusal);
        $this->assertSame(ErrorCode::GroupNameUnterminated, $refusal->getErrorCode());
        $this->assertSame(4, $refusal->getPosition());
    }

    #[Test]
    public function test_parse_conditional_with_bare_name_condition(): void
    {
        $ast = $this->parse('/(?<name>x)(?(name)a|b)/');
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $conditional = $ast->pattern->children[1];
        $this->assertInstanceOf(ConditionalNode::class, $conditional);
        $this->assertInstanceOf(BackrefNode::class, $conditional->condition);
        $this->assertSame('name', $conditional->condition->ref);
    }

    #[Test]
    public function test_parse_python_backreference_is_rejected(): void
    {
        $ast = $this->parse('/(?P<name>a)(?P=name)/');
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $this->assertInstanceOf(GroupNode::class, $ast->pattern->children[0]);
        $this->assertSame('name', $ast->pattern->children[0]->name);

        $backref = $ast->pattern->children[1];
        $this->assertInstanceOf(BackrefNode::class, $backref);
        $this->assertSame('\k<name>', $backref->ref);
    }

    #[Test]
    public function test_python_named_group_syntax(): void
    {
        // (?P<name>...)
        $ast = $this->parse('/(?P<foo>a)/');
        $this->assertInstanceOf(GroupNode::class, $ast->pattern);
        $this->assertSame('foo', $ast->pattern->name);

        // (?P'name'...) and (?P"name"...): preg_match() on PCRE2 10.48 gives
        // "unrecognized character after (?P at offset 4" for both.
        foreach (["/(?P'bar'a)/", '/(?P"baz"a)/'] as $pattern) {
            try {
                $this->parse($pattern);
                $this->fail(\sprintf('%s is refused by PHP but was parsed.', $pattern));
            } catch (ParserException $e) {
                $this->assertStringContainsString('(?P', $e->getMessage());
            }
        }
    }

    #[Test]
    public function test_pcre_named_group_single_quotes(): void
    {
        $ast = $this->parse("/(?'alias'a)/");
        $this->assertInstanceOf(GroupNode::class, $ast->pattern);
        $this->assertSame('alias', $ast->pattern->name);
    }

    #[Test]
    public function test_python_named_group_invalid_syntax(): void
    {
        $this->expectException(ParserException::class);
        // Missing group name
        $this->parse('/(?P<)/');
    }

    #[Test]
    public function test_max_pattern_length(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Regex pattern exceeds maximum length');

        // maxPatternLength is now enforced by the Regex class
        $regex = Regex::create(['max_pattern_length' => 5]);
        $regex->parse('/toolong/');
    }

    #[Test]
    public function test_invalid_range_codepoints_are_parsed_but_invalid(): void
    {
        // The parser itself allows [z-a], checking semantics is done by the Validator.
        // So we assert that it parses into a RangeNode successfully.
        $ast = $this->parse('/[z-a]/');

        $this->assertInstanceOf(CharClassNode::class, $ast->pattern);
        $this->assertInstanceOf(RangeNode::class, $ast->pattern->expression);
    }

    #[DoesNotPerformAssertions]
    #[Test]
    public function test_parse_conditional_define(): void
    {
        // (?(DEFINE)...)
        $this->parse('/(?(DEFINE)(?<A>a))(?&A)/');
    }

    #[DataProvider('extendedModeProvider')]
    #[Test]
    public function test_parser_handles_extended_mode_whitespace(string $pattern): void
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);

        // If we reach here without a "Quantifier without target" exception, the test passes.
        // The AST should be successfully parsed with extended mode flags.
        $this->assertStringContainsString('x', $ast->flags);
    }

    public static function extendedModeProvider(): \Iterator
    {
        // Case 1: Simple newline between atom and quantifier
        yield 'Newline separation' => ['/a
            ?/x'];
        // Case 2: Spaces and comments between atom and quantifier
        yield 'Comments separation' => ['/a  # comment
            +/x'];
        // Case 3: Complex Group with newline before quantifier (Symfony AssetMapper Case)
        yield 'Group with newline' => ['/(?:abc)
            ?/x'];
        // Case 4: Character class with newline
        yield 'Char class newline' => ['/[a-z]
            {2,}/x'];
        // Case 5: The actual Symfony AssetMapper Pattern fragment causing the crash
        yield 'Symfony AssetMapper Fragment' => ['/
                \s*[\'"`](\.\/[^\'"`\n]++|(\.\.\/)*+[^\'"`\n]++)[\'"`]\s*[;\)]
                ?
            /mxu'];
    }

    #[Test]
    public function test_parser_handles_null_byte_escape(): void
    {
        // Case 1: Null byte in a group
        $result = $this->regex->validate('/(\0)/');
        $this->assertTrue($result->isValid());

        // Case 2: Null byte in a character class (The Symfony case)
        $result2 = $this->regex->validate('/[^\0]/');
        $this->assertTrue($result2->isValid());

        // Case 3: Null byte followed by non-digit
        $result3 = $this->regex->validate('/\0a/');
        $this->assertTrue($result3->isValid());
    }

    #[Test]
    public function test_optimize_refuses_an_option_it_does_not_know(): void
    {
        // 1.x ignored an unknown option; 2.0 names it.
        foreach (['safe', 'aggressive'] as $mode) {
            try {
                $this->regex->optimize('/a+/', ['mode' => $mode]);
                $this->fail('An unknown optimizer option must be refused.');
            } catch (InvalidRegexOptionException $e) {
                $this->assertStringStartsWith('Unknown optimizer option "mode"', $e->getMessage());
            }
        }
    }

    #[Test]
    public function test_parse_pattern(): void
    {
        $ast = $this->regex->parsePattern('foo', 'i', '#');

        $this->assertSame('#', $ast->delimiter);
        $this->assertSame('i', $ast->flags);

        $paired = $this->regex->parsePattern('foo', 'i', '(');
        $this->assertSame('(', $paired->delimiter);
        $this->assertSame('i', $paired->flags);
    }

    #[Test]
    public function test_regex_pattern_from_delimited(): void
    {
        $pattern = DelimitedPattern::fromDelimited('/foo/i');

        $this->assertSame('foo', $pattern->pattern);
        $this->assertSame('i', $pattern->flags);
        $this->assertSame('/', $pattern->delimiter);
    }

    #[Test]
    public function test_regex_pattern_from_raw(): void
    {
        $pattern = DelimitedPattern::fromRaw('foo', 'i', '#');

        $this->assertSame('foo', $pattern->pattern);
        $this->assertSame('i', $pattern->flags);
        $this->assertSame('#', $pattern->delimiter);
    }

    #[Test]
    public function test_analyze_report(): void
    {
        $report = $this->regex->analyze('/foo/');

        $this->assertTrue($report->isValid);
        $this->assertIsArray($report->errors());
        $this->assertIsArray($report->lintIssues());
        $this->assertIsString($report->explain());
        $this->assertIsString($report->highlighted());
    }

    #[Test]
    public function test_regex_new(): void
    {
        $regex = Regex::new();
        $this->assertNotSame($this->regex, $regex);
    }

    #[Test]
    public function test_exception_with_visual_context(): void
    {
        $this->expectException(ParserException::class);
        // PHP: "two named subpatterns have the same name ... at offset 13".
        $this->expectExceptionMessage('Duplicate group name "a" at position 13.');

        $this->regex->parse('/(?<a>.) (?<a>.)/');
    }

    #[Test]
    public function test_quote_mode_parsing(): void
    {
        // Test \Q...\E quote mode parsing
        $ast = $this->parse('/\Qtest.*\E/');

        // The pattern should be parsed as a sequence with literal 'test.*'
        $this->assertInstanceOf(LiteralNode::class, $ast->pattern);
        $this->assertSame('test.*', $ast->pattern->value);

        // Test quote mode with special characters
        $ast2 = $this->parse('/\Q.+*?{}[]()\E/');
        $this->assertInstanceOf(LiteralNode::class, $ast2->pattern);
        $this->assertSame('.+*?{}[]()', $ast2->pattern->value);
    }

    #[Test]
    public function test_extended_mode_parses_every_comment_in_a_row(): void
    {
        $ast = $this->parse("/a # one\n # two\n b/x");

        $nodes = $ast->pattern instanceof SequenceNode ? $ast->pattern->children : [$ast->pattern];

        $comments = array_values(array_filter($nodes, static fn ($node) => $node instanceof CommentNode));
        $this->assertCount(2, $comments);
        $this->assertSame("# one\n", $comments[0]->comment);
        $this->assertSame("# two\n", $comments[1]->comment);
        // Both were written as "# ..." lines, not (?#...) groups.
        $this->assertTrue($comments[0]->extended);
        $this->assertTrue($comments[1]->extended);

        // The literal after the comments still belongs to the pattern.
        $literals = array_values(array_filter($nodes, static fn ($node) => $node instanceof LiteralNode));
        $this->assertSame(['a', 'b'], array_map(static fn (LiteralNode $literal) => $literal->value, $literals));
    }

    #[Test]
    public function test_extended_mode_collapses_quantifier_modifier_split_by_whitespace(): void
    {
        // PCRE reads "a* +" under /x as "a*+", and "a* ?" as "a*?".
        $possessive = $this->parse('/a* +/x')->pattern;
        $this->assertInstanceOf(QuantifierNode::class, $possessive);
        $this->assertSame(QuantifierType::Possessive, $possessive->type);

        $lazy = $this->parse('/a* ?/x')->pattern;
        $this->assertInstanceOf(QuantifierNode::class, $lazy);
        $this->assertSame(QuantifierType::Lazy, $lazy->type);
    }

    #[Test]
    public function test_empty_callout_has_no_string_identifier(): void
    {
        $ast = $this->parse('/(?C)x/');

        $nodes = $ast->pattern instanceof SequenceNode ? $ast->pattern->children : [$ast->pattern];
        $callouts = array_values(array_filter($nodes, static fn ($node) => $node instanceof CalloutNode));
        $this->assertCount(1, $callouts);
        $this->assertNull($callouts[0]->identifier);
        $this->assertFalse($callouts[0]->isStringIdentifier);
    }

    #[Test]
    public function test_pcre_verb_argument_is_kept_with_the_verb(): void
    {
        $ast = $this->parse('/(*MARK:foo)bar/');

        $nodes = $ast->pattern instanceof SequenceNode ? $ast->pattern->children : [$ast->pattern];
        $verbs = array_values(array_filter($nodes, static fn ($node) => $node instanceof PcreVerbNode));
        $this->assertCount(1, $verbs);
        $this->assertSame('MARK:foo', $verbs[0]->verb);
    }

    /**
     * Helper method to parse a regex string using the decoupled Lexer and Parser.
     */
    private function parse(string $regex): RegexNode
    {
        return $this->regex->parse($regex);
    }
}

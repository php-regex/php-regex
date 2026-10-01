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

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPUnit\Framework\TestCase;

final class OptimizerNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_sequence_flattens_nested_sequences(): void
    {
        $optimizer = new Rewriter();
        $inner = new SequenceNode([new LiteralNode('a', 0, 1), new LiteralNode('b', 1, 2)], 0, 2);
        $outer = new SequenceNode([$inner], 0, 2);

        $result = $outer->accept($optimizer);

        $this->assertInstanceOf(LiteralNode::class, $result);
        $this->assertSame('ab', $result->value);
    }

    public function test_sequence_with_empty_literal_returns_empty_literal(): void
    {
        $optimizer = new Rewriter();
        $sequence = new SequenceNode([new LiteralNode('', 0, 0)], 0, 0);

        $result = $sequence->accept($optimizer);

        $this->assertInstanceOf(LiteralNode::class, $result);
        $this->assertSame('', $result->value);
    }

    public function test_sequence_flattens_nested_sequences_with_non_literal_children(): void
    {
        $optimizer = new Rewriter();
        $inner = new SequenceNode([new CharTypeNode('d', 0, 0), new LiteralNode('a', 0, 0)], 0, 0);
        $outer = new SequenceNode([$inner, new LiteralNode('b', 0, 0)], 0, 0);

        $result = $outer->accept($optimizer);

        $this->assertInstanceOf(SequenceNode::class, $result);
        $this->assertCount(2, $result->children);
        $this->assertInstanceOf(CharTypeNode::class, $result->children[0]);
        $this->assertInstanceOf(LiteralNode::class, $result->children[1]);
        $this->assertSame('ab', $result->children[1]->value);
    }

    public function test_conditional_rebuilds_when_child_optimized(): void
    {
        $optimizer = new Rewriter();
        $condition = new SequenceNode([new LiteralNode('', 0, 0)], 0, 0);
        $node = new ConditionalNode($condition, new LiteralNode('a', 0, 0), new LiteralNode('b', 0, 0), 0, 0);

        $result = $node->accept($optimizer);

        $this->assertInstanceOf(ConditionalNode::class, $result);
        $this->assertNotSame($node, $result);
    }

    public function test_define_and_limit_match_are_visited(): void
    {
        $optimizer = new Rewriter();

        $define = new DefineNode(new LiteralNode('a', 0, 0), 0, 0);
        $defineResult = $define->accept($optimizer);
        $this->assertInstanceOf(DefineNode::class, $defineResult);
        $this->assertNotSame($define, $defineResult);

        $limit = new LimitMatchNode(100, 0, 0);
        $this->assertSame($limit, $limit->accept($optimizer));
    }

    public function test_normalize_char_class_parts_reverses_ranges_and_updates_scalar(): void
    {
        $optimizer = new Rewriter();
        $parts = [
            new LiteralNode('b', 0, 1),
            new RangeNode(new LiteralNode('d', 1, 2), new LiteralNode('a', 2, 3), 1, 3),
        ];

        $result = $this->invokePrivate($optimizer, 'normalizeCharClassParts', [$parts]);
        $this->assertIsArray($result);
        /* @var array{0: array<\PHPRegex\Parser\Node\NodeInterface>, 1: bool} $result */
        [$normalized, $changed] = $result;

        $this->assertTrue($changed);
        $this->assertNotEmpty($normalized);
    }

    public function test_visit_alternation_deduplicates_after_change(): void
    {
        $optimizer = new Rewriter();
        $alts = new AlternationNode([
            new SequenceNode([new LiteralNode('a', 0, 0), new LiteralNode('', 0, 0)], 0, 0),
            new LiteralNode('a', 0, 0),
            new CharTypeNode('d', 0, 0),
        ], 0, 0);

        $result = $alts->accept($optimizer);

        $this->assertInstanceOf(AlternationNode::class, $result);
        $this->assertCount(2, $result->alternatives);
    }

    public function test_factorize_alternation_returns_prefix_only_when_suffixes_empty(): void
    {
        $optimizer = new Rewriter();
        $alts = [new LiteralNode('ab', 0, 2), new LiteralNode('ab', 0, 2)];

        $result = $this->invokePrivate($optimizer, 'factorizeAlternation', [$alts]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function test_factorize_suffix_all_suffix_only(): void
    {
        $optimizer = new Rewriter();
        $alts = [new LiteralNode('ab', 0, 2), new LiteralNode('ab', 0, 2)];

        $result = $this->invokePrivate($optimizer, 'factorizeSuffix', [$alts]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function test_factorize_suffix_single_prefix_branch(): void
    {
        $optimizer = new Rewriter();
        $alts = [new LiteralNode('ab', 0, 2), new LiteralNode('cab', 0, 3)];

        $result = $this->invokePrivate($optimizer, 'factorizeSuffix', [$alts]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function test_find_common_prefix_empty_returns_empty_string(): void
    {
        $optimizer = new Rewriter();

        $result = $this->invokePrivate($optimizer, 'findCommonPrefix', [[]]);

        $this->assertSame('', $result);
    }

    public function test_merge_char_classes_and_char_types_merges_alternations(): void
    {
        $optimizer = new Rewriter();
        $class = new CharClassNode(
            new AlternationNode([new LiteralNode('a', 0, 1), new LiteralNode('b', 1, 2)], 0, 2),
            false,
            0,
            2,
        );

        $result = $this->invokePrivate($optimizer, 'mergeCharClassesAndCharTypes', [[
            $class,
            new CharTypeNode('d', 2, 3),
        ]]);

        $this->assertInstanceOf(CharClassNode::class, $result);
    }

    public function test_try_convert_alternation_to_char_class_range_and_null(): void
    {
        $optimizer = new Rewriter();

        $range = $this->invokePrivate($optimizer, 'tryConvertAlternationToCharClass', [[
            new LiteralNode('a', 0, 1),
            new LiteralNode('b', 1, 2),
            new LiteralNode('c', 2, 3),
        ], 0, 3]);

        $this->assertInstanceOf(CharClassNode::class, $range);

        $null = $this->invokePrivate($optimizer, 'tryConvertAlternationToCharClass', [[
            new LiteralNode('a', 0, 1),
            new LiteralNode('c', 1, 2),
            new LiteralNode('d', 2, 3),
        ], 0, 3]);

        $this->assertNull($null);
    }

    public function test_visit_regex_is_covered(): void
    {
        $optimizer = new Rewriter();
        $pattern = new SequenceNode([new LiteralNode('test', 0, 4)], 0, 4);
        $regex = new RegexNode($pattern, 'i', '/', 0, 4);

        $result = $regex->accept($optimizer);

        $this->assertInstanceOf(RegexNode::class, $result);
    }

    public function test_visit_group_is_covered(): void
    {
        $optimizer = new Rewriter();
        $child = new LiteralNode('test', 1, 5);
        $group = new GroupNode($child, GroupType::NonCapturing, null, null, 0, 6);

        $result = $group->accept($optimizer);

        // Optimizer unwraps non-capturing groups with single literals
        $this->assertInstanceOf(LiteralNode::class, $result);
    }

    public function test_visit_quantifier_is_covered(): void
    {
        $optimizer = new Rewriter();
        $child = new LiteralNode('a', 0, 1);
        $quantifier = new QuantifierNode($child, '+', QuantifierType::Greedy, 0, 2);

        $result = $quantifier->accept($optimizer);

        $this->assertInstanceOf(QuantifierNode::class, $result);
    }

    public function test_visit_char_class_is_covered(): void
    {
        $optimizer = new Rewriter();
        $expression = new AlternationNode([new LiteralNode('a', 1, 2), new LiteralNode('b', 3, 4)], 1, 4);
        $charClass = new CharClassNode($expression, false, 0, 5);

        $result = $charClass->accept($optimizer);

        $this->assertInstanceOf(CharClassNode::class, $result);
    }

    public function test_visit_range_is_covered(): void
    {
        $optimizer = new Rewriter();
        $start = new LiteralNode('a', 1, 2);
        $end = new LiteralNode('z', 3, 4);
        $range = new RangeNode($start, $end, 1, 4);

        $result = $range->accept($optimizer);

        $this->assertSame($range, $result);
    }

    public function test_visit_dot_is_covered(): void
    {
        $optimizer = new Rewriter();
        $dot = new DotNode(0, 1);

        $result = $dot->accept($optimizer);

        $this->assertSame($dot, $result);
    }

    public function test_visit_anchor_is_covered(): void
    {
        $optimizer = new Rewriter();
        $anchor = new AnchorNode('^', 0, 1);

        $result = $anchor->accept($optimizer);

        $this->assertSame($anchor, $result);
    }

    public function test_visit_assertion_is_covered(): void
    {
        $optimizer = new Rewriter();
        $assertion = new AssertionNode('\\b', 0, 2);

        $result = $assertion->accept($optimizer);

        $this->assertSame($assertion, $result);
    }

    public function test_visit_keep_is_covered(): void
    {
        $optimizer = new Rewriter();
        $keep = new KeepNode(0, 2);

        $result = $keep->accept($optimizer);

        $this->assertSame($keep, $result);
    }

    public function test_visit_backref_is_covered(): void
    {
        $optimizer = new Rewriter();
        $backref = new BackrefNode('1', 0, 2);

        $result = $backref->accept($optimizer);

        $this->assertSame($backref, $result);
    }

    public function test_visit_unicode_prop_is_covered(): void
    {
        $optimizer = new Rewriter();
        $unicodeProp = new UnicodePropNode('\\p{L}', false, 0, 4);

        $result = $unicodeProp->accept($optimizer);

        $this->assertSame($unicodeProp, $result);
    }

    public function test_visit_char_literal_is_covered(): void
    {
        $optimizer = new Rewriter();
        $charLiteral = new CharLiteralNode('\\n', 10, CharLiteralType::Unicode, 0, 2);

        $result = $charLiteral->accept($optimizer);

        $this->assertSame($charLiteral, $result);
    }

    public function test_visit_posix_class_is_covered(): void
    {
        $optimizer = new Rewriter();
        $posixClass = new PosixClassNode('alnum', 0, 7);

        $result = $posixClass->accept($optimizer);

        $this->assertSame($posixClass, $result);
    }

    public function test_visit_comment_is_covered(): void
    {
        $optimizer = new Rewriter();
        $comment = new CommentNode('test comment', 0, 14);

        $result = $comment->accept($optimizer);

        $this->assertSame($comment, $result);
    }

    public function test_visit_subroutine_is_covered(): void
    {
        $optimizer = new Rewriter();
        $subroutine = new SubroutineNode('1', '&', 0, 3);

        $result = $subroutine->accept($optimizer);

        $this->assertSame($subroutine, $result);
    }

    public function test_visit_pcre_verb_is_covered(): void
    {
        $optimizer = new Rewriter();
        $pcreVerb = new PcreVerbNode('ACCEPT', 0, 8);

        $result = $pcreVerb->accept($optimizer);

        $this->assertSame($pcreVerb, $result);
    }

    public function test_visit_callout_is_covered(): void
    {
        $optimizer = new Rewriter();
        $callout = new CalloutNode(1, false, 0, 4);

        $result = $callout->accept($optimizer);

        $this->assertSame($callout, $result);
    }

    public function test_nullable_status_covers_all_branches(): void
    {
        $optimizer = new Rewriter();

        // Test LiteralNode with empty string
        $emptyLiteral = new LiteralNode('', 0, 0);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$emptyLiteral]);
        $this->assertTrue($result);

        // Test LiteralNode with content
        $literal = new LiteralNode('a', 0, 1);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$literal]);
        $this->assertFalse($result);

        // Test atomic nodes (should return false)
        $dot = new DotNode(0, 1);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$dot]);
        $this->assertFalse($result);

        $charType = new CharTypeNode('d', 0, 2);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$charType]);
        $this->assertFalse($result);

        // Test nodes that return true (anchors, assertions, etc.)
        $anchor = new AnchorNode('^', 0, 1);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$anchor]);
        $this->assertTrue($result);

        $assertion = new AssertionNode('\\b', 0, 2);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$assertion]);
        $this->assertTrue($result);

        // Test GroupNode with lookahead (should return true)
        $child = new LiteralNode('a', 1, 2);
        $group = new GroupNode($child, GroupType::LookaheadPositive, null, null, 0, 3);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$group]);
        $this->assertTrue($result);

        // Test QuantifierNode with zero-allowed quantifier
        $quantifier = new QuantifierNode($child, '*', QuantifierType::Greedy, 0, 2);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$quantifier]);
        $this->assertTrue($result);

        // Test SequenceNode
        $seq = new SequenceNode([$child, $emptyLiteral], 0, 2);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$seq]);
        $this->assertFalse($result);

        // Test AlternationNode
        $alt = new AlternationNode([$child, $emptyLiteral], 0, 2);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$alt]);
        $this->assertTrue($result);

        // Test ConditionalNode (should return true because no branch is nullable)
        $conditional = new ConditionalNode($child, $child, $emptyLiteral, 0, 3);
        $result = $this->invokePrivate($optimizer, 'nullableStatus', [$conditional]);
        $this->assertTrue($result);
    }

    public function test_quantifier_allows_zero_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test quantifiers that allow zero
        $this->assertTrue($this->invokePrivate($optimizer, 'quantifierAllowsZero', ['*']));
        $this->assertTrue($this->invokePrivate($optimizer, 'quantifierAllowsZero', ['?']));
        $this->assertTrue($this->invokePrivate($optimizer, 'quantifierAllowsZero', ['{0,}']));
        $this->assertTrue($this->invokePrivate($optimizer, 'quantifierAllowsZero', ['{0,5}']));

        // Test quantifiers that don't allow zero
        $this->assertFalse($this->invokePrivate($optimizer, 'quantifierAllowsZero', ['+']));
        $this->assertFalse($this->invokePrivate($optimizer, 'quantifierAllowsZero', ['{1}']));
        $this->assertFalse($this->invokePrivate($optimizer, 'quantifierAllowsZero', ['{2,}']));
        $this->assertFalse($this->invokePrivate($optimizer, 'quantifierAllowsZero', ['{1,5}']));
    }

    public function test_char_from_code_point_covers_edge_cases(): void
    {
        $optimizer = new Rewriter();

        // Test regular ASCII character
        $result = $this->invokePrivate($optimizer, 'charFromCodePoint', [65]); // 'A'
        $this->assertSame('A', $result);

        // Test extended ASCII (should use \chr() fallback)
        $result = $this->invokePrivate($optimizer, 'charFromCodePoint', [200]);
        $this->assertSame('È', $result);

        // Test invalid code point (should return empty string)
        $result = $this->invokePrivate($optimizer, 'charFromCodePoint', [-1]);
        $this->assertSame('', $result);
    }

    public function test_factorize_alternation_with_literal_nodes(): void
    {
        $optimizer = new Rewriter();

        // Create literal nodes for testing
        $abc = new LiteralNode('abc', 0, 3);
        $adc = new LiteralNode('adc', 0, 3);
        $aec = new LiteralNode('aec', 0, 3);

        $alts = [$abc, $adc, $aec];
        $result = $this->invokePrivate($optimizer, 'factorizeAlternation', [$alts]);

        // Should factorize to 'a' followed by alternation of 'bc', 'dc', 'ec'
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function test_factorize_alternation_no_common_prefix(): void
    {
        $optimizer = new Rewriter();

        $abc = new LiteralNode('abc', 0, 3);
        $def = new LiteralNode('def', 0, 3);

        $alts = [$abc, $def];
        $result = $this->invokePrivate($optimizer, 'factorizeAlternation', [$alts]);

        // No common prefix, should return original array
        $this->assertSame($alts, $result);
    }

    public function test_factorize_suffix_with_literal_nodes(): void
    {
        $optimizer = new Rewriter();

        $cab = new LiteralNode('cab', 0, 3);
        $dab = new LiteralNode('dab', 0, 3);
        $eab = new LiteralNode('eab', 0, 3);

        $alts = [$cab, $dab, $eab];
        $result = $this->invokePrivate($optimizer, 'factorizeSuffix', [$alts]);

        // Should factorize to alternation of 'c', 'd', 'e' followed by 'ab'
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function test_string_to_node_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test single character
        $result = $this->invokePrivate($optimizer, 'stringToNode', ['a', 0, 1]);
        $this->assertInstanceOf(LiteralNode::class, $result);

        // Test quantifier pattern
        $result = $this->invokePrivate($optimizer, 'stringToNode', ['{2,}', 0, 4]);
        $this->assertInstanceOf(LiteralNode::class, $result);

        // Test string with escape sequences
        $result = $this->invokePrivate($optimizer, 'stringToNode', ['\\d', 0, 2]);
        $this->assertInstanceOf(CharTypeNode::class, $result);

        // Test string with multiple characters
        $result = $this->invokePrivate($optimizer, 'stringToNode', ['abc', 0, 3]);
        $this->assertInstanceOf(SequenceNode::class, $result);
    }

    public function test_find_common_prefix_covers_edge_cases(): void
    {
        $optimizer = new Rewriter();

        // Test empty array
        $result = $this->invokePrivate($optimizer, 'findCommonPrefix', [[]]);
        $this->assertSame('', $result);

        // Test single string
        $result = $this->invokePrivate($optimizer, 'findCommonPrefix', [['abc']]);
        $this->assertSame('abc', $result);

        // Test common prefix
        $result = $this->invokePrivate($optimizer, 'findCommonPrefix', [['abc', 'abd', 'abe']]);
        $this->assertSame('ab', $result);

        // Test no common prefix
        $result = $this->invokePrivate($optimizer, 'findCommonPrefix', [['abc', 'def']]);
        $this->assertSame('', $result);
    }

    public function test_pattern_contains_dots_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test with dot
        $dot = new DotNode(0, 1);
        $result = $this->invokePrivate($optimizer, 'patternContainsDots', [$dot]);
        $this->assertTrue($result);

        // Test with sequence containing dot
        $sequence = new SequenceNode([new LiteralNode('a', 0, 1), new DotNode(1, 2)], 0, 2);
        $result = $this->invokePrivate($optimizer, 'patternContainsDots', [$sequence]);
        $this->assertTrue($result);

        // Test with alternation containing dot
        $alternation = new AlternationNode([new LiteralNode('a', 0, 1), new DotNode(1, 2)], 0, 2);
        $result = $this->invokePrivate($optimizer, 'patternContainsDots', [$alternation]);
        $this->assertTrue($result);

        // Test with group containing dot
        $group = new GroupNode(new DotNode(1, 2), GroupType::NonCapturing, null, null, 0, 3);
        $result = $this->invokePrivate($optimizer, 'patternContainsDots', [$group]);
        $this->assertTrue($result);

        // Test with quantifier containing dot
        $quantifier = new QuantifierNode(new DotNode(0, 1), '+', QuantifierType::Greedy, 0, 2);
        $result = $this->invokePrivate($optimizer, 'patternContainsDots', [$quantifier]);
        $this->assertTrue($result);

        // Test with char class containing dot expression
        $expression = new AlternationNode([new LiteralNode('a', 1, 2), new DotNode(2, 3)], 1, 3);
        $charClass = new CharClassNode($expression, false, 0, 4);
        $result = $this->invokePrivate($optimizer, 'patternContainsDots', [$charClass]);
        $this->assertTrue($result);

        // Test without dots
        $literal = new LiteralNode('abc', 0, 3);
        $result = $this->invokePrivate($optimizer, 'patternContainsDots', [$literal]);
        $this->assertFalse($result);
    }

    public function test_pattern_contains_multiline_anchors_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test with caret
        $caret = new AnchorNode('^', 0, 1);
        $result = $this->invokePrivate($optimizer, 'patternContainsMultilineAnchors', [$caret]);
        $this->assertTrue($result);

        // Test with dollar
        $dollar = new AnchorNode('$', 0, 1);
        $result = $this->invokePrivate($optimizer, 'patternContainsMultilineAnchors', [$dollar]);
        $this->assertTrue($result);

        // Test sequence with anchor
        $sequence = new SequenceNode([new LiteralNode('a', 0, 1), new AnchorNode('^', 1, 2)], 0, 2);
        $result = $this->invokePrivate($optimizer, 'patternContainsMultilineAnchors', [$sequence]);
        $this->assertTrue($result);

        // Test alternation with anchor
        $alternation = new AlternationNode([new LiteralNode('a', 0, 1), new AnchorNode('$', 1, 2)], 0, 2);
        $result = $this->invokePrivate($optimizer, 'patternContainsMultilineAnchors', [$alternation]);
        $this->assertTrue($result);

        // Test group with anchor
        $group = new GroupNode(new AnchorNode('^', 1, 2), GroupType::NonCapturing, null, null, 0, 3);
        $result = $this->invokePrivate($optimizer, 'patternContainsMultilineAnchors', [$group]);
        $this->assertTrue($result);

        // Test quantifier with anchor
        $quantifier = new QuantifierNode(new AnchorNode('$', 0, 1), '+', QuantifierType::Greedy, 0, 2);
        $result = $this->invokePrivate($optimizer, 'patternContainsMultilineAnchors', [$quantifier]);
        $this->assertTrue($result);

        // Test define with anchor
        $define = new DefineNode(new AnchorNode('^', 0, 1), 0, 1);
        $result = $this->invokePrivate($optimizer, 'patternContainsMultilineAnchors', [$define]);
        $this->assertTrue($result);

        // Test without multiline anchors
        $literal = new LiteralNode('abc', 0, 3);
        $result = $this->invokePrivate($optimizer, 'patternContainsMultilineAnchors', [$literal]);
        $this->assertFalse($result);
    }

    public function test_is_possessify_candidate_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test with + quantifier
        $quantifier1 = new QuantifierNode(new LiteralNode('a', 0, 1), '+', QuantifierType::Greedy, 0, 2);
        $result = $this->invokePrivate($optimizer, 'isPossessifyCandidate', [$quantifier1]);
        $this->assertTrue($result);

        // Test with * quantifier
        $quantifier2 = new QuantifierNode(new LiteralNode('a', 0, 1), '*', QuantifierType::Greedy, 0, 2);
        $result = $this->invokePrivate($optimizer, 'isPossessifyCandidate', [$quantifier2]);
        $this->assertTrue($result);

        // Test with {0,} quantifier
        $quantifier3 = new QuantifierNode(new LiteralNode('a', 0, 1), '{0,}', QuantifierType::Greedy, 0, 4);
        $result = $this->invokePrivate($optimizer, 'isPossessifyCandidate', [$quantifier3]);
        $this->assertTrue($result);

        // Test with {5,} quantifier
        $quantifier4 = new QuantifierNode(new LiteralNode('a', 0, 1), '{5,}', QuantifierType::Greedy, 0, 5);
        $result = $this->invokePrivate($optimizer, 'isPossessifyCandidate', [$quantifier4]);
        $this->assertTrue($result);

        // Test with {1} quantifier (should be false)
        $quantifier5 = new QuantifierNode(new LiteralNode('a', 0, 1), '{1}', QuantifierType::Greedy, 0, 4);
        $result = $this->invokePrivate($optimizer, 'isPossessifyCandidate', [$quantifier5]);
        $this->assertFalse($result);

        // Test with ? quantifier (should be false)
        $quantifier6 = new QuantifierNode(new LiteralNode('a', 0, 1), '?', QuantifierType::Greedy, 0, 2);
        $result = $this->invokePrivate($optimizer, 'isPossessifyCandidate', [$quantifier6]);
        $this->assertFalse($result);
    }

    public function test_can_match_empty_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test with nullable node (empty literal)
        $emptyLiteral = new LiteralNode('', 0, 0);
        $result = $this->invokePrivate($optimizer, 'canMatchEmpty', [$emptyLiteral]);
        $this->assertTrue($result);

        // Test with non-nullable node
        $literal = new LiteralNode('a', 0, 1);
        $result = $this->invokePrivate($optimizer, 'canMatchEmpty', [$literal]);
        $this->assertFalse($result);

        // Test with unknown nullable status (backref)
        $backref = new BackrefNode('1', 0, 2);
        $result = $this->invokePrivate($optimizer, 'canMatchEmpty', [$backref]);
        $this->assertTrue($result); // defaults to true when unknown
    }

    public function test_is_full_word_class_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test with complete word class parts
        $parts = [
            new RangeNode(new LiteralNode('a', 0, 1), new LiteralNode('z', 1, 2), 0, 2),
            new RangeNode(new LiteralNode('A', 2, 3), new LiteralNode('Z', 3, 4), 2, 4),
            new RangeNode(new LiteralNode('0', 4, 5), new LiteralNode('9', 5, 6), 4, 6),
            new LiteralNode('_', 6, 7),
        ];
        $result = $this->invokePrivate($optimizer, 'isFullWordClass', [$parts]);
        $this->assertTrue($result);

        // Test with incomplete word class
        $parts2 = [
            new RangeNode(new LiteralNode('a', 0, 1), new LiteralNode('z', 1, 2), 0, 2),
            new RangeNode(new LiteralNode('A', 2, 3), new LiteralNode('Z', 3, 4), 2, 4),
            // missing digits and underscore
        ];
        $result = $this->invokePrivate($optimizer, 'isFullWordClass', [$parts2]);
        $this->assertFalse($result);
    }

    public function test_parse_quantifier_count_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test exact count {3}
        $result = $this->invokePrivate($optimizer, 'parseQuantifierCount', ['{3}']);
        $this->assertSame(3, $result);

        // Test range {2,5}
        $result = $this->invokePrivate($optimizer, 'parseQuantifierCount', ['{2,5}']);
        $this->assertNull($result);

        // Test open range {2,}
        $result = $this->invokePrivate($optimizer, 'parseQuantifierCount', ['{2,}']);
        $this->assertNull($result);

        // Test invalid format
        $result = $this->invokePrivate($optimizer, 'parseQuantifierCount', ['+']);
        $this->assertNull($result);
    }

    public function test_is_capture_sensitive_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test capturing group
        $capturingGroup = new GroupNode(new LiteralNode('a', 1, 2), GroupType::Capturing, null, null, 0, 3);
        $result = $this->invokePrivate($optimizer, 'isCaptureSensitive', [$capturingGroup]);
        $this->assertTrue($result);

        // Test named group
        $namedGroup = new GroupNode(new LiteralNode('a', 1, 2), GroupType::Named, 'name', null, 0, 8);
        $result = $this->invokePrivate($optimizer, 'isCaptureSensitive', [$namedGroup]);
        $this->assertTrue($result);

        // Test branch reset group
        $branchResetGroup = new GroupNode(new LiteralNode('a', 1, 2), GroupType::BranchReset, null, null, 0, 5);
        $result = $this->invokePrivate($optimizer, 'isCaptureSensitive', [$branchResetGroup]);
        $this->assertTrue($result);

        // Test backref
        $backref = new BackrefNode('1', 0, 2);
        $result = $this->invokePrivate($optimizer, 'isCaptureSensitive', [$backref]);
        $this->assertTrue($result);

        // Test subroutine
        $subroutine = new SubroutineNode('1', '&', 0, 3);
        $result = $this->invokePrivate($optimizer, 'isCaptureSensitive', [$subroutine]);
        $this->assertTrue($result);

        // Test conditional
        $conditional = new ConditionalNode(new LiteralNode('a', 0, 1), new LiteralNode('b', 1, 2), new LiteralNode('c', 2, 3), 0, 3);
        $result = $this->invokePrivate($optimizer, 'isCaptureSensitive', [$conditional]);
        $this->assertTrue($result);

        // Test non-capturing group with literal (should be false)
        $nonCapturingGroup = new GroupNode(new LiteralNode('a', 1, 2), GroupType::NonCapturing, null, null, 0, 3);
        $result = $this->invokePrivate($optimizer, 'isCaptureSensitive', [$nonCapturingGroup]);
        $this->assertFalse($result);
    }

    public function test_create_quantified_node_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test with count > 1
        $node = new LiteralNode('a', 0, 1);
        $result = $this->invokePrivate($optimizer, 'createQuantifiedNode', [$node, 3]);
        $this->assertInstanceOf(QuantifierNode::class, $result);
        $this->assertSame('{3}', $result->quantifier);

        // Test with count = 1 (should return original node)
        $result = $this->invokePrivate($optimizer, 'createQuantifiedNode', [$node, 1]);
        $this->assertSame($node, $result);
    }

    public function test_normalize_quantifier_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        $node = new LiteralNode('a', 0, 1);

        // Test {0,} -> *
        $quantifier1 = new QuantifierNode($node, '{0,}', QuantifierType::Greedy, 0, 5);
        $result = $this->invokePrivate($optimizer, 'normalizeQuantifier', [$quantifier1]);
        $this->assertInstanceOf(QuantifierNode::class, $result);
        $this->assertSame('*', $result->quantifier);

        // Test {1,} -> +
        $quantifier2 = new QuantifierNode($node, '{1,}', QuantifierType::Greedy, 0, 5);
        $result = $this->invokePrivate($optimizer, 'normalizeQuantifier', [$quantifier2]);
        $this->assertInstanceOf(QuantifierNode::class, $result);
        $this->assertSame('+', $result->quantifier);

        // Test {0,1} -> ?
        $quantifier3 = new QuantifierNode($node, '{0,1}', QuantifierType::Greedy, 0, 6);
        $result = $this->invokePrivate($optimizer, 'normalizeQuantifier', [$quantifier3]);
        $this->assertInstanceOf(QuantifierNode::class, $result);
        $this->assertSame('?', $result->quantifier);

        // Test {1} -> remove quantifier
        $quantifier4 = new QuantifierNode($node, '{1}', QuantifierType::Greedy, 0, 4);
        $result = $this->invokePrivate($optimizer, 'normalizeQuantifier', [$quantifier4]);
        $this->assertSame($node, $result);

        // Test {0} -> empty literal
        $quantifier5 = new QuantifierNode($node, '{0}', QuantifierType::Greedy, 0, 4);
        $result = $this->invokePrivate($optimizer, 'normalizeQuantifier', [$quantifier5]);
        $this->assertInstanceOf(LiteralNode::class, $result);
        $this->assertSame('', $result->value);
    }

    public function test_can_alternation_be_char_class_covers_all_cases(): void
    {
        $optimizer = new Rewriter();

        // Test empty alternation
        $result = $this->invokePrivate($optimizer, 'canAlternationBeCharClass', [[]]);
        $this->assertFalse($result);

        // Test with non-literal nodes
        $alternatives = [new DotNode(0, 1)];
        $result = $this->invokePrivate($optimizer, 'canAlternationBeCharClass', [$alternatives]);
        $this->assertFalse($result);

        // Test with multi-character literal
        $alternatives = [new LiteralNode('ab', 0, 2)];
        $result = $this->invokePrivate($optimizer, 'canAlternationBeCharClass', [$alternatives]);
        $this->assertFalse($result);

        // Test with char class metacharacter
        $alternatives = [new LiteralNode(']', 0, 1)];
        $result = $this->invokePrivate($optimizer, 'canAlternationBeCharClass', [$alternatives]);
        $this->assertFalse($result);

        // Test with valid single characters
        $alternatives = [new LiteralNode('a', 0, 1), new LiteralNode('b', 1, 2)];
        $result = $this->invokePrivate($optimizer, 'canAlternationBeCharClass', [$alternatives]);
        $this->assertTrue($result);
    }

    public function test_deduplicate_alternation_covers_case(): void
    {
        $optimizer = new Rewriter();

        // Test with duplicates
        $alternatives = [
            new LiteralNode('a', 0, 1),
            new LiteralNode('b', 1, 2),
            new LiteralNode('a', 2, 3), // duplicate
        ];
        /** @var array<NodeInterface> $result */
        $result = $this->invokePrivate($optimizer, 'deduplicateAlternation', [$alternatives]);
        $this->assertCount(2, $result);
    }

    public function test_compact_sequence_covers_cases(): void
    {
        $optimizer = new Rewriter();

        // Test empty sequence
        $result = $this->invokePrivate($optimizer, 'compactSequence', [[]]);
        $this->assertSame([], $result);

        // Test with repeated literals
        $children = [
            new LiteralNode('a', 0, 1),
            new LiteralNode('a', 1, 2),
            new LiteralNode('a', 2, 3),
            new LiteralNode('a', 3, 4),
            new LiteralNode('a', 4, 5),
        ];
        /** @var array<NodeInterface> $result */
        $result = $this->invokePrivate($optimizer, 'compactSequence', [$children]);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(QuantifierNode::class, $result[0]);
    }

    public function test_are_nodes_equal_covers_cases(): void
    {
        $optimizer = new Rewriter();

        // Test same type, same content
        $node1 = new LiteralNode('abc', 0, 3);
        $node2 = new LiteralNode('abc', 0, 3);
        $result = $this->invokePrivate($optimizer, 'areNodesEqual', [$node1, $node2]);
        $this->assertTrue($result);

        // Test different types
        $node3 = new DotNode(0, 1);
        $result = $this->invokePrivate($optimizer, 'areNodesEqual', [$node1, $node3]);
        $this->assertFalse($result);

        // Test same type, different content
        $node4 = new LiteralNode('def', 0, 3);
        $result = $this->invokePrivate($optimizer, 'areNodesEqual', [$node1, $node4]);
        $this->assertFalse($result);
    }

    public function test_remove_useless_flags_covers_cases(): void
    {
        $optimizer = new Rewriter();

        // Test removing 's' flag when no dots
        $pattern = new LiteralNode('abc', 0, 3);
        $result = $this->invokePrivate($optimizer, 'removeUselessFlags', ['si', $pattern]);
        $this->assertSame('i', $result);

        // Test removing 'm' flag when no multiline anchors
        $result = $this->invokePrivate($optimizer, 'removeUselessFlags', ['mi', $pattern]);
        $this->assertSame('i', $result);

        // Test keeping flags when needed
        $patternWithDot = new DotNode(0, 1);
        $result = $this->invokePrivate($optimizer, 'removeUselessFlags', ['si', $patternWithDot]);
        $this->assertSame('si', $result);
    }

    public function test_merge_adjacent_char_classes_covers_cases(): void
    {
        $optimizer = new Rewriter();

        // Test merging adjacent char classes
        $class1 = new CharClassNode(
            new AlternationNode([new LiteralNode('a', 1, 2), new LiteralNode('b', 3, 4)], 1, 4),
            false, 0, 5,
        );
        $class2 = new CharClassNode(
            new AlternationNode([new LiteralNode('c', 6, 7), new LiteralNode('d', 8, 9)], 6, 9),
            false, 5, 10,
        );

        $alternatives = [$class1, $class2];
        /** @var array<NodeInterface> $result */
        $result = $this->invokePrivate($optimizer, 'mergeAdjacentCharClasses', [$alternatives]);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(CharClassNode::class, $result[0]);
    }

    public function test_can_convert_char_type_to_char_class_covers_cases(): void
    {
        $optimizer = new Rewriter();

        // Test \d in non-unicode mode (should be true)
        $digitType = new CharTypeNode('d', 0, 2);
        $result = $this->invokePrivate($optimizer, 'canConvertCharTypeToCharClass', [$digitType]);
        $this->assertTrue($result);

        // Test \d in unicode mode (should be false)
        $optimizerUnicode = new Rewriter();
        // Set unicode mode
        $regex = new RegexNode(new LiteralNode('test', 0, 4), 'u', '/', 0, 4);
        $regex->accept($optimizerUnicode); // This sets unicode mode
        $result = $this->invokePrivate($optimizerUnicode, 'canConvertCharTypeToCharClass', [$digitType]);
        $this->assertFalse($result);

        // Test \w (should be false)
        $wordType = new CharTypeNode('w', 0, 2);
        $result = $this->invokePrivate($optimizer, 'canConvertCharTypeToCharClass', [$wordType]);
        $this->assertFalse($result);
    }

    public function test_try_convert_alternation_to_char_class_covers_cases(): void
    {
        $optimizer = new Rewriter();

        // Test with too few literals
        $alternatives = [new LiteralNode('a', 0, 1)];
        $result = $this->invokePrivate($optimizer, 'tryConvertAlternationToCharClass', [$alternatives, 0, 1]);
        $this->assertNull($result);

        // Test with char class metacharacter
        $alternatives = [new LiteralNode('a', 0, 1), new LiteralNode(']', 1, 2)];
        $result = $this->invokePrivate($optimizer, 'tryConvertAlternationToCharClass', [$alternatives, 0, 2]);
        $this->assertNull($result);

        // Test with valid consecutive range
        $alternatives = [
            new LiteralNode('a', 0, 1),
            new LiteralNode('b', 1, 2),
            new LiteralNode('c', 2, 3),
            new LiteralNode('d', 3, 4),
            new LiteralNode('e', 4, 5),
        ];
        $result = $this->invokePrivate($optimizer, 'tryConvertAlternationToCharClass', [$alternatives, 0, 5]);
        $this->assertInstanceOf(CharClassNode::class, $result);
    }

    public function test_should_build_range_covers_cases(): void
    {
        $optimizer = new Rewriter();

        // Test with explicit range (should be true)
        $result = $this->invokePrivate($optimizer, 'shouldBuildRange', [97, 122, true]); // a-z
        $this->assertTrue($result);

        // Test without explicit range, excluding dash (should be true)
        $result = $this->invokePrivate($optimizer, 'shouldBuildRange', [97, 122, false]); // a-z, no explicit
        $this->assertTrue($result);

        // Test with dash as endpoint (should be false)
        $result = $this->invokePrivate($optimizer, 'shouldBuildRange', [45, 122, false]); // -z
        $this->assertFalse($result);
    }

    public function test_get_char_category_covers_cases(): void
    {
        $optimizer = new Rewriter();

        // Test digits
        $result = $this->invokePrivate($optimizer, 'getCharCategory', [48]); // '0'
        $this->assertSame(1, $result);

        // Test uppercase
        $result = $this->invokePrivate($optimizer, 'getCharCategory', [65]); // 'A'
        $this->assertSame(2, $result);

        // Test lowercase
        $result = $this->invokePrivate($optimizer, 'getCharCategory', [97]); // 'a'
        $this->assertSame(3, $result);

        // Test other
        $result = $this->invokePrivate($optimizer, 'getCharCategory', [33]); // '!'
        $this->assertSame(0, $result);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($target);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invokeArgs($target, $args);
    }
}

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

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\TestCase;
use RegexParser\Analysis\CharSetAnalyzer;
use RegexParser\Lint\Rule\GroupIndex;
use RegexParser\Lint\Rule\InlineFlagsRule;
use RegexParser\Lint\Rule\LintContext;
use RegexParser\Lint\Rule\NestedDotStarRule;
use RegexParser\Lint\Rule\NestedQuantifierRule;
use RegexParser\Lint\Rule\OverlappingAlternationRule;
use RegexParser\Lint\Rule\PatternInfo;
use RegexParser\Lint\Rule\RedundantCharClassRule;
use RegexParser\Lint\Rule\RedundantGroupRule;
use RegexParser\Lint\Rule\Support\CharClassSets;
use RegexParser\Lint\Rule\Support\NodePredicates;
use RegexParser\Lint\Rule\UselessIFlagRule;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\ClassOperationType;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\NodeVisitor\LinterNodeVisitor;
use RegexParser\Regex;

final class LinterNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_octal_escape_out_of_range_adds_issue(): void
    {
        $linter = new LinterNodeVisitor();

        $node = new CharLiteralNode('\\777', 0x200, CharLiteralType::OCTAL, 0, 0);
        $node->accept($linter);

        $warnings = $linter->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Suspicious octal escape', $warnings[0]);
    }

    public function test_octal_escape_past_377_is_a_code_point_in_utf_mode(): void
    {
        // "\666" is U+01B6 with "u"; without it PHP refuses it.
        $octalWarnings = static function (string $pattern): array {
            $linter = new LinterNodeVisitor();
            Regex::create(['cache' => null])->parse($pattern)->accept($linter);

            return array_values(array_filter($linter->getWarnings(), static fn (string $warning): bool => str_contains($warning, 'Suspicious octal escape')));
        };

        $this->assertSame([], $octalWarnings('/\\666/u'));
        $this->assertCount(1, $octalWarnings('/\\666/'));
    }

    public function test_count_capturing_groups_handles_conditionals(): void
    {
        $linter = new LinterNodeVisitor();
        $conditional = new ConditionalNode(
            new LiteralNode('a', 0, 0),
            new GroupNode(new LiteralNode('b', 0, 0), GroupType::T_GROUP_CAPTURING, null, null, 0, 0),
            new LiteralNode('c', 0, 0),
            0,
            0,
        );

        $regex = new RegexNode($conditional, '', '/', 0, 0);
        $regex->accept($linter);

        $this->assertSame([], $linter->getIssues());
    }

    public function test_is_consuming_recognizes_node_types(): void
    {
        $this->assertTrue(NodePredicates::isConsuming(new CharClassNode(new LiteralNode('a', 0, 0), false, 0, 0)));
        $this->assertTrue(NodePredicates::isConsuming(new CharTypeNode('d', 0, 0)));
        $this->assertTrue(NodePredicates::isConsuming(new DotNode(0, 0)));
        $this->assertTrue(NodePredicates::isConsuming(new CharLiteralNode('\\x41', 0x41, CharLiteralType::UNICODE, 0, 0)));
        $this->assertTrue(NodePredicates::isConsuming(new UnicodePropNode('L', true, 0, 0)));
        $this->assertTrue(NodePredicates::isConsuming(new PosixClassNode('alpha', 0, 0)));
        $this->assertTrue(NodePredicates::isConsuming(new QuantifierNode(new LiteralNode('a', 0, 0), '+', QuantifierType::T_GREEDY, 0, 0)));
        $this->assertFalse(NodePredicates::isConsuming(new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_LOOKAHEAD_POSITIVE, null, null, 0, 0)));

        $alternation = new AlternationNode([
            new AnchorNode('^', 0, 0),
            new LiteralNode('a', 0, 0),
        ], 0, 0);
        $this->assertTrue(NodePredicates::isConsuming($alternation));

        $nonConsumingAlt = new AlternationNode([
            new AnchorNode('$', 0, 0),
        ], 0, 0);
        $this->assertFalse(NodePredicates::isConsuming($nonConsumingAlt));

        $sequence = new SequenceNode([
            new AnchorNode('^', 0, 0),
            new LiteralNode('b', 0, 0),
        ], 0, 0);
        $this->assertTrue(NodePredicates::isConsuming($sequence));

        $nonConsumingSeq = new SequenceNode([
            new AnchorNode('^', 0, 0),
        ], 0, 0);
        $this->assertFalse(NodePredicates::isConsuming($nonConsumingSeq));
    }

    public function test_char_class_part_has_letters_for_literal(): void
    {
        $rule = new UselessIFlagRule();
        $rule->begin($this->createRuleContext('i'));

        $this->assertTrue($this->invokePrivate($rule, 'charClassPartHasLetters', [new LiteralNode('A', 0, 0)]));
    }

    public function test_lint_alternation_skips_empty_literals(): void
    {
        $alternation = new AlternationNode([
            new LiteralNode('', 0, 0),
            new LiteralNode('a', 0, 0),
        ], 0, 0);

        $issues = (new OverlappingAlternationRule())->check($alternation, $this->createRuleContext());

        $this->assertIsArray($issues);
    }

    public function test_lint_redundant_char_class_handles_ranges_and_literals(): void
    {
        $linter = new LinterNodeVisitor();

        $parts = [
            new LiteralNode('c', 0, 0),
            new RangeNode(new LiteralNode('a', 0, 0), new LiteralNode('f', 0, 0), 0, 0),
            new LiteralNode('b', 0, 0),
            new RangeNode(new LiteralNode('ab', 0, 0), new LiteralNode('z', 0, 0), 0, 0),
            new RangeNode(new LiteralNode('z', 0, 0), new LiteralNode('a', 0, 0), 0, 0),
        ];

        $charClass = new CharClassNode(new SequenceNode($parts, 0, 0), false, 0, 0);

        $issues = (new RedundantCharClassRule())->check($charClass, $this->createRuleContext());

        $this->assertNotEmpty($issues);
    }

    public function test_lint_redundant_char_class_returns_on_class_operation(): void
    {
        $operation = new ClassOperationNode(
            ClassOperationType::INTERSECTION,
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0),
            0,
            0,
        );
        $charClass = new CharClassNode($operation, false, 0, 0);

        $issues = (new RedundantCharClassRule())->check($charClass, $this->createRuleContext());

        $this->assertSame([], $issues);
    }

    public function test_collect_char_class_parts_sequence(): void
    {
        $sequence = new SequenceNode([new LiteralNode('a', 0, 0)], 0, 0);

        $parts = CharClassSets::collectParts($sequence);

        $this->assertIsArray($parts);
        $this->assertCount(1, $parts);
    }

    public function test_lint_inline_flags_branches(): void
    {
        $rule = new InlineFlagsRule();
        $context = $this->createRuleContext();

        $emptyFlags = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_INLINE_FLAGS, null, '', 0, 0);
        $this->assertSame([], $rule->check($emptyFlags, $context));

        $resetFlags = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_INLINE_FLAGS, null, '^im', 0, 0);
        $this->assertSame([], $rule->check($resetFlags, $context));

        $unsetFlag = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_INLINE_FLAGS, null, '-i', 0, 0);
        $this->assertNotEmpty($rule->check($unsetFlag, $context));
    }

    public function test_is_redundant_group_recurses_through_sequence(): void
    {
        $linter = new RedundantGroupRule();
        $sequence = new SequenceNode([new LiteralNode('a', 0, 0)], 0, 0);

        $result = $this->invokePrivate($linter, 'isRedundantGroup', [$sequence]);

        $this->assertTrue($result);
    }

    public function test_find_nested_quantifier_handles_conditional_define_and_atomic_group(): void
    {
        $rule = new NestedQuantifierRule();
        $quant = new QuantifierNode(new LiteralNode('a', 0, 0), '+', QuantifierType::T_GREEDY, 0, 0);

        $atomic = new GroupNode($quant, GroupType::T_GROUP_ATOMIC, null, null, 0, 0);
        $this->assertNull($this->invokePrivate($rule, 'findNestedQuantifier', [$atomic]));

        $conditional = new ConditionalNode(new LiteralNode('a', 0, 0), $quant, new LiteralNode('b', 0, 0), 0, 0);
        $this->assertSame($quant, $this->invokePrivate($rule, 'findNestedQuantifier', [$conditional]));

        $define = new DefineNode($quant, 0, 0);
        $this->assertSame($quant, $this->invokePrivate($rule, 'findNestedQuantifier', [$define]));
    }

    public function test_find_sequence_for_nested_quantifier_returns_null_for_non_sequence(): void
    {
        $rule = new NestedQuantifierRule();
        $quant = new QuantifierNode(new LiteralNode('a', 0, 0), '+', QuantifierType::T_GREEDY, 0, 0);

        $result = $this->invokePrivate($rule, 'findSequenceForNestedQuantifier', [new LiteralNode('a', 0, 0), $quant]);

        $this->assertNull($result);
    }

    public function test_unwrap_transparent_node_unwraps_group_and_sequence(): void
    {
        $inner = new LiteralNode('a', 0, 0);
        $group = new GroupNode($inner, GroupType::T_GROUP_NON_CAPTURING, null, null, 0, 0);
        $sequence = new SequenceNode([$group], 0, 0);

        $this->assertSame($inner, NodePredicates::unwrapTransparentNode($sequence));
    }

    public function test_is_transparent_group_false_for_lookaround(): void
    {
        $this->assertFalse(NodePredicates::isTransparentGroup(GroupType::T_GROUP_LOOKAHEAD_NEGATIVE));
    }

    public function test_is_exclusive_separator_returns_false_for_optional_or_unknown(): void
    {
        $rule = new NestedQuantifierRule();
        $context = $this->createRuleContext();
        $innerBoundary = (new CharSetAnalyzer())->firstChars(new LiteralNode('a', 0, 0));

        $optionalSeparator = new LiteralNode('', 0, 0);
        $this->assertFalse($this->invokePrivate($rule, 'isExclusiveSeparator', [$optionalSeparator, $innerBoundary, $context]));

        $unknownSeparator = new UnicodePropNode('L', true, 0, 0);
        $this->assertFalse($this->invokePrivate($rule, 'isExclusiveSeparator', [$unknownSeparator, $innerBoundary, $context]));
    }

    public function test_is_optional_node_branches(): void
    {
        $quant = new QuantifierNode(new LiteralNode('a', 0, 0), '*', QuantifierType::T_GREEDY, 0, 0);
        $this->assertTrue(NodePredicates::isOptionalNode($quant));

        $transparentGroup = new GroupNode(new LiteralNode('', 0, 0), GroupType::T_GROUP_NON_CAPTURING, null, null, 0, 0);
        $this->assertTrue(NodePredicates::isOptionalNode($transparentGroup));

        $nonTransparentGroup = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_LOOKAHEAD_POSITIVE, null, null, 0, 0);
        $this->assertTrue(NodePredicates::isOptionalNode($nonTransparentGroup));

        $sequence = new SequenceNode([new LiteralNode('', 0, 0)], 0, 0);
        $this->assertTrue(NodePredicates::isOptionalNode($sequence));

        $alternation = new AlternationNode([new LiteralNode('', 0, 0)], 0, 0);
        $this->assertTrue(NodePredicates::isOptionalNode($alternation));

        $conditional = new ConditionalNode(new LiteralNode('a', 0, 0), new LiteralNode('', 0, 0), new LiteralNode('b', 0, 0), 0, 0);
        $this->assertTrue(NodePredicates::isOptionalNode($conditional));

        $anchor = new AnchorNode('^', 0, 0);
        $this->assertTrue(NodePredicates::isOptionalNode($anchor));
    }

    public function test_is_optional_node_returns_false_for_non_optional_sequence(): void
    {
        $sequence = new SequenceNode([new LiteralNode('a', 0, 0), new LiteralNode('', 0, 0)], 0, 0);

        $this->assertFalse(NodePredicates::isOptionalNode($sequence));
    }

    public function test_is_optional_node_returns_false_for_non_optional_alternation(): void
    {
        $alternation = new AlternationNode([new LiteralNode('a', 0, 0), new LiteralNode('b', 0, 0)], 0, 0);

        $this->assertFalse(NodePredicates::isOptionalNode($alternation));
    }

    public function test_is_safely_separated_nested_quantifier_returns_false_for_unknown_boundary(): void
    {
        $rule = new NestedQuantifierRule();
        $context = $this->createRuleContext('u');

        $nested = new QuantifierNode(new CharTypeNode('d', 0, 0), '+', QuantifierType::T_GREEDY, 0, 0);
        $sequence = new SequenceNode([$nested], 0, 0);
        $outer = new QuantifierNode($sequence, '+', QuantifierType::T_GREEDY, 0, 0);

        $result = $this->invokePrivate($rule, 'isSafelySeparatedNestedQuantifier', [$outer, $nested, $context]);

        $this->assertFalse($result);
    }

    public function test_is_safely_separated_nested_quantifier_checks_next_neighbor(): void
    {
        $rule = new NestedQuantifierRule();
        $context = $this->createRuleContext();
        $nested = new QuantifierNode(new LiteralNode('a', 0, 0), '+', QuantifierType::T_GREEDY, 0, 0);
        $sequence = new SequenceNode([$nested, new LiteralNode('b', 0, 0)], 0, 0);
        $outer = new QuantifierNode($sequence, '+', QuantifierType::T_GREEDY, 0, 0);

        $result = $this->invokePrivate($rule, 'isSafelySeparatedNestedQuantifier', [$outer, $nested, $context]);

        $this->assertIsBool($result);
    }

    public function test_find_sequence_for_nested_quantifier_returns_null_when_not_found(): void
    {
        $rule = new NestedQuantifierRule();
        $nested = new QuantifierNode(new LiteralNode('a', 0, 0), '+', QuantifierType::T_GREEDY, 0, 0);
        $sequence = new SequenceNode([new LiteralNode('b', 0, 0)], 0, 0);

        $result = $this->invokePrivate($rule, 'findSequenceForNestedQuantifier', [$sequence, $nested]);

        $this->assertNull($result);
    }

    public function test_contains_dot_star_branches(): void
    {
        $rule = new NestedDotStarRule();
        $dotStar = new QuantifierNode(new DotNode(0, 0), '*', QuantifierType::T_GREEDY, 0, 0);

        $sequence = new SequenceNode([$dotStar], 0, 0);
        $this->assertTrue($this->invokePrivate($rule, 'containsDotStar', [$sequence]));

        $alternation = new AlternationNode([$dotStar], 0, 0);
        $this->assertTrue($this->invokePrivate($rule, 'containsDotStar', [$alternation]));

        $conditional = new ConditionalNode(new LiteralNode('a', 0, 0), $dotStar, new LiteralNode('b', 0, 0), 0, 0);
        $this->assertTrue($this->invokePrivate($rule, 'containsDotStar', [$conditional]));

        $define = new DefineNode($dotStar, 0, 0);
        $this->assertTrue($this->invokePrivate($rule, 'containsDotStar', [$define]));
    }

    private function createRuleContext(string $flags = ''): LintContext
    {
        return new LintContext(
            new PatternInfo($flags, '/', '', str_contains($flags, 'u'), class_exists(\IntlChar::class)),
            new GroupIndex(0, [], [], [], false),
            new CharSetAnalyzer($flags),
        );
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

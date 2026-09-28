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
use RegexParser\Exception\SemanticErrorException;
use RegexParser\GroupNumbering;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\ClassOperationType;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\NodeVisitor\ValidatorNodeVisitor;
use RegexParser\Regex;

final class ValidatorNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_clear_caches_resets_static_state(): void
    {
        $ref = new \ReflectionClass(ValidatorNodeVisitor::class);

        $unicodePropCache = $ref->getProperty('unicodePropCache');
        $unicodePropCache->setValue(null, ['p{X}' => true]);

        $quantifierBoundsCache = $ref->getProperty('quantifierBoundsCache');
        $quantifierBoundsCache->setValue(null, ['{1,2}' => [1, 2]]);

        ValidatorNodeVisitor::clearCaches();

        $this->assertSame([], $unicodePropCache->getValue());
        $this->assertSame([], $quantifierBoundsCache->getValue());
    }

    public function test_invalid_assertion_raises_error(): void
    {
        $validator = new ValidatorNodeVisitor();
        $node = new AssertionNode('X', 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid assertion');

        $node->accept($validator);
    }

    public function test_range_invalid_start_length(): void
    {
        $validator = new ValidatorNodeVisitor();
        $range = new RangeNode(new LiteralNode('ab', 0, 0), new LiteralNode('c', 0, 0), 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('start char must be a single character');

        $range->accept($validator);
    }

    public function test_range_invalid_end_length(): void
    {
        $validator = new ValidatorNodeVisitor();
        $range = new RangeNode(new LiteralNode('a', 0, 0), new LiteralNode('bc', 0, 0), 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('end char must be a single character');

        $range->accept($validator);
    }

    public function test_backref_zero_variants_are_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference \\0 is not valid.');
        (new BackrefNode('\\0', 0, 0))->accept($validator);
    }

    public function test_backref_zero_without_slash_is_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference 0 is not valid.');
        (new BackrefNode('0', 0, 0))->accept($validator);
    }

    public function test_backref_missing_named_group_is_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('non-existent named group');
        (new BackrefNode('\\k<missing>', 0, 0))->accept($validator);
    }

    public function test_backref_bare_name_missing_group_is_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('non-existent named group');
        (new BackrefNode('missing', 0, 0))->accept($validator);
    }

    public function test_backref_missing_group_is_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('non-existent group');
        (new BackrefNode('\\g{2}', 0, 0))->accept($validator);
    }

    public function test_backref_relative_zero_is_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();
        $method = (new \ReflectionClass($validator))->getMethod('assertRelativeReferenceExists');

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('relative reference cannot be zero');

        $method->invoke($validator, 0, 0, 'regex.backref.relative', 'Backreference');
    }

    public function test_valid_backref_returns_cleanly(): void
    {
        $regex = Regex::create();
        $result = $regex->validate('/(a)\\g{1}/');

        $this->assertTrue($result->isValid);
    }

    public function test_control_char_out_of_range_is_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();
        $node = new ControlCharNode('A', 0x1FF, 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid control character');

        $node->accept($validator);
    }

    public function test_invalid_conditional_is_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();
        $node = new ConditionalNode(
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0),
            new LiteralNode('c', 0, 0),
            0,
            0,
        );

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid conditional construct');

        $node->accept($validator);
    }

    public function test_conditional_accepts_lookaround_condition(): void
    {
        $validator = new ValidatorNodeVisitor();
        $lookahead = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_LOOKAHEAD_POSITIVE, null, null, 0, 0);
        $node = new ConditionalNode(
            $lookahead,
            new LiteralNode('b', 0, 0),
            new LiteralNode('c', 0, 0),
            0,
            0,
        );

        $node->accept($validator);
        $this->expectNotToPerformAssertions();
    }

    public function test_conditional_accepts_define_condition(): void
    {
        $validator = new ValidatorNodeVisitor();
        $defineAssertion = new AssertionNode('DEFINE', 0, 0);
        $node = new ConditionalNode(
            $defineAssertion,
            new LiteralNode('b', 0, 0),
            new LiteralNode('c', 0, 0),
            0,
            0,
        );

        $this->expectException(SemanticErrorException::class);
        $node->accept($validator);
    }

    public function test_conditional_subroutine_branch_hits_reference_check(): void
    {
        $validator = new ValidatorNodeVisitor();
        $node = new ConditionalNode(
            new SubroutineNode('R-1', 'R-1', 0, 0),
            new LiteralNode('b', 0, 0),
            new LiteralNode('c', 0, 0),
            0,
            0,
        );

        $this->expectException(SemanticErrorException::class);
        $node->accept($validator);
    }

    public function test_conditional_subroutine_accepts_numeric_reference(): void
    {
        $validator = new ValidatorNodeVisitor();
        $node = new ConditionalNode(
            new SubroutineNode('1', '1', 0, 0),
            new LiteralNode('b', 0, 0),
            new LiteralNode('c', 0, 0),
            0,
            0,
        );

        $this->expectException(SemanticErrorException::class);
        $node->accept($validator);
    }

    public function test_subroutine_reference_variants_hit_branches(): void
    {
        $validator = new ValidatorNodeVisitor();

        (new SubroutineNode('R', 'R', 0, 0))->accept($validator);

        $this->expectException(SemanticErrorException::class);
        (new SubroutineNode('R1', 'R1', 0, 0))->accept($validator);
    }

    public function test_subroutine_absolute_recursion_reference_returns(): void
    {
        $validator = new ValidatorNodeVisitor();
        $this->setPrivateProperty($validator, 'groupNumbering', new GroupNumbering(1, [1], []));

        (new SubroutineNode('R1', 'R1', 0, 0))->accept($validator);
        $this->expectNotToPerformAssertions();
    }

    public function test_subroutine_relative_recursion_reference_returns(): void
    {
        $validator = new ValidatorNodeVisitor();
        $this->setPrivateProperty($validator, 'groupNumbering', new GroupNumbering(1, [1], []));
        $this->setPrivateProperty($validator, 'captureSequence', [1]);
        $this->setPrivateProperty($validator, 'captureIndex', 1);

        (new SubroutineNode('R-1', 'R-1', 0, 0))->accept($validator);
        $this->expectNotToPerformAssertions();
    }

    public function test_subroutine_relative_reference_hits_branch(): void
    {
        $validator = new ValidatorNodeVisitor();

        $this->expectException(SemanticErrorException::class);
        (new SubroutineNode('R-1', 'R-1', 0, 0))->accept($validator);
    }

    public function test_subroutine_zero_reference_returns(): void
    {
        $validator = new ValidatorNodeVisitor();
        (new SubroutineNode('0', '0', 0, 0))->accept($validator);
        $this->expectNotToPerformAssertions();
    }

    public function test_subroutine_negative_zero_reference_returns(): void
    {
        // preg_match() on PCRE2 10.48: "a relative value of zero is not allowed at offset 5" for "x(?-0)y".
        $this->expectException(SemanticErrorException::class);

        $validator = new ValidatorNodeVisitor();
        (new SubroutineNode('-0', '-0', 0, 0))->accept($validator);
    }

    public function test_define_and_limit_match_nodes_are_noops(): void
    {
        $validator = new ValidatorNodeVisitor();
        $define = new DefineNode(new LiteralNode('a', 0, 0), 0, 0);
        $limit = new LimitMatchNode(10, 0, 0);

        $define->accept($validator);
        $limit->accept($validator);
        $this->expectNotToPerformAssertions();
    }

    public function test_octal_legacy_out_of_range_is_rejected(): void
    {
        $validator = new ValidatorNodeVisitor();
        $node = new CharLiteralNode('\\777', 0x200, CharLiteralType::OCTAL_LEGACY, 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid legacy octal codepoint');

        $node->accept($validator);
    }

    public function test_calculate_fixed_length_helpers(): void
    {
        $validator = new ValidatorNodeVisitor();
        $method = (new \ReflectionClass($validator))->getMethod('calculateFixedLength');

        $this->assertSame(1, $method->invoke($validator, new LiteralNode('a', 0, 0)));
        $this->assertSame(1, $method->invoke($validator, new CharTypeNode('d', 0, 0)));
        $this->assertSame(1, $method->invoke($validator, new DotNode(0, 0)));
        $this->assertSame(0, $method->invoke($validator, new AssertionNode('A', 0, 0)));

        $sequence = new SequenceNode([new LiteralNode('a', 0, 0), new LiteralNode('b', 0, 0)], 0, 0);
        $this->assertSame(2, $method->invoke($validator, $sequence));

        $group = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_CAPTURING, null, null, 0, 0);
        $this->assertSame(1, $method->invoke($validator, $group));

        $quantifier = new QuantifierNode(new LiteralNode('a', 0, 0), '{2}', QuantifierType::T_GREEDY, 0, 0);
        $this->assertSame(2, $method->invoke($validator, $quantifier));

        $charClass = new CharClassNode(new LiteralNode('a', 0, 0), false, 0, 0);
        $this->assertSame(1, $method->invoke($validator, $charClass));

        $alternation = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0),
        ], 0, 0);
        $this->assertNull($method->invoke($validator, $alternation));
    }

    public function test_calculate_sequence_length_variable_returns_null(): void
    {
        $validator = new ValidatorNodeVisitor();
        $method = (new \ReflectionClass($validator))->getMethod('calculateSequenceLength');

        $sequence = new SequenceNode([
            new LiteralNode('a', 0, 0),
            new QuantifierNode(new LiteralNode('b', 0, 0), '*', QuantifierType::T_GREEDY, 0, 0),
        ], 0, 0);

        $this->assertNull($method->invoke($validator, $sequence));
    }

    public function test_calculate_quantifier_length_variable_returns_null(): void
    {
        $validator = new ValidatorNodeVisitor();
        $method = (new \ReflectionClass($validator))->getMethod('calculateQuantifierLength');

        $quantifier = new QuantifierNode(new LiteralNode('a', 0, 0), '*', QuantifierType::T_GREEDY, 0, 0);
        $this->assertNull($method->invoke($validator, $quantifier));
    }

    public function test_calculate_quantifier_length_child_null_returns_null(): void
    {
        $validator = new ValidatorNodeVisitor();
        $method = (new \ReflectionClass($validator))->getMethod('calculateQuantifierLength');

        $child = new AlternationNode([new LiteralNode('a', 0, 0), new LiteralNode('b', 0, 0)], 0, 0);
        $quantifier = new QuantifierNode($child, '{2}', QuantifierType::T_GREEDY, 0, 0);

        $this->assertNull($method->invoke($validator, $quantifier));
    }

    public function test_calculate_quantifier_length_fixed_returns_value(): void
    {
        $validator = new ValidatorNodeVisitor();
        $method = (new \ReflectionClass($validator))->getMethod('calculateQuantifierLength');

        $quantifier = new QuantifierNode(new LiteralNode('a', 0, 0), '{3}', QuantifierType::T_GREEDY, 0, 0);
        $this->assertSame(3, $method->invoke($validator, $quantifier));
    }

    public function test_quantifier_bounds_cache_eviction_runs(): void
    {
        $validator = new ValidatorNodeVisitor();
        $ref = new \ReflectionClass(ValidatorNodeVisitor::class);
        $quantifierCache = $ref->getProperty('quantifierBoundsCache');
        $cache = [];
        for ($i = 0; $i < 1000; $i++) {
            $cache['{'.$i.',}'] = [$i, -1];
        }
        $quantifierCache->setValue(null, $cache);

        $method = $ref->getMethod('getQuantifierBounds');

        $bounds = $method->invoke($validator, '{2,3}');
        $this->assertSame([2, 3], $bounds);
    }

    public function test_find_unbounded_lookbehind_node_traverses_branches(): void
    {
        $validator = new ValidatorNodeVisitor();
        $method = (new \ReflectionClass($validator))->getMethod('findUnboundedLookbehindNode');

        $group = new GroupNode(new BackrefNode('1', 0, 0), GroupType::T_GROUP_CAPTURING, null, null, 0, 0);
        $this->assertInstanceOf(BackrefNode::class, $method->invoke($validator, $group));

        $alternation = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new QuantifierNode(new LiteralNode('b', 0, 0), '*', QuantifierType::T_GREEDY, 0, 0),
        ], 0, 0);
        $this->assertInstanceOf(QuantifierNode::class, $method->invoke($validator, $alternation));

        $conditional = new ConditionalNode(
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0),
            new BackrefNode('1', 0, 0),
            0,
            0,
        );
        $this->assertInstanceOf(BackrefNode::class, $method->invoke($validator, $conditional));

        $define = new DefineNode(new BackrefNode('1', 0, 0), 0, 0);
        $this->assertInstanceOf(BackrefNode::class, $method->invoke($validator, $define));

        $classOperation = new ClassOperationNode(
            ClassOperationType::SUBTRACTION,
            new BackrefNode('1', 0, 0),
            new LiteralNode('a', 0, 0),
            0,
            0,
        );
        $this->assertInstanceOf(BackrefNode::class, $method->invoke($validator, $classOperation));

        $range = new RangeNode(new BackrefNode('1', 0, 0), new LiteralNode('b', 0, 0), 0, 0);
        $this->assertInstanceOf(BackrefNode::class, $method->invoke($validator, $range));
    }

    public function test_unbounded_lookbehind_with_backref_has_generic_hint(): void
    {
        $regex = Regex::create();

        // preg_match() on PCRE2 10.48 and 10.40 compiles "(a)(?<=\1)b": the
        // reference is as long as its group, one character.
        $this->assertTrue($regex->validate('/(a)(?<=\\1)b/')->isValid);

        $result = $regex->validate('/(a+)(?<=\\1)b/');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('bounded maximum length', (string) $result->error);
    }

    public function test_lookbehind_limit_exceeded(): void
    {
        // The limit only bounds a variable-length lookbehind, so the one
        // here has to vary: "ab?". It is set through max_lookbehind_length;
        // PHP refuses the (*LIMIT_LOOKBEHIND=n) verb.
        $regex = Regex::create(['max_lookbehind_length' => 1]);
        $result = $regex->validate('/(?<=ab?)c/');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Lookbehind exceeds the maximum length', (string) $result->error);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setValue($object, $value);
    }
}

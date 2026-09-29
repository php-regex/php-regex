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
use Random\Engine;
use Random\Randomizer;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\NodeVisitor\SampleGeneratorNodeVisitor;

final class SampleGeneratorNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_range_fallbacks_for_non_literal_nodes(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $range = new RangeNode(new CharTypeNode('d', 0, 0), new LiteralNode('z', 0, 0), 0, 0);

        $result = $range->accept($generator);

        $this->assertNotSame('', $result);
    }

    public function test_range_ord_fallback_returns_start_value(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $range = new RangeNode(new LiteralNode('z', 0, 0), new LiteralNode('a', 0, 0), 0, 0);

        $result = $range->accept($generator);

        $this->assertSame('z', $result);
    }

    public function test_backref_returns_captured_values(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $ref = new \ReflectionClass($generator);
        $captures = $ref->getProperty('captures');
        $captures->setValue($generator, [1 => 'match1', 'name' => 'named']);

        $numeric = (new BackrefNode('1', 0, 0))->accept($generator);
        $named = (new BackrefNode('name', 0, 0))->accept($generator);

        $this->assertSame('match1', $numeric);
        $this->assertSame('named', $named);
    }

    public function test_range_with_empty_start_returns_single_character(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $range = new RangeNode(new LiteralNode('', 0, 0), new LiteralNode('a', 0, 0), 0, 0);

        $result = $range->accept($generator);

        $this->assertSame(1, \strlen($result));
    }

    public function test_control_char_handles_out_of_range_and_valid_values(): void
    {
        $generator = new SampleGeneratorNodeVisitor();

        $this->assertSame('?', (new ControlCharNode('A', 0x1FF, 0, 0))->accept($generator));
        $this->assertSame('A', (new ControlCharNode('A', 0x41, 0, 0))->accept($generator));
    }

    public function test_char_literal_out_of_range_returns_question(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $result = (new CharLiteralNode('\\x{110000}', 0x110000, CharLiteralType::UNICODE, 0, 0))->accept($generator);

        $this->assertSame('?', $result);
    }

    public function test_subroutine_recursion_depth_returns_empty(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $maxDepth = (new \ReflectionClass(SampleGeneratorNodeVisitor::class))->getConstant('MAX_RECURSION_DEPTH');

        $this->setPrivate($generator, 'recursionDepth', $maxDepth);
        $this->setPrivate($generator, 'rootPattern', new LiteralNode('a', 0, 0));

        $result = (new SubroutineNode('1', '1', 0, 0))->accept($generator);

        $this->assertSame('', $result);
    }

    public function test_subroutine_unresolved_reference_throws(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $this->setPrivate($generator, 'rootPattern', new LiteralNode('a', 0, 0));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Sample generation for subroutines is not supported.');

        (new SubroutineNode('99', '99', 0, 0))->accept($generator);
    }

    public function test_define_limit_match_and_callout_return_empty(): void
    {
        $generator = new SampleGeneratorNodeVisitor();

        $this->assertSame('', (new DefineNode(new LiteralNode('a', 0, 0), 0, 0))->accept($generator));
        $this->assertSame('', (new LimitMatchNode(10, 0, 0))->accept($generator));
        $this->assertSame('', (new CalloutNode('callout', true, 0, 0))->accept($generator));
        $this->assertSame('', (new PcreVerbNode('FAIL', 0, 0))->accept($generator));
    }

    public function test_parse_quantifier_range_adjusts_when_max_less_than_min(): void
    {
        $generator = new SampleGeneratorNodeVisitor();

        $range = $this->invokePrivate($generator, 'parseQuantifierRange', ['{5,2}']);

        $this->assertSame([5, 5], $range);
    }

    public function test_random_int_falls_back_on_exception(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $engine = new class implements Engine {
            public function generate(): string
            {
                throw new \RuntimeException('random failed');
            }
        };
        $this->setPrivate($generator, 'randomizer', new Randomizer($engine));

        $value = $this->invokePrivate($generator, 'randomInt', [5, 1]);

        $this->assertSame(5, $value);
    }

    public function test_apply_lookaround_hints_skips_empty_prefix_suffix(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $this->setPrivate($generator, 'requiredPrefixes', ['', 'pre']);
        $this->setPrivate($generator, 'requiredSuffixes', ['', 'suf']);

        $result = $this->invokePrivate($generator, 'applyLookaroundHints', ['value']);

        $this->assertSame('prevaluesuf', $result);
    }

    public function test_is_condition_satisfied_branches(): void
    {
        $generator = new SampleGeneratorNodeVisitor();

        // A lookaround may hold or not, depending on the text around the
        // sample: over enough tries, both branches are taken.
        $negative = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_LOOKAHEAD_NEGATIVE, null, null, 0, 0);
        $positive = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_LOOKAHEAD_POSITIVE, null, null, 0, 0);
        $generator->setSeed(7);
        $taken = ['negative' => [], 'positive' => []];
        for ($try = 0; $try < 32; $try++) {
            foreach (['negative' => $negative, 'positive' => $positive] as $kind => $condition) {
                $satisfied = $this->invokePrivate($generator, 'isConditionSatisfied', [$condition]);
                $this->assertIsBool($satisfied);
                $taken[$kind][$satisfied ? 'yes' : 'no'] = true;
            }
        }
        $this->assertCount(2, $taken['negative']);
        $this->assertCount(2, $taken['positive']);

        $nonLookaround = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_NON_CAPTURING, null, null, 0, 0);
        $this->assertTrue($this->invokePrivate($generator, 'isConditionSatisfied', [$nonLookaround]));

        $assertion = new AssertionNode('A', 0, 0);
        $this->assertTrue($this->invokePrivate($generator, 'isConditionSatisfied', [$assertion]));

        $fallback = $this->invokePrivate($generator, 'isConditionSatisfied', [new LiteralNode('b', 0, 0)]);
        $this->assertIsBool($fallback);
    }

    public function test_has_capture_for_reference_branches(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $this->setPrivate($generator, 'captures', [2 => 'value', 'name' => 'named']);

        $this->assertTrue($this->invokePrivate($generator, 'hasCaptureForReference', ['name']));
        $this->assertTrue($this->invokePrivate($generator, 'hasCaptureForReference', ['\\2']));
        $this->assertFalse($this->invokePrivate($generator, 'hasCaptureForReference', ['missing']));
    }

    public function test_collect_groups_handles_define(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $group = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_CAPTURING, null, null, 0, 0);
        $define = new DefineNode($group, 0, 0);

        $this->invokePrivate($generator, 'collectGroups', [$define]);

        $map = $this->getPrivate($generator, 'groupIndexMap');
        $this->assertNotEmpty($map);
    }

    public function test_resolve_subroutine_target_reference_cases(): void
    {
        $generator = new SampleGeneratorNodeVisitor();
        $root = new LiteralNode('root', 0, 4);
        $this->setPrivate($generator, 'rootPattern', $root);

        // "(x)(a)(b)(?-1)": a relative call counts the groups opened before it.
        $groupFirst = new GroupNode(new LiteralNode('x', 1, 2), GroupType::T_GROUP_CAPTURING, null, null, 0, 3);
        $groupOne = new GroupNode(new LiteralNode('a', 4, 5), GroupType::T_GROUP_CAPTURING, null, null, 3, 6);
        $groupThree = new GroupNode(new LiteralNode('b', 7, 8), GroupType::T_GROUP_CAPTURING, null, null, 6, 9);
        $this->setPrivate($generator, 'groupIndexMap', [1 => $groupFirst, 2 => $groupOne, 3 => $groupThree]);

        $numeric = $this->invokePrivate($generator, 'resolveSubroutineTarget', [new SubroutineNode('2', '2', 9, 13)]);
        $this->assertSame($groupOne, $numeric);

        $negative = $this->invokePrivate($generator, 'resolveSubroutineTarget', [new SubroutineNode('-1', '-1', 9, 13)]);
        $this->assertSame($groupThree, $negative);

        $nullNegative = $this->invokePrivate($generator, 'resolveSubroutineTarget', [new SubroutineNode('-1', '-1', 0, 0)]);
        $this->assertNull($nullNegative);

        $rootReturn = $this->invokePrivate($generator, 'resolveSubroutineTarget', [new SubroutineNode('R', 'R', 0, 0)]);
        $this->assertSame($root, $rootReturn);
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

    private function setPrivate(object $target, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($target, $property);
        $ref->setValue($target, $value);
    }

    private function getPrivate(object $target, string $property): mixed
    {
        $ref = new \ReflectionProperty($target, $property);

        return $ref->getValue($target);
    }
}

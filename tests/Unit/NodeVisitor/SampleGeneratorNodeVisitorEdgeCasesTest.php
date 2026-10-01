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

use PhpRegex\Generator\SampleGenerationException;
use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PHPUnit\Framework\TestCase;
use Random\Engine;
use Random\Randomizer;

final class SampleGeneratorNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_range_fallbacks_for_non_literal_nodes(): void
    {
        $generator = new SampleGenerator();
        $range = new RangeNode(new CharTypeNode('d', 0, 0), new LiteralNode('z', 0, 0), 0, 0);

        $result = $range->accept($generator);

        $this->assertNotSame('', $result);
    }

    public function test_range_ord_fallback_returns_start_value(): void
    {
        $generator = new SampleGenerator();
        $range = new RangeNode(new LiteralNode('z', 0, 0), new LiteralNode('a', 0, 0), 0, 0);

        $result = $range->accept($generator);

        $this->assertSame('z', $result);
    }

    public function test_backref_returns_captured_values(): void
    {
        $generator = new SampleGenerator();
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
        $generator = new SampleGenerator();
        $range = new RangeNode(new LiteralNode('', 0, 0), new LiteralNode('a', 0, 0), 0, 0);

        $result = $range->accept($generator);

        $this->assertSame(1, \strlen($result));
    }

    public function test_control_char_handles_out_of_range_and_valid_values(): void
    {
        $generator = new SampleGenerator();

        $this->assertSame('?', (new ControlCharNode('A', 0x1FF, 0, 0))->accept($generator));
        $this->assertSame('A', (new ControlCharNode('A', 0x41, 0, 0))->accept($generator));
    }

    public function test_char_literal_out_of_range_returns_question(): void
    {
        $generator = new SampleGenerator();
        $result = (new CharLiteralNode('\\x{110000}', 0x110000, CharLiteralType::Unicode, 0, 0))->accept($generator);

        $this->assertSame('?', $result);
    }

    public function test_subroutine_recursion_depth_returns_empty(): void
    {
        $generator = new SampleGenerator();
        $maxDepth = (new \ReflectionClass(SampleGenerator::class))->getConstant('MAX_RECURSION_DEPTH');

        $this->setPrivate($generator, 'recursionDepth', $maxDepth);
        $this->setPrivate($generator, 'rootPattern', new LiteralNode('a', 0, 0));

        $result = (new SubroutineNode('1', '1', 0, 0))->accept($generator);

        $this->assertSame('', $result);
    }

    public function test_subroutine_unresolved_reference_throws(): void
    {
        $generator = new SampleGenerator();
        $this->setPrivate($generator, 'rootPattern', new LiteralNode('a', 0, 0));

        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessage('Sample generation for subroutines is not supported.');

        (new SubroutineNode('99', '99', 0, 0))->accept($generator);
    }

    public function test_define_limit_match_and_callout_return_empty(): void
    {
        $generator = new SampleGenerator();

        $this->assertSame('', (new DefineNode(new LiteralNode('a', 0, 0), 0, 0))->accept($generator));
        $this->assertSame('', (new LimitMatchNode(10, 0, 0))->accept($generator));
        $this->assertSame('', (new CalloutNode('callout', true, 0, 0))->accept($generator));
        $this->assertSame('', (new PcreVerbNode('FAIL', 0, 0))->accept($generator));
    }

    public function test_parse_quantifier_range_adjusts_when_max_less_than_min(): void
    {
        $generator = new SampleGenerator();

        $range = $this->invokePrivate($generator, 'parseQuantifierRange', ['{5,2}']);

        $this->assertSame([5, 5], $range);
    }

    public function test_random_int_falls_back_on_exception(): void
    {
        $generator = new SampleGenerator();
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
        $generator = new SampleGenerator();
        $this->setPrivate($generator, 'requiredPrefixes', ['', 'pre']);
        $this->setPrivate($generator, 'requiredSuffixes', ['', 'suf']);

        $result = $this->invokePrivate($generator, 'applyLookaroundHints', ['value']);

        $this->assertSame('prevaluesuf', $result);
    }

    public function test_is_condition_satisfied_branches(): void
    {
        $generator = new SampleGenerator();

        // A lookaround may hold or not, depending on the text around the
        // sample: over enough tries, both branches are taken.
        $negative = new GroupNode(new LiteralNode('a', 0, 0), GroupType::LookaheadNegative, null, null, 0, 0);
        $positive = new GroupNode(new LiteralNode('a', 0, 0), GroupType::LookaheadPositive, null, null, 0, 0);
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

        $nonLookaround = new GroupNode(new LiteralNode('a', 0, 0), GroupType::NonCapturing, null, null, 0, 0);
        $this->assertTrue($this->invokePrivate($generator, 'isConditionSatisfied', [$nonLookaround]));

        $assertion = new AssertionNode('A', 0, 0);
        $this->assertTrue($this->invokePrivate($generator, 'isConditionSatisfied', [$assertion]));

        $fallback = $this->invokePrivate($generator, 'isConditionSatisfied', [new LiteralNode('b', 0, 0)]);
        $this->assertIsBool($fallback);
    }

    public function test_a_version_condition_is_judged_as_each_release_reads_it(): void
    {
        // pcre2test: before 10.47 a one-digit minor counts tens ("10.5" is
        // 10.50, "10.4" is 10.40); from 10.47 it is read whole.
        $holds = static fn (string $operator, string $version, int $major, int $minor): bool => true === (new \ReflectionMethod(SampleGenerator::class, 'versionConditionHolds'))
            ->invoke(null, new VersionConditionNode($operator, $version, 0, 0), $major, $minor);

        $this->assertFalse($holds('>=', '10.5', 10, 42));
        $this->assertFalse($holds('>=', '10.5', 10, 46));
        $this->assertTrue($holds('>=', '10.5', 10, 47));
        $this->assertTrue($holds('>=', '10.5', 10, 49));
        $this->assertTrue($holds('>=', '10.05', 10, 42));
        $this->assertTrue($holds('=', '10.4', 10, 40));
        $this->assertFalse($holds('=', '10.4', 10, 47));
        $this->assertTrue($holds('>=', '9.99', 10, 40));
        $this->assertFalse($holds('>=', '11', 10, 49));
    }

    public function test_has_capture_for_reference_branches(): void
    {
        $generator = new SampleGenerator();
        $this->setPrivate($generator, 'captures', [2 => 'value', 'name' => 'named']);

        $this->assertTrue($this->invokePrivate($generator, 'hasCaptureForReference', ['name']));
        $this->assertTrue($this->invokePrivate($generator, 'hasCaptureForReference', ['\\2']));
        $this->assertFalse($this->invokePrivate($generator, 'hasCaptureForReference', ['missing']));
    }

    public function test_collect_groups_handles_define(): void
    {
        $generator = new SampleGenerator();
        $group = new GroupNode(new LiteralNode('a', 0, 0), GroupType::Capturing, null, null, 0, 0);
        $define = new DefineNode($group, 0, 0);

        $this->invokePrivate($generator, 'collectGroups', [$define]);

        $map = $this->getPrivate($generator, 'groupIndexMap');
        $this->assertNotEmpty($map);
    }

    public function test_resolve_subroutine_target_reference_cases(): void
    {
        $generator = new SampleGenerator();
        $root = new LiteralNode('root', 0, 4);
        $this->setPrivate($generator, 'rootPattern', $root);

        // "(x)(a)(b)(?-1)": a relative call counts the groups opened before it.
        $groupFirst = new GroupNode(new LiteralNode('x', 1, 2), GroupType::Capturing, null, null, 0, 3);
        $groupOne = new GroupNode(new LiteralNode('a', 4, 5), GroupType::Capturing, null, null, 3, 6);
        $groupThree = new GroupNode(new LiteralNode('b', 7, 8), GroupType::Capturing, null, null, 6, 9);
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

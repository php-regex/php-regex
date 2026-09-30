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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RegexParser\GroupNumberingCollector;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\SequenceNode;

final class GroupNumberingCollectorTest extends TestCase
{
    public function test_collect_basic_capturing_groups(): void
    {
        $literal = new LiteralNode('test', 0, 4);
        $group = new GroupNode($literal, GroupType::T_GROUP_CAPTURING, null, null, 0, 4);
        $sequence = new SequenceNode([$group], 0, 4);
        $root = new RegexNode($sequence, '', '/', 0, 4);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(1, $result->maxGroupNumber);
        $this->assertSame([1], $result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_named_groups(): void
    {
        $literal = new LiteralNode('test', 0, 4);
        $group = new GroupNode($literal, GroupType::T_GROUP_NAMED, 'name', null, 0, 4);
        $sequence = new SequenceNode([$group], 0, 4);
        $root = new RegexNode($sequence, '', '/', 0, 4);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(1, $result->maxGroupNumber);
        $this->assertSame([1], $result->captureSequence);
        $this->assertArrayHasKey('name', $result->namedGroups);
        $this->assertSame([1], $result->namedGroups['name']);
    }

    public function test_collect_multiple_capturing_groups(): void
    {
        $literal1 = new LiteralNode('a', 0, 1);
        $literal2 = new LiteralNode('b', 1, 2);
        $group1 = new GroupNode($literal1, GroupType::T_GROUP_CAPTURING, null, null, 0, 1);
        $group2 = new GroupNode($literal2, GroupType::T_GROUP_CAPTURING, null, null, 1, 2);
        $sequence = new SequenceNode([$group1, $group2], 0, 2);
        $root = new RegexNode($sequence, '', '/', 0, 2);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(2, $result->maxGroupNumber);
        $this->assertSame([1, 2], $result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_branch_reset_groups(): void
    {
        $literal1 = new LiteralNode('a', 0, 1);
        $literal2 = new LiteralNode('b', 1, 2);
        $group1 = new GroupNode($literal1, GroupType::T_GROUP_CAPTURING, null, null, 0, 1);
        $group2 = new GroupNode($literal2, GroupType::T_GROUP_CAPTURING, null, null, 1, 2);

        $alternation = new AlternationNode([$group1, $group2], 0, 2);
        $branchReset = new GroupNode($alternation, GroupType::T_GROUP_BRANCH_RESET, null, null, 0, 2);
        $sequence = new SequenceNode([$branchReset], 0, 2);
        $root = new RegexNode($sequence, '', '/', 0, 2);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(1, $result->maxGroupNumber);
        $this->assertSame([1, 1], $result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_with_alternation(): void
    {
        $literal1 = new LiteralNode('a', 0, 1);
        $literal2 = new LiteralNode('b', 1, 2);
        $group1 = new GroupNode($literal1, GroupType::T_GROUP_CAPTURING, null, null, 0, 1);
        $group2 = new GroupNode($literal2, GroupType::T_GROUP_CAPTURING, null, null, 1, 2);

        $alternation = new AlternationNode([$group1, $group2], 0, 2);
        $sequence = new SequenceNode([$alternation], 0, 2);
        $root = new RegexNode($sequence, '', '/', 0, 2);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(2, $result->maxGroupNumber);
        $this->assertSame([1, 2], $result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_with_quantifier(): void
    {
        $literal = new LiteralNode('a', 0, 1);
        $quantifier = new QuantifierNode($literal, '+', QuantifierType::T_GREEDY, 0, 1);
        $group = new GroupNode($quantifier, GroupType::T_GROUP_CAPTURING, null, null, 0, 1);
        $sequence = new SequenceNode([$group], 0, 1);
        $root = new RegexNode($sequence, '', '/', 0, 1);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(1, $result->maxGroupNumber);
        $this->assertSame([1], $result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_with_conditional(): void
    {
        $condition = new LiteralNode('x', 0, 1);
        $yes = new LiteralNode('y', 1, 2);
        $no = new LiteralNode('z', 2, 3);
        $conditional = new ConditionalNode($condition, $yes, $no, 0, 3);

        $group = new GroupNode($conditional, GroupType::T_GROUP_CAPTURING, null, null, 0, 3);
        $sequence = new SequenceNode([$group], 0, 3);
        $root = new RegexNode($sequence, '', '/', 0, 3);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(1, $result->maxGroupNumber);
        $this->assertSame([1], $result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_with_define(): void
    {
        $content = new LiteralNode('test', 0, 4);
        $define = new DefineNode($content, 0, 4);
        $sequence = new SequenceNode([$define], 0, 4);
        $root = new RegexNode($sequence, '', '/', 0, 4);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(0, $result->maxGroupNumber);
        $this->assertEmpty($result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_with_character_class(): void
    {
        $literal = new LiteralNode('a', 0, 1);
        $charClass = new CharClassNode($literal, false, 0, 1);
        $sequence = new SequenceNode([$charClass], 0, 1);
        $root = new RegexNode($sequence, '', '/', 0, 1);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(0, $result->maxGroupNumber);
        $this->assertEmpty($result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_with_range(): void
    {
        $start = new LiteralNode('a', 0, 1);
        $end = new LiteralNode('z', 1, 2);
        $range = new RangeNode($start, $end, 0, 2);
        $sequence = new SequenceNode([$range], 0, 2);
        $root = new RegexNode($sequence, '', '/', 0, 2);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(0, $result->maxGroupNumber);
        $this->assertEmpty($result->captureSequence);
        $this->assertEmpty($result->namedGroups);
    }

    public function test_collect_handles_branch_reset_define_and_char_classes(): void
    {
        $literalA = new LiteralNode('a', 0, 1);
        $literalB = new LiteralNode('b', 1, 2);

        $range = new RangeNode($literalA, $literalB, 0, 2);
        $charClass = new CharClassNode($range, false, 0, 2);

        $capturedInDefine = new GroupNode($charClass, GroupType::T_GROUP_CAPTURING, null, null, 0, 2);
        $define = new DefineNode($capturedInDefine, 0, 2);

        $conditional = new ConditionalNode($literalA, $capturedInDefine, $literalB, 0, 2);
        $sequence = new SequenceNode([$define, $conditional], 0, 2);

        $namedGroup = new GroupNode($sequence, GroupType::T_GROUP_NAMED, 'foo', null, 0, 2);
        $capturing = new GroupNode($literalA, GroupType::T_GROUP_CAPTURING, null, null, 0, 1);

        $branchReset = new GroupNode(
            new AlternationNode([$capturing, $namedGroup], 0, 2),
            GroupType::T_GROUP_BRANCH_RESET,
            null,
            null,
            0,
            2,
        );

        $root = new RegexNode(new SequenceNode([$branchReset], 0, 2), '', '/', 0, 2);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertGreaterThanOrEqual(1, $result->maxGroupNumber);
        $this->assertContains(1, $result->captureSequence);
        $this->assertArrayHasKey('foo', $result->namedGroups);
        $this->assertContains(1, $result->namedGroups['foo']);
    }

    public function test_collect_duplicate_named_groups(): void
    {
        $literal1 = new LiteralNode('a', 0, 1);
        $literal2 = new LiteralNode('b', 1, 2);
        $group1 = new GroupNode($literal1, GroupType::T_GROUP_NAMED, 'name', null, 0, 1);
        $group2 = new GroupNode($literal2, GroupType::T_GROUP_NAMED, 'name', null, 1, 2);
        $sequence = new SequenceNode([$group1, $group2], 0, 2);
        $root = new RegexNode($sequence, '', '/', 0, 2);

        $collector = new GroupNumberingCollector();
        $result = $collector->collect($root);

        $this->assertSame(2, $result->maxGroupNumber);
        $this->assertSame([1, 2], $result->captureSequence);
        $this->assertArrayHasKey('name', $result->namedGroups);
        $this->assertSame([1, 2], $result->namedGroups['name']);
    }
}

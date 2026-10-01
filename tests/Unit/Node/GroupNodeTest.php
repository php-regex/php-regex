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

namespace PhpRegex\Tests\Unit\Node;

use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GroupNodeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{\PhpRegex\Parser\Node\GroupType, ?string, ?string}>
     */
    public static function data_provider_group_types(): \Iterator
    {
        yield 'capturing_default' => [GroupType::Capturing, null, null];
        yield 'non_capturing' => [GroupType::NonCapturing, null, null];
        yield 'named_simple' => [GroupType::Named, 'id', null];
        yield 'lookahead_positive' => [GroupType::LookaheadPositive, null, null];
        yield 'lookahead_negative' => [GroupType::LookaheadNegative, null, null];
        yield 'lookbehind_positive' => [GroupType::LookbehindPositive, null, null];
        yield 'lookbehind_negative' => [GroupType::LookbehindNegative, null, null];
        yield 'atomic' => [GroupType::Atomic, null, null];
        yield 'inline_flags_simple' => [GroupType::InlineFlags, null, 'i'];
        yield 'inline_flags_complex' => [GroupType::InlineFlags, null, 'imsx'];
    }

    #[DataProvider('data_provider_group_types')]
    public function test_constructor_and_getters(GroupType $type, ?string $name, ?string $flags): void
    {
        $child = new LiteralNode('a', 1, 2);
        $node = new GroupNode($child, $type, $name, $flags, 0, 5);

        $this->assertSame($child, $node->child);
        $this->assertSame($type, $node->type);
        $this->assertSame($name, $node->name);
        $this->assertSame($flags, $node->flags);
        $this->assertSame(0, $node->getStartPosition());
        $this->assertSame(5, $node->getEndPosition());
    }

    public function test_group_without_name_does_not_set_flags(): void
    {
        // T_GROUP_CAPTURING ne devrait pas avoir de flags, mais la structure le permet.
        $child = new LiteralNode('a', 1, 2);
        $node = new GroupNode($child, GroupType::Capturing, null, 'i', 0, 5);

        $this->assertNull($node->name);
        $this->assertSame('i', $node->flags);
    }

    public function test_accept_visitor_calls_visit_group(): void
    {
        $child = $this->createStub(NodeInterface::class);
        $node = new GroupNode($child, GroupType::Capturing, null, null, 0, 5);
        $visitor = $this->createMock(NodeVisitorInterface::class);

        $visitor->expects($this->once())
            ->method('visitGroup')
            ->with($this->identicalTo($node))
            ->willReturn('visited');

        $this->assertSame('visited', $node->accept($visitor));
    }
}

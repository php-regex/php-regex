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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class AdvancedParserTest extends TestCase
{
    public function test_parse_named_group(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?<name>a)/');

        $this->assertInstanceOf(GroupNode::class, $ast->pattern);
        $this->assertSame(GroupType::Named, $ast->pattern->type);
        $this->assertSame('name', $ast->pattern->name);
    }

    public function test_parse_lazy_quantifier(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/a+?/');

        $this->assertInstanceOf(QuantifierNode::class, $ast->pattern);
        $this->assertSame('+', $ast->pattern->quantifier);
        $this->assertSame(QuantifierType::Lazy, $ast->pattern->type);
    }

    public function test_parse_possessive_quantifier(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/a{2,3}+/');

        $this->assertInstanceOf(QuantifierNode::class, $ast->pattern);
        $this->assertSame('{2,3}', $ast->pattern->quantifier);
        $this->assertSame(QuantifierType::Possessive, $ast->pattern->type);
    }

    public function test_parse_lookahead(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/a(?=b)/');

        // $ast->pattern is a SequenceNode(Literal(a), GroupNode(...))
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $this->assertCount(2, $ast->pattern->children);
        $group = $ast->pattern->children[1];
        $this->assertInstanceOf(GroupNode::class, $group);
        $this->assertSame(GroupType::LookaheadPositive, $group->type);
    }

    public function test_parse_alternative_delimiter(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('#a|b#imsU');

        $this->assertSame('#', $ast->delimiter);
        $this->assertSame('imsU', $ast->flags);
    }

    public function test_parse_brace_delimiter(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('{foo(bar)}i');

        $this->assertSame('{', $ast->delimiter);
        $this->assertSame('i', $ast->flags);
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $this->assertCount(4, $ast->pattern->children); // 'f','o','o','(bar)'
        $this->assertInstanceOf(GroupNode::class, $ast->pattern->children[3]);
    }
}

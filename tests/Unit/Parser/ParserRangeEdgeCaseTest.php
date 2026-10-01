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

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ParserRangeEdgeCaseTest extends TestCase
{
    public function test_parse_chained_hyphen_a_z_0_9(): void
    {
        $regex = Regex::create()->parse('/[a-z-0-9]/');
        $charClass = $regex->pattern;

        $this->assertInstanceOf(CharClassNode::class, $charClass);

        $alternation = $charClass->expression;
        $this->assertInstanceOf(AlternationNode::class, $alternation);

        $parts = $alternation->alternatives;
        $this->assertCount(3, $parts);

        $this->assertInstanceOf(RangeNode::class, $parts[0]);
        $this->assertInstanceOf(LiteralNode::class, $parts[0]->start);
        $this->assertInstanceOf(LiteralNode::class, $parts[0]->end);
        $this->assertSame('a', $parts[0]->start->value);
        $this->assertSame('z', $parts[0]->end->value);

        $this->assertInstanceOf(LiteralNode::class, $parts[1]);
        $this->assertSame('-', $parts[1]->value);

        $this->assertInstanceOf(RangeNode::class, $parts[2]);
        $this->assertInstanceOf(LiteralNode::class, $parts[2]->start);
        $this->assertInstanceOf(LiteralNode::class, $parts[2]->end);
        $this->assertEquals('0', $parts[2]->start->value);
        $this->assertEquals('9', $parts[2]->end->value);
    }

    public function test_parse_trailing_hyphen_a_z(): void
    {
        $regex = Regex::create()->parse('/[a-z-]/');
        $charClass = $regex->pattern;

        $this->assertInstanceOf(CharClassNode::class, $charClass);

        $alternation = $charClass->expression;
        $this->assertInstanceOf(AlternationNode::class, $alternation);

        $parts = $alternation->alternatives;
        $this->assertCount(2, $parts);

        $this->assertInstanceOf(RangeNode::class, $parts[0]);
        $this->assertInstanceOf(LiteralNode::class, $parts[0]->start);
        $this->assertInstanceOf(LiteralNode::class, $parts[0]->end);
        $this->assertSame('a', $parts[0]->start->value);
        $this->assertSame('z', $parts[0]->end->value);

        $this->assertInstanceOf(LiteralNode::class, $parts[1]);
        $this->assertSame('-', $parts[1]->value);
    }

    public function test_parse_leading_hyphen_a_z(): void
    {
        $regex = Regex::create()->parse('/[-a-z]/');
        $charClass = $regex->pattern;

        $this->assertInstanceOf(CharClassNode::class, $charClass);

        $alternation = $charClass->expression;
        $this->assertInstanceOf(AlternationNode::class, $alternation);

        $parts = $alternation->alternatives;
        $this->assertCount(2, $parts);

        $this->assertInstanceOf(LiteralNode::class, $parts[0]);
        $this->assertSame('-', $parts[0]->value);

        $this->assertInstanceOf(RangeNode::class, $parts[1]);
        $this->assertInstanceOf(LiteralNode::class, $parts[1]->start);
        $this->assertInstanceOf(LiteralNode::class, $parts[1]->end);
        $this->assertSame('a', $parts[1]->start->value);
        $this->assertSame('z', $parts[1]->end->value);
    }
}

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

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class SubroutineParserTest extends TestCase
{
    public function test_parse_recursion(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?R)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('R', $ast->pattern->reference);
        $this->assertSame('', $ast->pattern->syntax);
    }

    public function test_parse_numeric_subroutine(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?1)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('1', $ast->pattern->reference);
    }

    public function test_parse_relative_numeric_subroutine(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?-1)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('-1', $ast->pattern->reference);
    }

    public function test_parse_named_subroutine(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?&name)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('name', $ast->pattern->reference);
        $this->assertSame('&', $ast->pattern->syntax);
    }

    public function test_parse_p_named_subroutine(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?P>name)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('name', $ast->pattern->reference);
        $this->assertSame('P>', $ast->pattern->syntax);
    }

    public function test_throws_on_incomplete_subroutine(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected ) to close subroutine call');
        $regex = Regex::create();
        // The regex must be validly delimited for the parser to run
        $regex->parse('/(?&name/i');
    }
}

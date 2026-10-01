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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ParserEdgeCaseTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    public function test_quantifier_on_empty_sequence(): void
    {
        // Case: /(?:)+/ -> Empty group (empty sequence) quantified.
        // preg_match() on PCRE2 10.48 and 10.40 compiles it: a group, even an
        // empty one, can be repeated.
        $ast = $this->regex->parse('/(?:)+/');
        $this->assertInstanceOf(QuantifierNode::class, $ast->pattern);
    }

    public function test_subroutine_empty_name(): void
    {
        // Case: (?&) -> subroutine call without name
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected subroutine name');
        $this->regex->parse('/(?&)/');
    }

    public function test_named_group_empty_name_angle_brackets(): void
    {
        // Case: (?<>) -> Empty named group
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected group name');
        $this->regex->parse('/(?<>)/');
    }

    public function test_unclosed_group_in_subroutine_name(): void
    {
        // Case: (?&name -> no closing parenthesis, but end of string
        // Should trigger "Unexpected token" or "Expected )"
        $this->expectException(ParserException::class);
        $this->regex->parse('/(?&name/');
    }
}

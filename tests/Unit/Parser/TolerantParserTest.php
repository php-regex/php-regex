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

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\TolerantParseResult;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class TolerantParserTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    public function test_returns_partial_ast_and_errors(): void
    {
        $result = $this->regex->parseTolerant('/(a+/');

        $this->assertTrue($result->hasErrors());
        $this->assertInstanceOf(ParserException::class, $result->errors[0]);
        $this->assertInstanceOf(SequenceNode::class, $result->ast->pattern);
        $this->assertInstanceOf(LiteralNode::class, $result->ast->pattern->children[0]);
        $this->assertSame('(a+', $result->ast->pattern->children[0]->value);
    }

    public function test_successful_parse_has_no_errors(): void
    {
        $result = $this->regex->parseTolerant('/abc/');

        $this->assertFalse($result->hasErrors());
    }

    /**
     * The errors are the library's: a list of the exceptions a strict parse
     * throws, each with its error code and offset.
     */
    public function test_the_errors_are_a_list_of_regex_exceptions(): void
    {
        $result = $this->regex->parseTolerant('/(a+/');
        $type = (string) (new \ReflectionClass(TolerantParseResult::class))->getConstructor()?->getDocComment();

        $this->assertTrue(array_is_list($result->errors));
        $this->assertContainsOnlyInstancesOf(RegexException::class, $result->errors);
        $this->assertMatchesRegularExpression('/@param\s+list<RegexException>\s+\$errors/', $type);
    }
}

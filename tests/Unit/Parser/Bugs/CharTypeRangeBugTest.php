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

namespace PhpRegex\Tests\Unit\Parser\Bugs;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for range endpoints in character classes.
 *
 * PCRE rejects a range whose endpoint is a character type, POSIX class, or
 * Unicode property: `[\w-_]`, `[\d-z]`, `[a-\d]` are all compile errors
 * ("invalid range in character class"). The parser must reject them too —
 * an earlier "fix" silently re-interpreted the hyphen as a literal, which
 * made the library accept patterns that fail at runtime in PHP.
 *
 * A hyphen is only a literal when it cannot be a range operator: at the
 * start (`[-a]`) or end (`[a-]`) of a class.
 */
final class CharTypeRangeBugTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    /**
     * @return \Iterator<string, array{0: string}>
     */
    public static function charTypeRangeProvider(): \Iterator
    {
        yield 'word character \w as range start' => ['/[\w-_]/'];
        yield 'word character \w before hyphen and letter' => ['/[\w-a]/'];
        yield 'digit \d as range start' => ['/[\d-a]/'];
        yield 'whitespace \s as range start' => ['/[\s-a]/'];
        yield 'non-word \W as range start' => ['/[\W-a]/'];
        yield 'non-digit \D as range start' => ['/[\D-a]/'];
        yield 'non-whitespace \S as range start' => ['/[\S-a]/'];
        yield 'horizontal whitespace \h as range start' => ['/[\h-a]/'];
        yield 'vertical whitespace \v as range start' => ['/[\v-a]/'];
        yield 'char type as range end' => ['/[a-\d]/'];
        yield 'char type range with valid range after' => ['/[\w-_a-z]/'];
    }

    #[DataProvider('charTypeRangeProvider')]
    public function test_chartype_range_endpoint_is_rejected(string $pattern): void
    {
        $this->expectException(ParserException::class);

        $this->regex->parse($pattern);
    }

    /**
     * Test that valid ranges still work correctly.
     */
    public function test_valid_literal_ranges_still_work(): void
    {
        $ast = $this->regex->parse('/[a-z]/');

        $this->assertInstanceOf(CharClassNode::class, $ast->pattern);
        // Should be a RangeNode, not separate literals
        $this->assertInstanceOf(RangeNode::class, $ast->pattern->expression);
    }

    /**
     * Test that hyphen at end of character class is still literal.
     */
    public function test_trailing_hyphen_is_literal(): void
    {
        $ast = $this->regex->parse('/[abc-]/');

        $this->assertInstanceOf(CharClassNode::class, $ast->pattern);

        $expression = $ast->pattern->expression;
        $this->assertInstanceOf(AlternationNode::class, $expression);

        // Last element should be a literal hyphen
        $lastElement = $expression->alternatives[\count($expression->alternatives) - 1];
        $this->assertInstanceOf(LiteralNode::class, $lastElement);
        $this->assertSame('-', $lastElement->value);
    }

    /**
     * Test that hyphen at start of character class is still literal.
     */
    public function test_leading_hyphen_is_literal(): void
    {
        $ast = $this->regex->parse('/[-abc]/');

        $this->assertInstanceOf(CharClassNode::class, $ast->pattern);

        $expression = $ast->pattern->expression;
        $this->assertInstanceOf(AlternationNode::class, $expression);

        // First element should be a literal hyphen
        $this->assertInstanceOf(LiteralNode::class, $expression->alternatives[0]);
        $this->assertSame('-', $expression->alternatives[0]->value);
    }

    /**
     * Test that a hyphen after a char type is literal when no range can form
     * (end of class), matching PCRE: /[\d-]/ compiles.
     */
    public function test_chartype_then_trailing_hyphen_is_literal(): void
    {
        $ast = $this->regex->parse('/[\d-]/');

        $this->assertInstanceOf(CharClassNode::class, $ast->pattern);

        $expression = $ast->pattern->expression;
        $this->assertInstanceOf(AlternationNode::class, $expression);

        $lastElement = $expression->alternatives[\count($expression->alternatives) - 1];
        $this->assertInstanceOf(LiteralNode::class, $lastElement);
        $this->assertSame('-', $lastElement->value);
    }
}

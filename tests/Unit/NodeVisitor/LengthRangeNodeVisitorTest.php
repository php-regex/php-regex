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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LengthRangeNodeVisitorTest extends TestCase
{
    private LengthRangeCalculator $visitor;

    protected function setUp(): void
    {
        $this->visitor = new LengthRangeCalculator();
    }

    #[DataProvider('lengthRangeProvider')]
    public function test_length_range(string $regex, int $expectedMin, ?int $expectedMax): void
    {
        $ast = Regex::create()->parse($regex);
        [$min, $max] = $ast->accept($this->visitor);

        $this->assertSame($expectedMin, $min);
        $this->assertSame($expectedMax, $max);
    }

    /**
     * @return iterable<array{string, int, int|null}>
     */
    public static function lengthRangeProvider(): iterable
    {
        yield ['/a/', 1, 1];
        yield ['/abc/', 3, 3];
        yield ['/a?/', 0, 1];
        yield ['/a*/', 0, null];
        yield ['/a+/', 1, null];
        yield ['/a{2,5}/', 2, 5];
        yield ['/a{3,}/', 3, null];
        yield ['/a{4}/', 4, 4];
        yield ['/(a|b)/', 1, 1];
        yield ['/(aa|bbb)/', 2, 3];
        yield ['/a*b/', 1, null]; // a* + b
        yield ['/^a$/', 1, 1]; // anchors don't add length
        yield ['/\bword\b/', 4, 4]; // assertions don't add length
        yield ['/[abc]/', 1, 1];
        yield ['/\d/', 1, 1];
        yield ['/./', 1, 1];
        yield ['/\w+/', 1, null];
        yield ['/(?=a)b/', 1, 1]; // lookahead zero-width
        yield ['/(?#comment)a/', 1, 1]; // comment zero-width

        // Additional patterns to cover more visitor methods
        yield ['/[a-z]/', 1, 1]; // visitRange - character range
        yield ['/(.)\1/', 1, null]; // visitBackref - backreference (unknown length)
        yield ['/\x{0041}/u', 1, 1]; // visitUnicode - unicode escape (requires u flag)
        yield ['/[[:alpha:]]/', 1, 1]; // visitPosixClass - POSIX class
        yield ['/\p{L}/', 1, 1]; // visitUnicodeProp - unicode property
    }

    public function test_conditional_pattern(): void
    {
        // visitConditional - conditional pattern
        $ast = Regex::create()->parse('/(a)?(?(1)b|c)/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(1, $min);
        $this->assertSame(2, $max);
    }

    public function test_subroutine_pattern(): void
    {
        // visitSubroutine - subroutine call (unknown length)
        $ast = Regex::create()->parse('/(a)(?1)/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(1, $min);
        $this->assertNull($max);
    }

    public function test_pcre_verb_pattern(): void
    {
        // visitPcreVerb - PCRE verb (zero-width)
        $ast = Regex::create()->parse('/a(*SKIP)b/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(2, $min);
        $this->assertSame(2, $max);
    }

    public function test_define_pattern(): void
    {
        // visitDefine - define block (zero-width)
        $ast = Regex::create()->parse('/(?(DEFINE)(?<foo>a))(?&foo)/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(0, $min);
        $this->assertNull($max);
    }

    public function test_limit_match_pattern(): void
    {
        // visitLimitMatch - limit match (zero-width)
        $ast = Regex::create()->parse('/(*LIMIT_MATCH=100)a/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(1, $min);
        $this->assertSame(1, $max);
    }

    public function test_callout_pattern(): void
    {
        // visitCallout - callout (zero-width)
        $ast = Regex::create()->parse('/a(?C1)b/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(2, $min);
        $this->assertSame(2, $max);
    }

    public function test_keep_pattern(): void
    {
        // visitKeep - keep marker (zero-width)
        $ast = Regex::create()->parse('/a\Kb/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(2, $min);
        $this->assertSame(2, $max);
    }
}

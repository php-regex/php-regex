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
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LengthRangeCalculatorTest extends TestCase
{
    private LengthRangeCalculator $visitor;

    protected function setUp(): void
    {
        $this->visitor = new LengthRangeCalculator();
    }

    #[Test]
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

    #[Test]
    public function test_conditional_pattern(): void
    {
        // visitConditional - conditional pattern
        $ast = Regex::create()->parse('/(a)?(?(1)b|c)/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(1, $min);
        $this->assertSame(2, $max);
    }

    #[Test]
    public function test_subroutine_pattern(): void
    {
        // visitSubroutine - subroutine call (unknown length)
        $ast = Regex::create()->parse('/(a)(?1)/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(1, $min);
        $this->assertNull($max);
    }

    #[Test]
    public function test_subroutine_follows_the_body_a_resolver_gives(): void
    {
        // preg_match('/(ab)x(?1)c/', 'abxabc') matches six characters (PHP 8.4.27, PCRE2 10.49).
        $ast = Regex::create()->parse('/(ab)x(?1)c/');
        $group = $ast->pattern instanceof SequenceNode ? $ast->pattern->children[0] : null;
        $this->assertInstanceOf(GroupNode::class, $group);
        $calls = [];
        $visitor = new LengthRangeCalculator(calledBody: static function (SubroutineNode $call) use ($group, &$calls): NodeInterface {
            $calls[] = $call->reference;

            return $group->child;
        });

        $this->assertSame([6, 6], $ast->accept($visitor));
        $this->assertSame(['1'], $calls);
    }

    #[Test]
    public function test_subroutine_stays_unbounded_where_the_resolver_gives_no_body(): void
    {
        $ast = Regex::create()->parse('/(a(?1)?b)x(?1)/');
        $visitor = new LengthRangeCalculator(calledBody: static fn (SubroutineNode $call): ?NodeInterface => null);

        $this->assertSame([3, null], $ast->accept($visitor));
    }

    #[Test]
    public function test_shared_subroutine_bodies_are_measured_once(): void
    {
        $bodies = [new LiteralNode('a', 0, 1)];
        for ($level = 1; $level <= 20; $level++) {
            $bodies[] = new SequenceNode([
                new SubroutineNode((string) ($level - 1), '', 0, 0),
                new SubroutineNode((string) ($level - 1), '', 0, 0),
            ], 0, 0);
        }
        $calls = 0;
        $visitor = new LengthRangeCalculator(calledBody: function (SubroutineNode $call) use ($bodies, &$calls): NodeInterface {
            $this->assertLessThanOrEqual(40, ++$calls, 'Shared bodies must not be expanded at every call site.');

            return $bodies[(int) $call->reference];
        });

        $this->assertSame([1048576, 1048576], $bodies[20]->accept($visitor));
    }

    #[Test]
    public function test_capture_shape_measures_a_compact_subroutine_graph(): void
    {
        $definitions = '(?<g0>a)';
        for ($level = 1; $level <= 10; $level++) {
            $previous = $level - 1;
            $definitions .= '(?<g'.$level.'>(?&g'.$previous.')(?&g'.$previous.'))';
        }
        $pattern = '/(?(DEFINE)'.$definitions.')(?&g10)/';
        $subject = str_repeat('a', 1024);
        $this->assertSame(1, preg_match($pattern, $subject));

        $whole = Regex::create(['cache' => null])->captureShape($pattern)->whole;

        $this->assertSame(1024, $whole->minLength);
        $this->assertSame(1024, $whole->maxLength);
    }

    #[Test]
    public function test_length_work_budget_returns_conservative_bounds(): void
    {
        $children = [];
        for ($i = 0; $i < 100001; $i++) {
            $children[] = new LiteralNode('a', 0, 1);
        }
        [$min, $max] = (new SequenceNode($children, 0, 0))->accept(new LengthRangeCalculator());

        $this->assertSame(100000, $min);
        $this->assertNull($max);
    }

    /**
     * The budget is one pattern's: a visitor that ran out of it on one
     * pattern measures the next one in full.
     */
    #[Test]
    public function test_length_work_budget_starts_again_with_each_pattern(): void
    {
        $children = [];
        for ($i = 0; $i < 100001; $i++) {
            $children[] = new LiteralNode('a', 0, 1);
        }
        $visitor = new LengthRangeCalculator();

        // The sequence is one of the 100,000 nodes measured: 99,999 letters are.
        $this->assertSame([99999, null], (new RegexNode(new SequenceNode($children, 0, 0), '', '/', 0, 0))->accept($visitor));
        $this->assertSame([2, 2], (new RegexNode(new SequenceNode([new LiteralNode('a', 0, 1), new LiteralNode('b', 1, 2)], 0, 2), '', '/', 0, 0))->accept($visitor));
    }

    #[Test]
    public function test_endless_subroutine_chain_stops_at_the_call_depth_limit(): void
    {
        $calls = 0;
        $visitor = new LengthRangeCalculator(calledBody: function (SubroutineNode $call) use (&$calls): NodeInterface {
            $this->assertLessThanOrEqual(1024, ++$calls);

            return new SubroutineNode('next', '', 0, 0);
        });

        $this->assertSame([0, null], (new SubroutineNode('start', '', 0, 0))->accept($visitor));
        $this->assertSame(1024, $calls);
    }

    #[Test]
    public function test_capture_shape_measures_many_calls_in_a_row(): void
    {
        $pattern = '/(?(DEFINE)(?<a>a))'.str_repeat('(?&a)', 1100).'/';
        $this->assertSame(1, preg_match($pattern, str_repeat('a', 1100), $match));
        $this->assertSame(1100, \strlen($match[0]));

        $whole = Regex::create(['cache' => null])->captureShape($pattern)->whole;

        $this->assertSame(1100, $whole->minLength);
        $this->assertSame(1100, $whole->maxLength);
    }

    #[Test]
    public function test_deeply_nested_groups_keep_their_exact_length(): void
    {
        // Two hundred nested groups compile: PCRE2 refuses a nesting past 250.
        $pattern = '/'.str_repeat('(?:b', 200).'a'.str_repeat(')', 200).'/';
        $this->assertSame(1, preg_match($pattern, str_repeat('b', 200).'a', $match));
        $this->assertSame(201, \strlen($match[0]));

        $this->assertSame([201, 201], Regex::create()->parse($pattern)->accept(new LengthRangeCalculator()));
    }

    #[Test]
    public function test_capture_shape_measures_a_long_subroutine_chain(): void
    {
        $definitions = '(?<g0>a)';
        for ($link = 1; $link <= 200; $link++) {
            $definitions .= '(?<g'.$link.'>b(?&g'.($link - 1).'))';
        }
        $pattern = '/(?(DEFINE)'.$definitions.')(?&g200)/';
        $this->assertSame(1, preg_match($pattern, str_repeat('b', 200).'a', $match));
        $this->assertSame(201, \strlen($match[0]));

        $whole = Regex::create(['cache' => null])->captureShape($pattern)->whole;

        $this->assertSame(201, $whole->minLength);
        $this->assertSame(201, $whole->maxLength);
    }

    #[Test]
    public function test_cache_is_reset_when_pattern_unicode_mode_changes(): void
    {
        $body = new LiteralNode('é', 0, 2);
        $call = new SubroutineNode('body', '', 0, 0);
        $visitor = new LengthRangeCalculator(calledBody: static fn (SubroutineNode $call): NodeInterface => $body);

        $this->assertSame([2, 2], (new RegexNode($call, '', '/', 0, 0))->accept($visitor));
        $this->assertSame([1, 1], (new RegexNode($call, 'u', '/', 0, 0))->accept($visitor));
        $this->assertSame([2, 2], (new RegexNode($call, '', '/', 0, 0))->accept($visitor));
    }

    #[Test]
    public function test_pcre_verb_pattern(): void
    {
        // visitPcreVerb - PCRE verb (zero-width)
        $ast = Regex::create()->parse('/a(*SKIP)b/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(2, $min);
        $this->assertSame(2, $max);
    }

    #[Test]
    public function test_define_pattern(): void
    {
        // visitDefine - define block (zero-width)
        $ast = Regex::create()->parse('/(?(DEFINE)(?<foo>a))(?&foo)/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(0, $min);
        $this->assertNull($max);
    }

    #[Test]
    public function test_limit_match_pattern(): void
    {
        // visitLimitMatch - limit match (zero-width)
        $ast = Regex::create()->parse('/(*LIMIT_MATCH=100)a/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(1, $min);
        $this->assertSame(1, $max);
    }

    #[Test]
    public function test_callout_pattern(): void
    {
        // visitCallout - callout (zero-width)
        $ast = Regex::create()->parse('/a(?C1)b/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(2, $min);
        $this->assertSame(2, $max);
    }

    #[Test]
    public function test_keep_pattern(): void
    {
        // visitKeep - keep marker (zero-width)
        $ast = Regex::create()->parse('/a\Kb/');
        [$min, $max] = $ast->accept($this->visitor);
        $this->assertSame(2, $min);
        $this->assertSame(2, $max);
    }
}

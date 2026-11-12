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

use PHPRegex\Parser\Analysis\CaptureFacts;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Groups that call one another, "(?&name)" or "(?1)": the body several
 * calls share is read once for each fact, and the shape says what the calls
 * read.
 */
final class CaptureShapeCallGraphTest extends TestCase
{
    use PcreGates;

    /**
     * The facts a call asks of the body it runs: its values and whether it
     * starts off zero, each for a match and for a scan; whether it may be
     * "0"; whether it holds digits only; whether it holds no letter, of
     * each case.
     */
    private const FACTS = 8;

    /**
     * Each level calls the level below twice: entering every call reads
     * 2^depth bodies, and twenty levels a million of them. A fact is read
     * once per body, so the bodies read grow with the depth alone. The
     * pattern is marked first, as the analysis marks it before it reads.
     */
    #[Test]
    #[DataProvider('provideDoublingGraphs')]
    public function test_a_shared_body_is_read_once_per_fact(string $leaf, string $level): void
    {
        foreach ([10, 20] as $depth) {
            $ast = RegexParser::create()->parse('/(?(DEFINE)'.self::levels($depth, $leaf, $level).')(?&g'.$depth.')/');
            $facts = new CaptureFacts(groups: GroupIndex::of($ast->pattern));
            $facts->markAtStart($ast->pattern);
            $facts->markAtEnd($ast->pattern);
            $facts->of($ast->pattern);
            $facts->of($ast->pattern, true);

            // every level is read, and none more than once for a fact
            $this->assertGreaterThanOrEqual($depth + 1, $facts->bodiesRead());
            $this->assertLessThanOrEqual(self::FACTS * ($depth + 1), $facts->bodiesRead());
        }
    }

    /**
     * A body is read when a call asks: none is before, and a fact asked
     * again of the same node reads no body again.
     */
    #[Test]
    public function test_bodies_are_counted_as_calls_read_them(): void
    {
        $ast = RegexParser::create()->parse('/(?(DEFINE)(?<d>\d))(?&d)(?&d)/');
        $facts = new CaptureFacts(groups: GroupIndex::of($ast->pattern));

        // Each count is read into a variable: the count moves with every
        // fact asked, and an assertion on the call itself would freeze it.
        $before = $facts->bodiesRead();
        $facts->of($ast->pattern);
        $read = $facts->bodiesRead();
        $facts->of($ast->pattern);
        $again = $facts->bodiesRead();

        $this->assertSame(0, $before);
        $this->assertGreaterThan(0, $read);
        $this->assertSame($read, $again);
    }

    /**
     * A first branch of "^" wins where the alternation starts the pattern
     * and nothing follows it. The analysis marks the nodes of each case it
     * reads, so a call reads the body as it is marked by then, not as the
     * call before it read it.
     */
    #[Test]
    public function test_a_body_is_read_again_once_a_node_is_marked_at_the_start(): void
    {
        [$facts, $call, $next, $body] = self::twoCallsOfAnAlternation();

        $facts->markAtEnd($body);
        $this->assertSame(['', 'a'], $facts->of($call)['values']);

        $facts->markAtStart($body);
        $this->assertSame([''], $facts->of($next)['values']);
    }

    #[Test]
    public function test_a_body_is_read_again_once_a_node_is_marked_at_the_end(): void
    {
        [$facts, $call, $next, $body] = self::twoCallsOfAnAlternation();

        $facts->markAtStart($body);
        $this->assertSame(['', 'a'], $facts->of($call)['values']);

        $facts->markAtEnd($body);
        $this->assertSame([''], $facts->of($next)['values']);
    }

    /**
     * What the group around the top call holds at twenty levels, as the
     * calls entered one by one read it. Three levels are few enough to ask
     * the engine.
     *
     * @param list<string>|null                                                                           $values
     * @param array{nonFalsy: bool, digitsOnly: bool, decimalInt: bool, lowercase: bool, uppercase: bool} $facts
     */
    #[Test]
    #[DataProvider('provideTwentyLevels')]
    public function test_twenty_levels_read_what_their_calls_read(string $leaf, string $level, string $subject, int $min, int $max, ?array $values, array $facts): void
    {
        $this->assertSame(1, preg_match('/^(?(DEFINE)'.self::levels(3, $leaf, $level).')((?&g3))$/', $subject, $written));
        $this->assertSame($subject, $written[5]);

        $shape = self::analyze('/(?(DEFINE)'.self::levels(20, $leaf, $level).')((?&g20))/');
        $group = $shape->groups[22];

        $this->assertSame([$min, $max], [$shape->whole->minLength, $shape->whole->maxLength]);
        $this->assertSame([$min, $max], [$group->minLength, $group->maxLength]);
        $this->assertSame($values, $group->values);
        $this->assertSame($facts, [
            'nonFalsy' => $group->nonFalsy,
            'digitsOnly' => $group->digitsOnly,
            'decimalInt' => $group->decimalInt,
            'lowercase' => $group->lowercase,
            'uppercase' => $group->uppercase,
        ]);
    }

    /**
     * The pattern whose shape took seconds: twenty levels, each calling the
     * one below twice, and one call of the last.
     */
    #[Test]
    public function test_twenty_levels_called_once_read_a_million_letters(): void
    {
        $this->assertSame(1, preg_match('/^(?(DEFINE)'.self::levels(4, 'a', '%1$s%1$s').')(?&g4)$/', str_repeat('a', 16), $written));
        $this->assertSame([str_repeat('a', 16)], $written);

        $shape = self::analyze('/(?(DEFINE)'.self::levels(20, 'a', '%1$s%1$s').')(?&g20)/');

        $this->assertSame([1_048_576, 1_048_576], [$shape->whole->minLength, $shape->whole->maxLength]);
        $this->assertSame('array{0: lowercase-string&non-falsy-string}', $shape->matchShape());
        $this->assertCount(21, $shape->groups);
    }

    /**
     * @param array<int|string, string> $matches what the engine writes for the subject
     */
    #[Test]
    #[DataProvider('provideCallGraphs')]
    public function test_the_shape_reads_calls_as_the_engine_runs_them(string $pattern, string $subject, array $matches, string $expected): void
    {
        // The interpreter is asked: PHP runs a pattern through the JIT or
        // not as the process stands, and one packaged engine answers the
        // two differently for the groups inside a called one.
        $this->assertSame(1, preg_match(PcreInterpreter::pattern($pattern), $subject, $written));
        if ($matches !== $written) {
            $this->requiresCallCapturesGivenBack($pattern);
        }
        $this->assertSame($matches, $written);

        $this->assertSame($expected, self::analyze($pattern)->matchShape());
    }

    /**
     * @return \Iterator<string, array{leaf: string, level: string}>
     */
    public static function provideDoublingGraphs(): \Iterator
    {
        // each row doubles another reading when every call is entered
        yield 'a letter twice: its values, then its letters' => ['leaf' => 'a', 'level' => '%1$s%1$s'];
        yield 'a digit in either branch: its values' => ['leaf' => '1', 'level' => '(?:%1$s|%1$s)'];
        yield 'any digit twice: its digits and its letters' => ['leaf' => '\d', 'level' => '%1$s%1$s'];
        yield 'anything but zero in either branch: whether it may be "0"' => ['leaf' => '[^0]', 'level' => '(?:%1$s|%1$s)'];
        yield 'a number in either branch: whether it starts off zero' => ['leaf' => '[1-9]\d?', 'level' => '(?:%1$s|%1$s)'];
    }

    /**
     * @return \Iterator<string, array{leaf: string, level: string, subject: string, min: int, max: int, values: list<string>|null, facts: array{nonFalsy: bool, digitsOnly: bool, decimalInt: bool, lowercase: bool, uppercase: bool}}>
     */
    public static function provideTwentyLevels(): \Iterator
    {
        yield 'a letter twice' => [
            'leaf' => 'a', 'level' => '%1$s%1$s', 'subject' => 'aaaaaaaa', 'min' => 1_048_576, 'max' => 1_048_576, 'values' => null,
            'facts' => ['nonFalsy' => true, 'digitsOnly' => false, 'decimalInt' => false, 'lowercase' => true, 'uppercase' => false],
        ];
        yield 'a digit in either branch' => [
            'leaf' => '1', 'level' => '(?:%1$s|%1$s)', 'subject' => '1', 'min' => 1, 'max' => 1, 'values' => ['1'],
            'facts' => ['nonFalsy' => true, 'digitsOnly' => true, 'decimalInt' => true, 'lowercase' => true, 'uppercase' => true],
        ];
        yield 'any digit twice' => [
            'leaf' => '\d', 'level' => '%1$s%1$s', 'subject' => '01234567', 'min' => 1_048_576, 'max' => 1_048_576, 'values' => null,
            'facts' => ['nonFalsy' => true, 'digitsOnly' => true, 'decimalInt' => false, 'lowercase' => true, 'uppercase' => true],
        ];
        yield 'anything but zero in either branch' => [
            'leaf' => '[^0]', 'level' => '(?:%1$s|%1$s)', 'subject' => 'A', 'min' => 1, 'max' => 1, 'values' => null,
            'facts' => ['nonFalsy' => true, 'digitsOnly' => false, 'decimalInt' => false, 'lowercase' => false, 'uppercase' => false],
        ];
        yield 'a number in either branch' => [
            'leaf' => '[1-9]\d?', 'level' => '(?:%1$s|%1$s)', 'subject' => '42', 'min' => 1, 'max' => 2, 'values' => null,
            'facts' => ['nonFalsy' => true, 'digitsOnly' => true, 'decimalInt' => true, 'lowercase' => true, 'uppercase' => true],
        ];
    }

    /**
     * @return \Iterator<string, array{pattern: string, subject: string, matches: array<int|string, string>, expected: string}>
     */
    public static function provideCallGraphs(): \Iterator
    {
        // What a recursion reads is unknown, whoever calls it.
        yield 'a group that calls itself' => [
            'pattern' => '/(?(DEFINE)(?<r>a(?&r)?b))x((?&r))/', 'subject' => 'xaabb',
            'matches' => ['xaabb', 'r' => '', 1 => '', 2 => 'aabb'],
            'expected' => "array{0: non-falsy-string, r: '', 1: '', 2: string}",
        ];
        yield 'two groups that call each other' => [
            'pattern' => '/(?(DEFINE)(?<p>a(?&q)?)(?<q>b(?&p)?))((?&p))/', 'subject' => 'abab',
            'matches' => ['abab', 'p' => '', 1 => '', 'q' => '', 2 => '', 3 => 'abab'],
            'expected' => "array{0: string, p: '', 1: '', q: '', 2: '', 3: string}",
        ];
        yield 'a group called before it is defined' => [
            'pattern' => '/((?&d)-(?&d))(?(DEFINE)(?<d>\d\d))/', 'subject' => '12-34',
            'matches' => ['12-34', '12-34'],
            'expected' => 'array{0: non-falsy-string, 1: non-falsy-string}',
        ];
        yield 'a call of a group that holds captures' => [
            'pattern' => '/(?(DEFINE)(?<pair>(\d)(\d)))((?&pair)(?&pair))/', 'subject' => '1234',
            'matches' => ['1234', 'pair' => '', 1 => '', 2 => '', 3 => '', 4 => '1234'],
            'expected' => "array{0: non-falsy-string&numeric-string, pair: '', 1: '', 2: '', 3: '', 4: non-falsy-string&numeric-string}",
        ];
        yield 'two levels of digits off zero' => [
            'pattern' => '/(?(DEFINE)(?<d>[1-9])(?<dd>(?&d)(?&d)))((?&dd)(?&dd))/', 'subject' => '1234',
            'matches' => ['1234', 'd' => '', 1 => '', 'dd' => '', 2 => '', 3 => '1234'],
            'expected' => "array{0: decimal-int-string&non-falsy-string, d: '', 1: '', dd: '', 2: '', 3: decimal-int-string&non-falsy-string}",
        ];
        yield 'a sign from one group, a digit after it' => [
            'pattern' => '/(?(DEFINE)(?<s>-?)(?<n>(?&s)[1-9]))((?&n))/', 'subject' => '-5',
            'matches' => ['-5', 's' => '', 1 => '', 'n' => '', 2 => '', 3 => '-5'],
            'expected' => "array{0: decimal-int-string&non-falsy-string, s: '', 1: '', n: '', 2: '', 3: decimal-int-string&non-falsy-string}",
        ];
        yield 'zero in either branch' => [
            'pattern' => '/(?(DEFINE)(?<z>0)(?<o>(?:(?&z)|(?&z))))((?&o))/', 'subject' => '0',
            'matches' => ['0', 'z' => '', 1 => '', 'o' => '', 2 => '', 3 => '0'],
            'expected' => "array{0: '0', z: '', 1: '', o: '', 2: '', 3: '0'}",
        ];
        yield 'two levels of an uppercase letter' => [
            'pattern' => '/(?(DEFINE)(?<u>A)(?<v>(?&u)(?&u)))((?&v)(?&v))/', 'subject' => 'AAAA',
            'matches' => ['AAAA', 'u' => '', 1 => '', 'v' => '', 2 => '', 3 => 'AAAA'],
            'expected' => "array{0: 'AAAA', u: '', 1: '', v: '', 2: '', 3: 'AAAA'}",
        ];
        yield 'a body read caselessly' => [
            'pattern' => '/(?(DEFINE)(?<w>(?i)ab))((?&w)(?&w))/', 'subject' => 'aBAb',
            'matches' => ['aBAb', 'w' => '', 1 => '', 2 => 'aBAb'],
            'expected' => "array{0: non-falsy-string, w: '', 1: '', 2: non-falsy-string}",
        ];
        yield 'levels that are set themselves' => [
            'pattern' => '/(?<a>x)(?<b>(?&a)(?&a))(?<c>(?&b)(?&b))/', 'subject' => 'xxxxxxx',
            'matches' => ['xxxxxxx', 'a' => 'x', 1 => 'x', 'b' => 'xx', 2 => 'xx', 'c' => 'xxxx', 3 => 'xxxx'],
            'expected' => "array{0: 'xxxxxxx', a: 'x', 1: 'x', b: 'xx', 2: 'xx', c: 'xxxx', 3: 'xxxx'}",
        ];

        // The case that skips the optional group reads group 3 at the start
        // of the pattern, where its "^" wins: the call reads the same body
        // again there, and no longer what it read behind "xy".
        yield 'a called body the skipped group leaves at the start' => [
            'pattern' => '/(?:(x)(y))?(?:(?3)){0}(^|\d)/', 'subject' => '5',
            'matches' => ['', '', '', ''],
            'expected' => "array{0: lowercase-string&non-falsy-string, 1: 'x', 2: 'y', 3: ''|numeric-string}|array{0: '', 1: '', 2: '', 3: ''|numeric-string}",
        ];
        yield 'a called body behind the group that runs' => [
            'pattern' => '/(?:(x)(y))?(?:(?3)){0}(^|\d)/', 'subject' => 'xy5',
            'matches' => ['xy5', 'x', 'y', '5'],
            'expected' => "array{0: lowercase-string&non-falsy-string, 1: 'x', 2: 'y', 3: ''|numeric-string}|array{0: '', 1: '', 2: '', 3: ''|numeric-string}",
        ];
        // And at the end of the pattern, where nothing sends the engine back.
        yield 'a called body the skipped group leaves at the end' => [
            'pattern' => '/(?:(?1)){0}(^|\d)(?:(x)(y))?/', 'subject' => '5',
            'matches' => ['', ''],
            'expected' => "array{0: lowercase-string&non-falsy-string, 1: ''|numeric-string, 2: 'x', 3: 'y'}|array{0: '', 1: ''|numeric-string}",
        ];
    }

    /**
     * The groups g0 to g<depth>: g0 reads the leaf, and each one after it
     * calls the one before where the level says "%1$s".
     */
    private static function levels(int $depth, string $leaf, string $level): string
    {
        $groups = '(?<g0>'.$leaf.')';
        for ($number = 1; $number <= $depth; $number++) {
            $groups .= '(?<g'.$number.'>'.\sprintf($level, '(?&g'.($number - 1).')').')';
        }

        return $groups;
    }

    /**
     * The facts of /(?1)(?1)(^|a)/, its two calls, and the alternation
     * they run.
     *
     * @return array{0: CaptureFacts, 1: NodeInterface, 2: NodeInterface, 3: NodeInterface}
     */
    private static function twoCallsOfAnAlternation(): array
    {
        $pattern = RegexParser::create()->parse('/(?1)(?1)(^|a)/')->pattern;
        self::assertInstanceOf(SequenceNode::class, $pattern);
        [$call, $next, $group] = $pattern->children;
        self::assertInstanceOf(SubroutineNode::class, $call);
        self::assertInstanceOf(SubroutineNode::class, $next);
        self::assertInstanceOf(GroupNode::class, $group);
        self::assertInstanceOf(AlternationNode::class, $group->child);

        return [new CaptureFacts(groups: GroupIndex::of($pattern)), $call, $next, $group->child];
    }

    private static function analyze(string $pattern): CaptureShape
    {
        return (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern));
    }
}

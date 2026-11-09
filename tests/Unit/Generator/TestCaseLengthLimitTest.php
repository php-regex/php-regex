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

namespace PHPRegex\Tests\Unit\Generator;

use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * No case is longer than the longest sample, 1,048,576 bytes: a pattern
 * the engine compiles in a few bytes can ask for a subject of hundreds of
 * megabytes, and a longer case is left out before it is allocated.
 */
final class TestCaseLengthLimitTest extends TestCase
{
    private const LIMIT = 1_048_576;

    #[Test]
    #[DataProvider('providePatternsWhoseShortestMatchPassesTheLimit')]
    public function test_a_case_past_the_limit_is_not_built(string $pattern): void
    {
        $this->assertSame(0, preg_match($pattern, ''), $pattern.' does not compile');

        $cases = $this->cases($pattern);

        $this->assertSame([], $cases['matching']);
        $this->assertNotSame([], $cases['non_matching']);
        foreach ($cases['non_matching'] as $subject) {
            $this->assertLessThanOrEqual(self::LIMIT, \strlen($subject));
            $this->assertSame(0, preg_match($pattern, $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatternsWhoseShortestMatchPassesTheLimit(): iterable
    {
        yield 'a repeat of a repeat, 6,553,500 bytes' => ['pattern' => '/^(?:a{65535}){100}$/'];
        yield 'a repeat of a repeat, one unit past the limit' => ['pattern' => '/^(?:a{1025}){1024}$/'];
        yield 'repeats in a row, 1,114,095 bytes' => ['pattern' => '/^'.str_repeat('a{65535}', 17).'$/'];
        yield 'repeats in a row, one byte past the limit' => ['pattern' => '/^'.str_repeat('a{65535}', 16).'a{17}$/'];
    }

    #[Test]
    public function test_a_repeat_of_exactly_the_limit_is_kept(): void
    {
        $pattern = '/^(?:a{1024}){1024}$/';
        $longest = str_repeat('a', self::LIMIT);
        $this->assertSame(1, preg_match($pattern, $longest));

        $cases = $this->cases($pattern);

        $this->assertSame([$longest], $cases['matching']);
        // One repeat fewer fits; one more, 1,049,600 bytes, is left out.
        $this->assertContains(str_repeat('a', 1024 * 1023), $cases['non_matching']);
        foreach ($cases['non_matching'] as $subject) {
            $this->assertLessThanOrEqual(self::LIMIT, \strlen($subject));
            $this->assertSame(0, preg_match($pattern, $subject));
        }
    }

    #[Test]
    public function test_a_row_of_exactly_the_limit_is_kept(): void
    {
        $pattern = '/^'.str_repeat('a{65535}', 16).'a{16}$/';
        $longest = str_repeat('a', self::LIMIT);
        $this->assertSame(1, preg_match($pattern, $longest));

        $cases = $this->cases($pattern);

        $this->assertSame([$longest], $cases['matching']);
        $this->assertNotSame([], $cases['non_matching']);
        foreach ($cases['non_matching'] as $subject) {
            $this->assertLessThanOrEqual(self::LIMIT, \strlen($subject));
            $this->assertSame(0, preg_match($pattern, $subject));
        }
    }

    /**
     * The limit is on each case, not on the work done to reach it: a long
     * pattern of short pieces keeps its cases.
     */
    #[Test]
    #[DataProvider('provideLongPatternsOfShortCases')]
    public function test_a_long_pattern_of_short_cases_keeps_them(string $pattern, int $length): void
    {
        $cases = $this->cases($pattern);

        $this->assertNotSame([], $cases['matching']);
        foreach ($cases['matching'] as $subject) {
            $this->assertSame($length, \strlen($subject));
            $this->assertSame(1, preg_match($pattern, $subject));
        }
        $this->assertNotSame([], $cases['non_matching']);
        foreach ($cases['non_matching'] as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, length: int}>
     */
    public static function provideLongPatternsOfShortCases(): iterable
    {
        yield 'five thousand literal characters' => ['pattern' => '/^'.str_repeat('abcdefghij', 500).'$/', 'length' => 5000];
        yield 'eight hundred counted digits' => ['pattern' => '/^'.str_repeat('\d{4}-', 800).'$/', 'length' => 4000];
        yield 'a repeat of a repeat under the limit' => ['pattern' => '/^(?:a{1000}){1000}$/', 'length' => 1_000_000];
    }

    /**
     * The parser reads "{9223372036854775807}" where PCRE stops at 65,535:
     * a quantifier visited on its own repeats nothing that many times, and
     * one repeat fewer than any count past the limit is a case that fits.
     */
    #[Test]
    public function test_a_count_past_the_limit_visited_on_its_own_builds_no_match(): void
    {
        $this->assertFalse(EngineProbe::compiles('/a{'.\PHP_INT_MAX.'}/'));
        $node = new QuantifierNode(new LiteralNode('a', 0, 0), '{'.\PHP_INT_MAX.'}', QuantifierType::Greedy, 0, 0);

        $cases = $node->accept(new TestCaseGenerator());

        $this->assertSame(['matching' => [], 'non_matching' => [str_repeat('a', self::LIMIT)]], $cases);
    }

    /**
     * The limit as each visitor applies it, with no engine in between: the
     * nodes are built by hand, as PCRE counts no further than 65,535. One
     * byte after 1,048,576 of them is a case too long; one byte after one
     * fewer is a case of exactly the limit, kept in the order it reads.
     */
    #[Test]
    public function test_a_sequence_joins_what_fits_and_leaves_out_what_does_not(): void
    {
        $digit = new CharTypeNode('d', 0, 0);
        $generator = new TestCaseGenerator();
        $this->assertSame(['matching' => ['0'], 'non_matching' => ['!']], $digit->accept($generator));

        $atTheLimit = new SequenceNode([self::repeated(self::LIMIT), $digit], 0, 0);
        $this->assertSame([
            'matching' => [],
            'non_matching' => [str_repeat('a', self::LIMIT - 1).'!'],
        ], $atTheLimit->accept($generator));

        $oneBelow = new SequenceNode([self::repeated(self::LIMIT - 1), $digit], 0, 0);
        $this->assertSame([
            'matching' => [str_repeat('a', self::LIMIT - 1).'0'],
            'non_matching' => [str_repeat('a', self::LIMIT - 2).'!'],
        ], $oneBelow->accept($generator));
    }

    #[Test]
    public function test_a_repeat_keeps_the_counts_that_fit(): void
    {
        $generator = new TestCaseGenerator();

        $this->assertSame([
            'matching' => [str_repeat('a', self::LIMIT)],
            'non_matching' => [str_repeat('a', self::LIMIT - 1)],
        ], self::repeated(self::LIMIT)->accept($generator));
        $this->assertSame([
            'matching' => [str_repeat('a', self::LIMIT - 1), str_repeat('a', self::LIMIT)],
            'non_matching' => [str_repeat('a', self::LIMIT - 2)],
        ], (new QuantifierNode(new LiteralNode('a', 0, 0), '{'.(self::LIMIT - 1).',}', QuantifierType::Greedy, 0, 0))->accept($generator));
        $this->assertSame([
            'matching' => [str_repeat('ab', 3), str_repeat('ab', 4)],
            'non_matching' => [str_repeat('ab', 2), str_repeat('ab', 5)],
        ], (new QuantifierNode(new LiteralNode('ab', 0, 0), '{3,4}', QuantifierType::Greedy, 0, 0))->accept($generator));
    }

    #[Test]
    public function test_an_empty_unit_repeats_to_itself_at_any_count(): void
    {
        $node = new QuantifierNode(new LiteralNode('', 0, 0), '{'.\PHP_INT_MAX.'}', QuantifierType::Greedy, 0, 0);

        $cases = $node->accept(new TestCaseGenerator());

        $this->assertSame(['matching' => [''], 'non_matching' => ['', '']], $cases);
    }

    private static function repeated(int $count): QuantifierNode
    {
        return new QuantifierNode(new LiteralNode('a', 0, 0), '{'.$count.'}', QuantifierType::Greedy, 0, 0);
    }

    /**
     * @return array{matching: array<string>, non_matching: array<string>}
     */
    private function cases(string $pattern): array
    {
        return Regex::create(['cache' => null, 'runtime_pcre_validation' => false])->parse($pattern)->accept(new TestCaseGenerator());
    }
}

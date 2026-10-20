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

use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreError;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\CpuClock;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A count is not walked repetition by repetition where nothing new comes
 * of it: the literals of "x{n}" are those of x joined n times, found in
 * time whatever n, PCRE's 65535 or one past what PCRE takes; and no test
 * case is built for a pattern the engine refuses, however large its counts
 * would make each case.
 */
final class LargeCountExpansionTest extends TestCase
{
    /**
     * A count PCRE refuses is read in a few milliseconds, and each count it
     * takes in a few more: forty of the largest took 0.17 s on an arm64 Mac
     * and up to 0.61 s on GitHub's shared Ubuntu runners. This leaves room
     * for a busy machine or a coverage driver; a count walked repetition by
     * repetition took minutes.
     */
    private const BUDGET = 0.5;

    /**
     * The budget on a shared runner, slower by a factor of three or four.
     */
    private const SHARED_RUNNER_BUDGET = 2.0;

    /**
     * The literals of x{n}, read from the pattern, are x's joined n times.
     */
    #[Test]
    #[DataProvider('provideRepeats')]
    public function test_the_literals_of_a_count_are_those_of_its_item_joined_that_many_times(string $item, int $count): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $child = $parser->parse('/'.$item.'/')->accept(new LiteralExtractor());
        $this->assertInstanceOf(LiteralSet::class, $child);

        $joined = $child;
        for ($i = 1; $i < $count; $i++) {
            $joined = $joined->concat($child);
        }

        $set = Regex::create(['cache' => null])->literals('/(?:'.$item.'){'.$count.'}/')->literalSet;

        $this->assertSame([$joined->prefixes, $joined->suffixes, $joined->complete], [$set->prefixes, $set->suffixes, $set->complete], \sprintf('(?:%s){%d}', $item, $count));
    }

    /**
     * @return iterable<string, array{item: string, count: int}>
     */
    public static function provideRepeats(): iterable
    {
        foreach ([2, 999, 1000, 1001, 1002, 2501, 4999] as $count) {
            yield 'a letter '.$count.' times' => ['item' => 'a', 'count' => $count];
            yield 'three letters '.$count.' times' => ['item' => 'abc', 'count' => $count];
            yield 'two branches of unequal lengths '.$count.' times' => ['item' => 'ab|c', 'count' => $count];
            yield 'a class '.$count.' times' => ['item' => '[ab]', 'count' => $count];
            yield 'letters of either case '.$count.' times' => ['item' => '(?i)ab', 'count' => $count];
            yield 'a letter then a repeat '.$count.' times' => ['item' => 'xa+', 'count' => $count];
        }
    }

    #[Test]
    #[DataProvider('provideLargeCounts')]
    public function test_the_literals_of_a_large_count_are_found_within_budget(string $regex): void
    {
        $start = CpuClock::seconds();
        $result = Regex::create(['cache' => null])->literals($regex);
        $time = CpuClock::seconds() - $start;

        $budget = false !== getenv('CI') && '' !== getenv('CI') ? self::SHARED_RUNNER_BUDGET : self::BUDGET;
        $this->assertLessThan($budget, $time, \sprintf('%s: %.3f s.', $regex, $time));
        $this->assertFalse($result->literalSet->complete, $regex);
    }

    /**
     * @return iterable<string, array{regex: string}>
     */
    public static function provideLargeCounts(): iterable
    {
        // Measured joining a{99999999} letter by letter: past two minutes.
        yield 'a count PCRE refuses' => ['regex' => '/a{99999999}/'];
        yield 'the largest count PCRE takes, forty times' => ['regex' => '/'.str_repeat('a{65535}', 40).'/'];
        yield 'counts in counts' => ['regex' => '/(?:(?:ab|c){60000}){60000}/'];
    }

    /**
     * Each case of (?:a{60000}){60000} would be 3.6 GB; the engine refuses
     * the pattern, which gets no case.
     */
    #[Test]
    public function test_a_pattern_the_engine_refuses_gets_no_test_case_whatever_its_counts(): void
    {
        $regex = '/(?:a{60000}){60000}/';
        $this->assertInstanceOf(PcreError::class, (new PcreEngine())->compile($regex), 'Oracle: the engine refuses the pattern as too large.');

        $start = CpuClock::seconds();
        $cases = Regex::create(['cache' => null])->parse($regex)->accept(new TestCaseGenerator());
        $time = CpuClock::seconds() - $start;

        $this->assertSame(['matching' => [], 'non_matching' => []], $cases);
        $this->assertLessThan(self::BUDGET, $time, \sprintf('%.3f s.', $time));

        // The same count once, which the engine takes, has its cases.
        $taken = Regex::create(['cache' => null])->parse('/(?:a{60000})/')->accept(new TestCaseGenerator());
        $this->assertSame([str_repeat('a', 60000)], $taken['matching']);
    }
}

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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A witness the model does not read exactly, through a lookaround or an
 * atomic body it keeps whole, is reported once the engine replay confirms
 * it. The replay counts on 256 run words, at two attempts pinned 64 and
 * 128 of them in: it shows what the engine does on a run of any length
 * only where nothing in the pattern tells those attempts from the ones of
 * a longer run. A repeat that counts past 64, a lookaround that must read
 * further than 64 characters, and a lookaround that goes round a loop of
 * several characters, which holds at one position in so many, do.
 *
 * "(?=a{200})" fails wherever fewer than 200 "a" follow, as at both pinned
 * attempts, and holds everywhere on a longer run: PHP 8.4.28, PCRE2 10.49,
 * pcre.jit=0, preg_match_all() with "/a(?:a+\d|(?=a{200})a*)?/" finds one
 * match in 0.02 ms on 16,000 "a", where the replay counted 195 then 131
 * steps for two short matches.
 */
final class RedosSearchCostReplayReachTest extends TestCase
{
    /**
     * On a run longer than what the pattern counts, the first attempt, or
     * one of the first two hundred, matches the rest of the run: each
     * function is linear, and nothing is reported in either mode.
     */
    #[Test]
    #[DataProvider('provideSearchesThatMatchALongerRun')]
    public function test_search_cost_is_not_reported_when_the_pattern_counts_past_the_replay(string $pattern): void
    {
        $subject = str_repeat('a', 1000);
        $count = preg_match_all(PcreInterpreter::pattern($pattern), $subject, $matches);
        $this->assertGreaterThanOrEqual(1, $count, 'Oracle: '.$pattern.' finds no match in 1,000 "a".');
        $this->assertLessThanOrEqual(201, $count, 'Oracle: the loop of every match of '.$pattern.' goes on inside the run.');
        $this->assertGreaterThanOrEqual(700, max([0, ...array_map(\strlen(...), $matches[0])]), 'Oracle: no match of '.$pattern.' reads the rest of the run.');

        $analyzer = new RedosAnalyzer();
        foreach ([$analyzer->analyze($pattern), $analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))] as $analysis) {
            $this->assertNull($analysis->searchCost, $pattern.' in '.$analysis->mode->value.' mode');
            $this->assertNull($analysis->everyMatchSearchCost, $pattern.' in '.$analysis->mode->value.' mode');
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSearchesThatMatchALongerRun(): iterable
    {
        yield 'a lookahead that counts 300' => ['pattern' => '/a+\d|(?=a{300})a+/'];
        yield 'a lookahead that counts 200, after an empty match' => ['pattern' => '/(?:a+\d|(?=a{200})a+)?/'];
        yield 'a lookahead that counts 200, after a short match' => ['pattern' => '/a(?:a+\d|(?=a{200})a*)?/'];
        yield 'a lookbehind that counts 200' => ['pattern' => '/a(?:a+\d|(?<=a{200})a*)?/'];
        yield 'a count of 200 before a lookahead' => ['pattern' => '/a(?:a+\d|a{200}(?=a)a*)?/'];
        yield 'a lookahead that counts 193 in four repeats' => ['pattern' => '/a(?:a+\d|(?=a{60}a{60}a{60}a{13})a*)?/'];
        yield 'a lookahead that goes round five characters' => ['pattern' => '/a(?:a+\d|(?=(?:a{5})*\z)a*)?/'];
    }

    /**
     * Within the reach of the replay the witness is reported once
     * replayed, as before: a count of 64, a lookahead that must read 64
     * characters, and a lookahead that goes round a loop of one character,
     * which reads to the end of any run alike.
     */
    #[Test]
    #[DataProvider('provideSearchesWithinTheReplay')]
    public function test_search_cost_is_reported_once_replayed_when_the_pattern_counts_within_the_replay(string $pattern, string $run): void
    {
        $analyzer = new RedosAnalyzer();
        $cost = $analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame($run, $cost->run, $pattern);
        $this->assertAttemptsGrow($pattern, $run, $cost->breaker);

        $this->assertNull($analyzer->analyze($pattern)->searchCost, $pattern.' is reported before the engine replay.');
        $this->assertTrue($cost->replayed, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, run: string}>
     */
    public static function provideSearchesWithinTheReplay(): iterable
    {
        yield 'a lookahead of one character' => ['pattern' => '/[a-z]+(?=\d)/', 'run' => 'a'];
        yield 'a lookahead that counts 64' => ['pattern' => '/[a-z]+(?=\d{64})/', 'run' => 'a'];
        yield 'a lookahead that counts 64 in two repeats' => ['pattern' => '/[a-z]+(?=\d{32}\d{32})/', 'run' => 'a'];
        yield 'a lookahead that goes round one character' => ['pattern' => '/[a-z]+(?=\d*x)/', 'run' => 'a'];
        yield 'a count of 64 beside the lookahead' => ['pattern' => '/[a-z]+(?=\d)|x{64}y/', 'run' => 'a'];
        yield 'a count of 8 times 8 beside the lookahead' => ['pattern' => '/[a-z]+(?=\d)|(?:x{8}){8}y/', 'run' => 'a'];
        yield 'a loop of two characters outside the lookahead' => ['pattern' => '/(?:ab)+c(?=\d)/', 'run' => 'ab'];
    }

    /**
     * One past that reach, the replay is not asked: the attempts grow on
     * the engine all the same, and the witness, which the model does not
     * read exactly, is left unreported.
     */
    #[Test]
    #[DataProvider('provideSearchesPastTheReplay')]
    public function test_search_cost_is_left_unreported_one_count_past_the_replay(string $pattern): void
    {
        $this->assertAttemptsGrow($pattern, 'a', '!');

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyze($pattern)->searchCost, $pattern);
        $this->assertNull($analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost, $pattern.' in confirmed mode');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSearchesPastTheReplay(): iterable
    {
        yield 'a lookahead that counts 65' => ['pattern' => '/[a-z]+(?=\d{65})/'];
        yield 'a lookahead that counts 65 in two repeats' => ['pattern' => '/[a-z]+(?=\d{32}\d{33})/'];
        yield 'a count of 65 beside the lookahead' => ['pattern' => '/[a-z]+(?=\d)|x{65}y/'];
        yield 'a count of 65 or more beside the lookahead' => ['pattern' => '/[a-z]+(?=\d)|x{65,}y/'];
        yield 'a count of 8 times 9 beside the lookahead' => ['pattern' => '/[a-z]+(?=\d)|(?:x{8}){9}y/'];
    }

    /**
     * A witness the model reads exactly needs no replay, whatever the
     * pattern counts.
     */
    #[Test]
    public function test_search_cost_the_model_reads_exactly_is_reported_whatever_the_pattern_counts(): void
    {
        $pattern = '/[a-z]+\d|x{65}y/';
        $this->assertAttemptsGrow($pattern, 'a', '!');

        $analyzer = new RedosAnalyzer();
        $this->assertInstanceOf(RedosSearchCost::class, $analyzer->analyze($pattern)->searchCost, $pattern);
        $this->assertTrue($analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost?->replayed, $pattern);
    }

    /**
     * The search finds no match on 1,000 run words and the breaker, and
     * the attempt pinned 250 run words in takes one more step at least per
     * run word than the one pinned 500 in.
     */
    private function assertAttemptsGrow(string $pattern, string $run, string $breaker): void
    {
        $subject = str_repeat($run, 1000).$breaker;
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern), $subject), 'Oracle: '.$pattern.' matches its run.');
        $pinned = PcreAttemptSteps::pinned($pattern);
        $far = PcreAttemptSteps::steps($pinned, $subject, 250 * \strlen($run));
        $near = PcreAttemptSteps::steps($pinned, $subject, 500 * \strlen($run));
        $this->assertGreaterThanOrEqual(250, $far - $near, \sprintf('Oracle: the attempts of %s take %d then %d steps.', $pattern, $far, $near));
    }
}

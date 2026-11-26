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
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search cost of a pattern that matches only after the run: the empty
 * string where the subject ends, through "$", "\z" or a lookahead, or the
 * breaker itself, from an attempt the breaker starts.
 *
 * The search tries those attempts last, after every attempt the run
 * starts: each of those reads what is left of the run then fails on the
 * breaker, and only then does the search end on a match. preg_match()
 * returns 1, and the search is quadratic all the same.
 *
 * PHP 8.4.28, PCRE2 10.49, pcre.jit=0: "/(?:a+\d)?$/" takes 1.1, 4.1, 15.8
 * then 62 ms on 2 000, 4 000, 8 000 then 16 000 "a";
 * "/(?:a\n)*(?:a{2}|b|\z)/m" 20, 79 then 321 ms on "a\n" 1 000, 2 000 then
 * 4 000 times and "!"; "/\s*$/" 131, 521 then 2 093 ms on 5 000, 10 000
 * then 20 000 spaces and "!" (0.1 ms with the JIT); "/(?:\w+\.)?x/" 1.2,
 * 9.4, 18 then 123 ms on 2 000, 4 000, 8 000 then 16 000 "a" and "x" (0.9,
 * 5.6, 18 then 58 ms with the JIT).
 */
final class RedosSearchCostEndMatchTest extends TestCase
{
    private const QUARTER = 250;

    /**
     * Every attempt the run starts fails, each one step longer per run word
     * left; the search ends on the empty match at the subject's end, and the
     * witness is reported, from the theoretical mode when the model reads
     * the pattern exactly, once the engine replay confirms it through a
     * lookaround.
     */
    #[Test]
    #[DataProvider('provideSearchesEndingOnAnEmptyMatchAtTheEnd')]
    public function test_search_cost_is_reported_when_only_the_attempt_at_the_subject_end_matches(string $pattern, string $prefix, string $run, string $breaker, bool $exact): void
    {
        $interpreted = PcreInterpreter::pattern($pattern);
        foreach ([1, 2, 3, 100] as $words) {
            $subject = $prefix.str_repeat($run, $words).$breaker;
            $this->assertSame(1, preg_match($interpreted, $subject, $matches, \PREG_OFFSET_CAPTURE), \sprintf('Oracle: %s finds no match in %d run words.', $pattern, $words));
            $this->assertSame(['', \strlen($subject)], $matches[0], \sprintf('Oracle: %s matches before the end of %d run words.', $pattern, $words));
        }
        $this->assertAttemptsGrowWithTheRestOfTheRun($pattern, $prefix, $run, $breaker);

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern);

        $confirmed = $analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $confirmed, $pattern.' in confirmed mode');
        $this->assertSame([2, $prefix, $run, $breaker, true], [$confirmed->degree, $confirmed->prefix, $confirmed->run, $confirmed->breaker, $confirmed->replayed], $pattern);

        $cost = $analysis->searchCost;
        if (!$exact) {
            $this->assertNull($cost, $pattern.' is reported before the engine replay.');

            return;
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame([2, $prefix, $run, $breaker, null], [$cost->degree, $cost->prefix, $cost->run, $cost->breaker, $cost->replayed], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, prefix: string, run: string, breaker: string, exact: bool}>
     */
    public static function provideSearchesEndingOnAnEmptyMatchAtTheEnd(): iterable
    {
        yield 'optional group before the dollar' => ['pattern' => '/(?:a+\d)?$/', 'prefix' => '', 'run' => 'a', 'breaker' => '', 'exact' => true];
        yield 'optional group before the dollar under D' => ['pattern' => '/(?:a+\d)?$/D', 'prefix' => '', 'run' => 'a', 'breaker' => '', 'exact' => true];
        yield 'optional group before \z' => ['pattern' => '/(?:a+\d)?\z/', 'prefix' => '', 'run' => 'a', 'breaker' => '', 'exact' => true];
        yield 'optional group before the dollar under u' => ['pattern' => '/(?:é+\d)?$/u', 'prefix' => '', 'run' => 'é', 'breaker' => '', 'exact' => true];
        yield 'dollar in another branch' => ['pattern' => '/a+\d|$/', 'prefix' => '', 'run' => 'a', 'breaker' => '', 'exact' => true];
        // The run matches up to the end: a character after it fails every
        // attempt it starts.
        yield 'star loop before the dollar' => ['pattern' => '/\s*$/', 'prefix' => '', 'run' => ' ', 'breaker' => '!', 'exact' => true];
        yield 'star loop before \z' => ['pattern' => '/\s*\z/', 'prefix' => '', 'run' => ' ', 'breaker' => '!', 'exact' => true];
        yield 'repeated line, then two letters, a letter or \z' => ['pattern' => '/(?:a\n)*(?:a{2}|b|\z)/m', 'prefix' => '', 'run' => "a\n", 'breaker' => '!', 'exact' => true];
        yield 'repeated line, then two letters, a letter or \z, without m' => ['pattern' => '/(?:a\n)*(?:a{2}|b|\z)/', 'prefix' => '', 'run' => "a\n", 'breaker' => '!', 'exact' => true];
        // The lookahead holds at the end alone, where no "a" follows.
        yield 'optional group before a negative lookahead' => ['pattern' => '/(?:a+\d)?(?!a)/', 'prefix' => '', 'run' => 'a', 'breaker' => '', 'exact' => false];
    }

    /**
     * An attempt the breaker starts may match it, once every attempt of the
     * run has read to the end of the run and failed: "/(?:\w+\.)?x/"
     * requires the "x" every subject must then hold, and the attempt at it
     * matches it; "/a+b|b$/" matches the "b" that ends the subject. The
     * witness is reported, from the theoretical mode when the model reads
     * the pattern exactly, once the engine replay confirms it through a
     * lookaround.
     */
    #[Test]
    #[DataProvider('provideSearchesEndingOnAMatchTheBreakerStarts')]
    public function test_search_cost_is_reported_when_only_an_attempt_the_breaker_starts_matches(string $pattern, string $prefix, string $run, string $breaker, bool $exact): void
    {
        $interpreted = PcreInterpreter::pattern($pattern);
        foreach ([1, 2, 3, 100] as $words) {
            $subject = $prefix.str_repeat($run, $words).$breaker;
            $this->assertSame(1, preg_match($interpreted, $subject, $matches, \PREG_OFFSET_CAPTURE), \sprintf('Oracle: %s finds no match in %d run words.', $pattern, $words));
            $this->assertGreaterThanOrEqual(\strlen($prefix.str_repeat($run, $words)), $matches[0][1], \sprintf('Oracle: %s matches before the breaker of %d run words.', $pattern, $words));
            $this->assertLessThan(\strlen($subject), $matches[0][1], \sprintf('Oracle: %s matches at the end of %d run words alone.', $pattern, $words));
        }
        $this->assertAttemptsGrowWithTheRestOfTheRun($pattern, $prefix, $run, $breaker);

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern);

        $confirmed = $analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $confirmed, $pattern.' in confirmed mode');
        $this->assertSame([2, $prefix, $run, $breaker, true], [$confirmed->degree, $confirmed->prefix, $confirmed->run, $confirmed->breaker, $confirmed->replayed], $pattern);

        $cost = $analysis->searchCost;
        if (!$exact) {
            $this->assertNull($cost, $pattern.' is reported before the engine replay.');

            return;
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame([2, $prefix, $run, $breaker, null], [$cost->degree, $cost->prefix, $cost->run, $cost->breaker, $cost->replayed], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, prefix: string, run: string, breaker: string, exact: bool}>
     */
    public static function provideSearchesEndingOnAMatchTheBreakerStarts(): iterable
    {
        // The "x" every match ends with is required: each subject holds one,
        // and the attempt at it matches.
        yield 'optional group of a class before the required character' => ['pattern' => '/(?:\w+\.)?x/', 'prefix' => '', 'run' => '0', 'breaker' => 'x', 'exact' => true];
        yield 'optional group before the required character' => ['pattern' => '/(?:a+\d)?x/', 'prefix' => '', 'run' => 'a', 'breaker' => 'x', 'exact' => true];
        yield 'optional scheme before the required character' => ['pattern' => '/(?:[a-z]+:)?@/', 'prefix' => '', 'run' => 'a', 'breaker' => '@', 'exact' => true];
        yield 'optional group before the required character under i' => ['pattern' => '/(?:a+\d)?x/i', 'prefix' => '', 'run' => 'A', 'breaker' => 'x', 'exact' => true];
        yield 'optional group before the required character under u' => ['pattern' => '/(?:é+\d)?x/u', 'prefix' => '', 'run' => 'é', 'breaker' => 'x', 'exact' => true];
        // A leading dot-star reads the rest of the subject at each attempt
        // before the one at the required "b": 177, 728 then 2 856 ms on
        // 5 000, 10 000 then 20 000 "!" and "b" for the greedy one, 128, 514
        // then 2 075 ms for the lazy one under s (pcre.jit=0).
        yield 'dot-star alternative, or the required character alone' => ['pattern' => '/(.*)(?:ab)+|b/', 'prefix' => '', 'run' => '!', 'breaker' => 'b', 'exact' => true];
        yield 'lazy dot-star alternative, or the required character alone' => ['pattern' => '/.*?(?:ab)+|b/s', 'prefix' => '', 'run' => '!', 'breaker' => 'b', 'exact' => true];
        // An alternative matches the required character at the end: the
        // breaker fails the attempts of the run before it.
        yield 'alternative matching the required code unit at the end' => ['pattern' => '/a+b|b$/', 'prefix' => '', 'run' => 'a', 'breaker' => '!b', 'exact' => true];
        yield 'alternative matching the required sign at the end' => ['pattern' => '/\s+=|=$/', 'prefix' => '', 'run' => ' ', 'breaker' => '!=', 'exact' => true];
        // A lookahead inside the optional group, a lookbehind before the
        // required character.
        yield 'optional group holding a lookahead before the required character' => ['pattern' => '/(?:a+(?=\d)\d)?x/', 'prefix' => '', 'run' => 'a', 'breaker' => 'x', 'exact' => false];
        yield 'optional group before a lookbehind and the required character' => ['pattern' => '/(?:a+\d)?(?<!y)x/', 'prefix' => '', 'run' => 'a', 'breaker' => 'x', 'exact' => false];
    }

    /**
     * The attempts pinned 250, 500 and 750 run words before the breaker
     * fail, each taking at least one more step per run word than the next.
     */
    private function assertAttemptsGrowWithTheRestOfTheRun(string $pattern, string $prefix, string $run, string $breaker): void
    {
        $subject = $prefix.str_repeat($run, 4 * self::QUARTER).$breaker;
        $pinned = PcreAttemptSteps::pinned($pattern);
        $steps = [];
        foreach ([1, 2, 3] as $quarters) {
            $offset = \strlen($prefix) + (4 - $quarters) * self::QUARTER * \strlen($run);
            $this->assertSame(0, preg_match($pinned, $subject, $matches, 0, $offset), \sprintf('Oracle: the attempt of %s %d run words before the breaker matches.', $pattern, $quarters * self::QUARTER));
            $steps[] = PcreAttemptSteps::steps($pinned, $subject, $offset);
        }

        $growth = [$steps[1] - $steps[0], $steps[2] - $steps[1]];
        $this->assertSame([$growth[0], $growth[0]], $growth, \sprintf('Oracle: the steps %s of %s are not linear in the rest of the run.', implode(' / ', $steps), $pattern));
        $this->assertGreaterThanOrEqual(self::QUARTER, $growth[0], \sprintf('Oracle: %s takes fewer steps than run words.', $pattern));
    }
}

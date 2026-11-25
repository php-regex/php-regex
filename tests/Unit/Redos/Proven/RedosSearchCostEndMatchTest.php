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
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search cost of a pattern that matches the empty string where the
 * subject ends, through "$", "\z" or a lookahead, and nowhere before it.
 *
 * The search tries the attempt at the subject's end last, after every
 * attempt the run starts: each of those reads what is left of the run then
 * fails on the breaker, and only then does the search end on the empty
 * match. preg_match() returns 1, and the search is quadratic all the same.
 *
 * PHP 8.4.28, PCRE2 10.49, pcre.jit=0: "/(?:a+\d)?$/" takes 1.1, 4.1, 15.8
 * then 62 ms on 2 000, 4 000, 8 000 then 16 000 "a";
 * "/(?:a\n)*(?:a{2}|b|\z)/m" 20, 79 then 321 ms on "a\n" 1 000, 2 000 then
 * 4 000 times and "!"; "/\s*$/" 131, 521 then 2 093 ms on 5 000, 10 000
 * then 20 000 spaces and "!" (0.1 ms with the JIT).
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

        $confirmed = $analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)->searchCost;
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

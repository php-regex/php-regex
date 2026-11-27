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

use PHPRegex\Redos\Backtrack\SearchCostReplayer;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The replay of a short match on the subject the prefix, 256 run words and
 * the breaker make: the attempts pinned 64 and 128 run words into the run
 * each match past their start within 64 run words, a retry after a match
 * "\K" leaves empty reads no further, and the attempt pinned at 64 takes one
 * more step at least per run word than the one pinned at 128.
 */
final class RedosShortMatchReplayTest extends TestCase
{
    private const WORDS = 256;

    private const QUARTER = 64;

    private const MAX_MATCH_WORDS = 64;

    #[Test]
    #[DataProvider('provideShortMatchWitnesses')]
    public function test_replay_of_short_matches_follows_the_engine_on_the_witness(string $pattern, string $prefix, string $run, string $breaker, bool $replays): void
    {
        $this->assertSame($replays, self::engineShows($pattern, $prefix, $run, $breaker), 'Oracle: '.$pattern);
        $this->assertSame($replays, (new SearchCostReplayer())->replaysShortMatches($pattern, $prefix, $run, $breaker), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, prefix: string, run: string, breaker: string, replays: bool}>
     */
    public static function provideShortMatchWitnesses(): iterable
    {
        // The run starts after the prefix: on "ab" alone the attempts would
        // start on a "b".
        yield 'run after a prefix' => ['pattern' => '/ab(?:(?:ab)+\d)?/', 'prefix' => 'a', 'run' => 'ab', 'breaker' => '', 'replays' => true];
        yield 'run word the pattern does not start' => ['pattern' => '/b(?:(?:ab)+\d)?/', 'prefix' => '', 'run' => 'ab', 'breaker' => '', 'replays' => false];
        yield 'same run started where the pattern starts' => ['pattern' => '/b(?:(?:ab)+\d)?/', 'prefix' => 'a', 'run' => 'ba', 'breaker' => '', 'replays' => true];
        // The bound counts run words, not characters.
        yield 'match of four run words' => ['pattern' => '/abababab(?:(?:ab)+\d)?/', 'prefix' => '', 'run' => 'ab', 'breaker' => '', 'replays' => true];
        yield 'match of 64 run words' => ['pattern' => '/(?:ab){64}(?:(?:ab)+\d)?/', 'prefix' => '', 'run' => 'ab', 'breaker' => '', 'replays' => true];
        yield 'match past 64 run words' => ['pattern' => '/(?:ab){65}(?:(?:ab)+\d)?/', 'prefix' => '', 'run' => 'ab', 'breaker' => '', 'replays' => false];
        yield 'attempt that reads the run before a match that reads it too' => ['pattern' => '/a(?:a+\d)?a*/', 'prefix' => '', 'run' => 'a', 'breaker' => '', 'replays' => false];
        // Each attempt takes the same 42 steps: they add up past a quarter
        // of the run, but do not grow with it.
        yield 'attempts of the same cost' => ['pattern' => '/a(?:a{1,40}\d)?/', 'prefix' => '', 'run' => 'a', 'breaker' => '', 'replays' => false];
        yield 'attempts that match only a quarter and half-way through the run' => ['pattern' => '/(?<=^a{128}|^a{64})a(?:a+\d)?/', 'prefix' => '', 'run' => 'a', 'breaker' => '', 'replays' => true];
        yield 'retry after a kept empty match that reads the run' => ['pattern' => '/a\K(?:(?:ba)+\d)?|b(?:ab)+/', 'prefix' => '', 'run' => 'ab', 'breaker' => '', 'replays' => false];
        yield 'retry after a kept empty match that reads four run words' => ['pattern' => '/a\K(?:(?:ba)+\d)?|babababa/', 'prefix' => '', 'run' => 'ab', 'breaker' => '', 'replays' => true];
        yield 'retry after a kept empty match that reads 64 run words' => ['pattern' => '/a\K(?:(?:ba)+\d)?|b(?:ab){63}a/', 'prefix' => '', 'run' => 'ab', 'breaker' => '', 'replays' => true];
        yield 'retry after a kept empty match that reads 65 run words' => ['pattern' => '/a\K(?:(?:ba)+\d)?|b(?:ab){64}a/', 'prefix' => '', 'run' => 'ab', 'breaker' => '', 'replays' => false];
        // Pinned, the retry fails where the kept empty match ends; a search
        // from there would match the breaker.
        yield 'retry after a kept empty match that fails where it is pinned' => ['pattern' => '/\Ga\K(?:(?:ba)+\d)?|!/', 'prefix' => '', 'run' => 'ab', 'breaker' => '!', 'replays' => true];
    }

    /**
     * The attempts at other offsets of the run fail: the replay pins them a
     * quarter and half-way through it.
     */
    #[Test]
    public function test_replay_of_short_matches_pins_its_attempts_a_quarter_and_half_way_through_the_run(): void
    {
        $pattern = '/(?<=^a{128}|^a{64})a(?:a+\d)?/';
        $pinned = PcreAttemptSteps::pinned($pattern);
        $subject = str_repeat('a', self::WORDS);
        foreach ([51, 85, 102, 170, 192] as $offset) {
            $this->assertSame(0, preg_match($pinned, $subject, $matches, 0, $offset), \sprintf('Oracle: the attempt at %d matches.', $offset));
        }

        $this->assertTrue((new SearchCostReplayer())->replaysShortMatches($pattern, '', 'a', ''));
    }

    /**
     * What the engine shows of the witness: each pinned attempt matches past
     * its start within 64 run words, the retry after a kept empty match
     * reads no further, and the first attempt takes a step at least per run
     * word more than the second.
     */
    private static function engineShows(string $pattern, string $prefix, string $run, string $breaker): bool
    {
        $subject = $prefix.str_repeat($run, self::WORDS).$breaker;
        $pinned = PcreAttemptSteps::pinned($pattern);
        $retry = PcreAttemptSteps::pinned($pattern[0].'(*NOTEMPTY_ATSTART)'.substr($pattern, 1));
        $bound = self::MAX_MATCH_WORDS * \strlen($run);
        $short = true;
        $steps = [];
        foreach ([1, 2] as $quarters) {
            $start = \strlen($prefix) + $quarters * self::QUARTER * \strlen($run);
            $steps[] = PcreAttemptSteps::steps($pinned, $subject, $start);
            if (1 !== preg_match($pinned, $subject, $matches, \PREG_OFFSET_CAPTURE, $start)) {
                $short = false;

                continue;
            }

            $end = $matches[0][1] + \strlen($matches[0][0]);
            $short = $short && $end > $start && $end <= $start + $bound;
            if ('' === $matches[0][0] && 1 === preg_match($retry, $subject, $retried, \PREG_OFFSET_CAPTURE, $end)) {
                $short = $short && $retried[0][1] + \strlen($retried[0][0]) <= $end + $bound;
            }
        }

        return $short && $steps[0] - $steps[1] >= self::QUARTER;
    }
}

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
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The degree of a negative lookahead read again in a loop. Its body runs
 * each time the loop passes it: where the body fails all over the witness,
 * the lookahead holds at every step and costs its own degree at each, so
 * the attempt's degree is the body's plus the loops around it.
 *
 * Engine: PHP 8.4.27 / PCRE2 10.49, without the JIT, the fastest of five
 * runs, on "a" x n . "!". PCRE2 counts a run of one class as one step, so
 * its step counter grows one degree below the time: the counter is pinned
 * in the test, the time in the comments.
 */
final class RedosLookaheadDegreeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRaisedRows')]
    public function test_a_negative_lookahead_in_a_loop_adds_its_degree(string $pattern, int $degree, int $counted, string $suffix): void
    {
        // The counter's growth, n to 2n: about 2^(degree - 1).
        $short = self::steps($pattern, str_repeat('a', 50).$suffix);
        $long = self::steps($pattern, str_repeat('a', 100).$suffix);
        $this->assertGreaterThan(0.85 * 2 ** $counted, $long / $short, \sprintf('Oracle disagrees with %s: %d -> %d steps.', $pattern, $short, $long));
        $this->assertLessThan(1.15 * 2 ** $counted, $long / $short, \sprintf('Oracle disagrees with %s: %d -> %d steps.', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = $pattern.' is "'.$analysis->headline().'"';

        $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertSame(RedosComplexity::Polynomial, $analysis->complexity, $message);
        $this->assertSame($degree, $analysis->degree, $message);
        $this->assertGreaterThanOrEqual($degree, $analysis->upperBoundDegree, $message);

        // The witness is one the bodies fail on everywhere.
        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $message);
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern).'A', $witness->build(20)), $message);
    }

    /**
     * @return iterable<string, array{pattern: string, degree: int, counted: int, suffix: string}>
     */
    public static function provideRaisedRows(): iterable
    {
        // 1.0, 7.3, 50.6 ms on 200, 400, 800: 7.1 and 7.0 per doubling.
        yield 'quadratic body in a loop' => ['pattern' => '/^(?:(?!a*a*b)a)*$/', 'degree' => 3, 'counted' => 2, 'suffix' => '!'];
        // 2.2, 27.4, 324 ms on 80, 160, 320: 12.4 and 11.8 per doubling.
        yield 'cubic body in a loop' => ['pattern' => '/^(?:(?!a*a*a*b)a)*$/', 'degree' => 4, 'counted' => 3, 'suffix' => '!'];
        // 1.0, 6.7, 48.9 ms on 200, 400, 800: the lazy loop turns at each
        // failure of the end.
        yield 'lazy loop' => ['pattern' => '/^(?:(?!a*a*b)a)*?$/', 'degree' => 3, 'counted' => 2, 'suffix' => '!'];
        // 1.0, 6.8, 48.7 ms on 200, 400, 800.
        yield 'under u' => ['pattern' => '/^(?:(?!a*a*b)a)*$/u', 'degree' => 3, 'counted' => 2, 'suffix' => '!'];
        // 1.0, 6.7, 48.3 ms on 200, 400, 800.
        yield 'a loop of two alternatives' => ['pattern' => '/^(?:(?!a*a*b)a|b)*$/', 'degree' => 3, 'counted' => 2, 'suffix' => '!'];
        // 1.2, 7.8, 54.4 ms on 200, 400, 800: the body matches the "!" of
        // the suffix only, where the loop turns no more.
        yield 'body that matches in the suffix only' => ['pattern' => '/^(?:(?!a*a*b|!)a)*$/', 'degree' => 3, 'counted' => 2, 'suffix' => '!'];
        // 1.2, 7.7, 54.9 ms on 200, 400, 800: the body's end never comes.
        yield 'body tied to the end' => ['pattern' => '/^(?:(?!a*a*$)a)*$/', 'degree' => 3, 'counted' => 2, 'suffix' => '!'];
        // 1.3, 7.7, 57.7 ms on 200, 400, 800.
        yield 'possessive run in the body' => ['pattern' => '/^(?:(?!a*a++b)a)*$/', 'degree' => 3, 'counted' => 2, 'suffix' => '!'];
        // 5.5, 63, 796 ms on 100, 200, 400 for the whole search, which
        // retries the attempt at each position: n^3 per attempt.
        yield 'unanchored' => ['pattern' => '/(?:(?!a*a*b)a)*$/', 'degree' => 3, 'counted' => 2, 'suffix' => '!'];
    }

    /**
     * The search read in place runs on a budget of its own: when it runs
     * out, the degree each search proved alone stands, still proven.
     */
    #[Test]
    public function test_a_lookahead_read_in_place_out_of_budget_keeps_the_lower_degree(): void
    {
        $pattern = '/^(?:(?!a*a*b)a)*$/';

        $analysis = (new RedosAnalyzer(options: new RedosOptions(maxSteps: 225)))->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $analysis->headline());
        $this->assertSame(2, $analysis->degree, $analysis->headline());
    }

    /**
     * A lookahead the in-place reading does not read as the engine runs it
     * keeps the degree its own search proves: a positive one, whose body
     * stops at its first success and whose failure ends the iteration, a
     * lookahead that reads no run, a body that matches inside the run, and,
     * the reading being kept to what it can show, a body that matches on
     * the suffix the search read in place rejects with though another would
     * do, or in the prefix before the loop.
     */
    #[Test]
    #[DataProvider('provideKeptRows')]
    public function test_a_lookahead_read_otherwise_keeps_the_degree_of_its_own_search(string $pattern, ?int $degree): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame($degree, $analysis->degree, $pattern.' is "'.$analysis->headline().'"');
    }

    /**
     * @return iterable<string, array{pattern: string, degree: int|null}>
     */
    public static function provideKeptRows(): iterable
    {
        // 0.01, 0.05, 0.17 ms on 200, 400, 800 (3.4 and 3.7 per doubling):
        // the body fails, the lookahead with it, and the loop stops there.
        yield 'positive lookahead whose failure ends the loop' => ['pattern' => '/^(?:(?=a*a*b)a)*$/', 'degree' => 2];
        yield 'no loop around the lookahead' => ['pattern' => '/^(?!a*a*b)a+$/', 'degree' => 2];
        yield 'a loop over other characters' => ['pattern' => '/^(?:(?!a*a*b)x)*$/', 'degree' => 2];
        // 0.31, 1.17, 4.61 ms on 1,000, 2,000, 4,000: the attempt's own
        // quadratic.
        yield 'a lookahead that reads no run' => ['pattern' => '/^(?!b)a*a*$/', 'degree' => 2];
        // 0.01, 0.05, 0.18 ms on 200, 400, 800: the body matches "aa", the
        // loop stops at once.
        yield 'body that matches inside the run' => ['pattern' => '/^(?:(?!a*a*b|aa)a)*$/', 'degree' => 2];
        // On "ab" x n . "!c": 3.2, 25.7, 199 ms on 100, 200, 400, cubic;
        // the body matches "ba" from inside a pump, where the lookahead
        // never runs.
        yield 'body that matches inside the pump' => ['pattern' => '/^(?:(?!(?:ab)*(?:ab)*c|ba)ab)*$/', 'degree' => 2];
        // On "bx" . "a" x n . "!c": 1.2, 7.2, 51.7 ms on 200, 400, 800,
        // cubic; the body matches the "b" before the loop, where the
        // lookahead never runs.
        yield 'body that matches in the prefix' => ['pattern' => '/^bx(?:(?!a*a*c|b)a)*$/', 'degree' => 2];
        // 0.98 and 7.4 ms on 60 and 120, cubic; a positive lookahead
        // anywhere is read otherwise in place.
        yield 'a positive lookahead in the body' => ['pattern' => '/^(?:(?!a*a*(?=b))a)*$/', 'degree' => 2];
        // On "a" x n . "b": 1.0, 6.8, 48.6 ms on 200, 400, 800, cubic; on
        // "a" x n . "!" the body matches, the loop never turns, and the
        // attempt is linear. The search read in place rejects with "!",
        // and no other suffix is tried: the body's degree stands, short.
        yield 'body that matches on the rejecting suffix' => ['pattern' => '/^(?:(?!a*a*!)a)*$/', 'degree' => 2];
    }

    /**
     * The smallest backtrack limit one match attempt runs under: in the
     * interpreter, start optimizations off.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $limit = ini_get('pcre.backtrack_limit');
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));

        try {
            $low = 1;
            $high = 1 << 26;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($unoptimized, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }
    }
}

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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\ConfirmationRunnerInterface;
use PHPRegex\Redos\ConfirmationSample;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The replay tries every candidate witness (bare, the shortest non-empty
 * rejecting suffix, that suffix followed by each required literal) and
 * publishes the one that reproduced; the theoretical verdict publishes the
 * candidate most likely to reproduce. Only an exhausted backtrack limit is
 * replay evidence.
 *
 * Engine: PHP 8.4.26 / PCRE2 10.49, pcre.jit 0, pcre.backtrack_limit
 * 100000 (the confirmed replay's default), preg_match() with $matches.
 */
final class RedosReplayCandidatesTest extends TestCase
{
    /**
     * The most pumps a replay tries (the confirmation runner's bound).
     */
    private const MAX_PUMPS = 64;

    private const REPLAY_BACKTRACK_LIMIT = 100_000;

    private string|false $backtrackLimit = false;

    private string|false $jit = false;

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        $this->jit = ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', (string) self::REPLAY_BACKTRACK_LIMIT);
        ini_set('pcre.jit', '0');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
        if (false !== $this->jit) {
            ini_set('pcre.jit', $this->jit);
        }
    }

    #[Test]
    #[DataProvider('provideReplayCandidates')]
    public function test_confirmed_replay_publishes_the_candidate_that_reproduced(string $pattern, string $input): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $pattern);
        $this->assertTrue($analysis->replayed, \sprintf('%s: "%s" reported as not reproduced, but %s fails', $pattern, $witness->render(), json_encode($input)));
        $this->assertTrue(self::reproduces($pattern, $witness), $pattern.': the published witness '.$witness->render().' does not reproduce');
    }

    /**
     * @return iterable<string, array{pattern: string, input: string}>
     */
    public static function provideReplayCandidates(): iterable
    {
        // Review row 1. "a" x n . "!c" fails from n=15, 16 and 15
        // respectively; "a" x n alone never fails up to n=64.
        yield 'negative lookahead before the required literal' => ['pattern' => '/(a+)+(?!x)c/', 'input' => str_repeat('a', 16).'!c'];
        yield 'negative lookbehind before the required literal' => ['pattern' => '/(?:a+)+(?<!b)c/', 'input' => str_repeat('a', 16).'!c'];
        yield 'positive lookahead on the required literal' => ['pattern' => '/(a+)+(?=c)c/', 'input' => str_repeat('a', 16).'!c'];
        // A10: the required "a" sits in a possessive quantifier inside a
        // mandatory group. " " x 40 . "!a": false, "Backtrack limit
        // exhausted" (already at " " x 20 . "!a"); " " x 10 . "!a": 0.
        yield 'required literal inside nested mandatory groups' => ['pattern' => '/(\s{1,4})+\b((?>x*?)(a)++)/i', 'input' => str_repeat(' ', 40).'!a'];
    }

    #[Test]
    #[DataProvider('provideReplayCandidates')]
    public function test_engine_fact_the_candidate_input_exhausts_the_limit(string $pattern, string $input): void
    {
        $this->assertFalse(@preg_match($pattern, $input, $matches), $pattern);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), $pattern);
    }

    #[Test]
    #[DataProvider('provideLookaroundBeforeLiteral')]
    public function test_engine_fact_bare_pump_never_fails_and_suffixed_pump_fails(string $pattern, int $firstFailing): void
    {
        for ($n = 1; $n <= self::MAX_PUMPS; $n++) {
            $this->assertNotFalse(@preg_match($pattern, str_repeat('a', $n), $matches), $pattern.' on "a" x '.$n);
        }

        $first = null;
        for ($n = 1; null === $first && $n <= self::MAX_PUMPS; $n++) {
            if (false === @preg_match($pattern, str_repeat('a', $n).'!c', $matches) && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error()) {
                $first = $n;
            }
        }
        $this->assertSame($firstFailing, $first, $pattern);
    }

    /**
     * Theoretical mode publishes the rejecting non-empty suffix followed by
     * the required literal: the candidate that reproduces on the engine.
     */
    #[Test]
    #[DataProvider('provideLookaroundBeforeLiteral')]
    public function test_theoretical_witness_carries_the_rejecting_suffix_and_the_required_literal(string $pattern, int $firstFailing): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $pattern);
        $this->assertStringEndsWith('c', $witness->suffix, $pattern.': '.$witness->render());
        $this->assertGreaterThanOrEqual(2, \strlen($witness->suffix), $pattern.': no rejecting character before the literal in '.$witness->render());
        $this->assertTrue(self::reproduces($pattern, $witness), $pattern.': the published witness '.$witness->render().' does not reproduce');
    }

    /**
     * @return iterable<string, array{pattern: string, firstFailing: int}>
     */
    public static function provideLookaroundBeforeLiteral(): iterable
    {
        yield 'negative lookahead before the required literal' => ['pattern' => '/(a+)+(?!x)c/', 'firstFailing' => 15];
        yield 'negative lookbehind before the required literal' => ['pattern' => '/(?:a+)+(?<!b)c/', 'firstFailing' => 16];
        yield 'positive lookahead on the required literal' => ['pattern' => '/(a+)+(?=c)c/', 'firstFailing' => 15];
    }

    /**
     * B6: a verdict from the search that refuses an empty success at the
     * attempt start is replayed with the call that has that behaviour,
     * preg_match() without $matches, and the evidence line says so. The
     * line is pinned through the one renderer every consumer uses, not
     * through a field: the confirmation already carries the evidence.
     */
    #[Test]
    #[DataProvider('provideEmptySuccessRefusedWitnesses')]
    public function test_empty_match_retry_verdict_is_replayed_without_matches(string $pattern, string $input): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $pattern);
        $this->assertTrue($analysis->replayed, \sprintf('%s: "%s" reported as not reproduced, but %s fails without $matches', $pattern, $witness->render(), json_encode($input)));
        $this->assertStringContainsString('preg_match() without $matches', implode("\n", RedosVerdict::evidence($analysis)), $pattern);
        $this->assertTrue(self::reproduces($pattern, $witness, false), $pattern.': the published witness '.$witness->render().' does not reproduce without $matches');
    }

    /**
     * Engine, at the replay's backtrack limit 100000: without $matches the
     * input fails with "Backtrack limit exhausted"; with $matches it
     * matches at once. The theoretical witnesses "aa" x n . "00", "!" x n
     * and "\n" x n first fail without $matches at n = 11, 16 and 9, and
     * never with $matches up to n = 64.
     */
    #[Test]
    #[DataProvider('provideEmptySuccessRefusedWitnesses')]
    public function test_engine_fact_the_input_fails_only_without_matches(string $pattern, string $input): void
    {
        $this->assertSame(1, preg_match($pattern, $input, $matches), $pattern.' with $matches');
        $this->assertFalse(@preg_match($pattern, $input), $pattern.' without $matches');
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, input: string}>
     */
    public static function provideEmptySuccessRefusedWitnesses(): iterable
    {
        yield 'lazy optional group ending on a boundary' => ['pattern' => '/(([ab].?\s?)*\b)??/', 'input' => str_repeat('aa', 20).'00'];
        yield 'lazy loop of lazy runs before a start anchor' => ['pattern' => '/^(!+?)*?^/', 'input' => str_repeat('!', 24)];
        yield 'nested nullable loops under a lazy optional group' => ['pattern' => '/(((\s??)+)*.)??/m', 'input' => str_repeat("\n", 20)];
    }

    /**
     * H1: under a recursion limit of 1 every run of /(a+)+$/ fails with
     * "Recursion limit exhausted" (engine: "a!" already), never with an
     * exhausted backtrack limit. That is no replay of the witness.
     */
    #[Test]
    public function test_recursion_limit_is_no_replay_evidence(): void
    {
        $analysis = (new RedosAnalyzer())->analyze(
            '/(a+)+$/',
            RedosSeverity::Low,
            RedosMode::Confirmed,
            new ConfirmationOptions(recursionLimit: 1),
        );

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertFalse($analysis->replayed, 'replayed on the evidence of '.($analysis->confirmation->evidence ?? 'nothing'));
    }

    #[Test]
    public function test_engine_fact_a_recursion_limit_of_one_fails_before_backtracking(): void
    {
        ini_set('pcre.recursion_limit', '1');

        try {
            // (*NO_JIT): PHP keeps a pattern compiled with the JIT by an earlier test, and the JIT ignores pcre.recursion_limit.
            $this->assertFalse(@preg_match('/(*NO_JIT)(a+)+$/', 'a!', $matches));
            $this->assertSame(\PREG_RECURSION_LIMIT_ERROR, preg_last_error());
        } finally {
            ini_restore('pcre.recursion_limit');
        }
    }

    /**
     * H1, for a runner other than the library's: a confirmation whose only
     * failing sample is a recursion-limit error does not make the verdict
     * replayed.
     */
    #[Test]
    public function test_recursion_limit_sample_from_a_runner_is_no_replay_evidence(): void
    {
        $runner = new class implements ConfirmationRunnerInterface {
            public function confirm(string $regex, RedosAnalysis $analysis, ?ConfirmationOptions $options = null): Confirmation
            {
                return new Confirmation(
                    true,
                    [new ConfirmationSample(2, 0.1, 'a!', \PREG_RECURSION_LIMIT_ERROR, 'Recursion limit exhausted')],
                    '0',
                    100_000,
                    1,
                    1,
                    50.0,
                    false,
                    'recursion_limit',
                );
            }
        };

        $analysis = (new RedosAnalyzer(confirmationRunner: $runner))->analyze('/(a+)+$/', RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertNotTrue($analysis->replayed);
    }

    /**
     * Whether a build of the witness exhausts the replay's backtrack limit
     * within the runner's bound on pumps, in the call form given.
     */
    private static function reproduces(string $pattern, RedosWitness $witness, bool $withMatches = true): bool
    {
        for ($n = 1; $n <= self::MAX_PUMPS; $n++) {
            $result = $withMatches ? @preg_match($pattern, $witness->build($n), $matches) : @preg_match($pattern, $witness->build($n));
            if (false === $result && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error()) {
                return true;
            }
        }

        return false;
    }
}

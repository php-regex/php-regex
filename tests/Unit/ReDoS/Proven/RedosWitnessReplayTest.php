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

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The witness of an exponential verdict, replayed on the running PCRE2.
 *
 * The lengths are measured on PHP 8.4.26 / PCRE2 10.49 with
 * pcre.backtrack_limit 1000000; the assertions only require a failure below
 * 64 bytes so that they hold from PCRE2 10.40 to 10.49.
 */
final class RedosWitnessReplayTest extends TestCase
{
    private const MAX_INPUT_LENGTH = 64;

    private string|false $backtrackLimit = false;

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
    }

    #[Test]
    #[DataProvider('provideSelfContainedExponentialPatterns')]
    public function test_witness_replay_fails_preg_match_before_the_length_bound(string $pattern): void
    {
        $witness = (new RedosAnalyzer())->analyze($pattern)->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $pattern);

        $failingLength = null;
        for ($n = 1; \strlen($witness->build($n)) <= self::MAX_INPUT_LENGTH; $n++) {
            if (false === @preg_match($pattern, $witness->build($n))) {
                $failingLength = \strlen($witness->build($n));

                break;
            }
        }

        $this->assertNotNull($failingLength, \sprintf('%s: no witness up to %d bytes makes preg_match() fail', $pattern, self::MAX_INPUT_LENGTH));
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), $pattern);
    }

    /**
     * Exponential patterns of the literature table whose witness needs no
     * required literal.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideSelfContainedExponentialPatterns(): iterable
    {
        yield 'nested plus, a…a! fails at 20 bytes' => ['/(a+)+$/'];
        yield 'identical alternatives, a…a! fails at 20 bytes' => ['/(a|a)*$/'];
        yield 'overlapping alternatives, a…a! fails at 29 bytes' => ['/(a|aa)*$/'];
        yield 'word then optional space, a…a! fails at 20 bytes' => ['/(\w+\s?)+$/'];
        yield 'anchored digits, 1…1! fails at 20 bytes' => ['/^(\d+)*$/'];
        yield 'lazy inner quantifier, a…a! fails at 20 bytes' => ['/(a+?)+$/'];
        yield 'bounded repeat unrolled, a…a! fails at 21 bytes' => ['/(a{1,5})+$/'];
        yield 'bounded repeat abstracted, a…a! fails at 20 bytes' => ['/(a{1,20})+$/'];
        // Only é is ambiguous: é…é! fails at 19 pumps (39 bytes), a…a! never fails.
        yield 'word class and e acute under u' => ['/(\w|é)+$/u'];
        // a…a\n! fails at 19 pumps: the rejecting suffix needs a newline.
        yield 'dot before a newline' => ['/(.+)+$/'];
    }

    #[Test]
    #[DataProvider('provideExponentialPatterns')]
    public function test_confirmed_mode_replays_every_exponential_witness(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $this->assertTrue($analysis->replayed, $pattern);
        $this->assertSame(RedosConfidence::High, $analysis->confidenceLevel(), $pattern);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity, $pattern);

        // The published witness is the one replayed: it reproduces as it stands.
        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $pattern);
        $reproduced = false;
        for ($n = 1; !$reproduced && \strlen($witness->build($n)) <= self::MAX_INPUT_LENGTH; $n++) {
            $reproduced = false === @preg_match($pattern, $witness->build($n));
        }
        $this->assertTrue($reproduced, $pattern.': the published witness does not reproduce');
    }

    /**
     * a…a alone never fails: the bare witness does not reproduce, so the
     * replay appends a character outside the pumped class, then the required
     * literal. a…a!b fails at 21 bytes on PCRE2 10.49.
     */
    #[Test]
    public function test_confirmed_replay_appends_a_rejecting_character_then_the_required_literal(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a+)+b/', RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertTrue($analysis->replayed);
        $this->assertInstanceOf(RedosWitness::class, $analysis->witness);
        $this->assertMatchesRegularExpression('/^a+$/', $analysis->witness->pump);
        $this->assertSame('!b', $analysis->witness->suffix);
    }

    /**
     * Every exponential pattern of the literature table, the two whose
     * replay needs the required literal "b" included.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideExponentialPatterns(): iterable
    {
        yield from self::provideSelfContainedExponentialPatterns();
        yield 'required literal after the loop' => ['/(a+)+b/'];
        yield 'lookahead body with a required literal' => ['/(?=(a+)+b)/'];
    }

    /**
     * Engine fact the replay of /(a+)+b/ and /(?=(a+)+b)/ relies on: without
     * a "b" anywhere in the subject PCRE gives up before backtracking; with
     * it, a…a!b fails at 19 pumps (21 bytes) on 10.49.
     */
    #[Test]
    #[DataProvider('provideRequiredLiteralPatterns')]
    public function test_required_literal_must_be_in_the_replayed_input(string $pattern): void
    {
        $withoutLiteral = null;
        $withLiteral = null;
        for ($n = 1; $n <= self::MAX_INPUT_LENGTH - 2; $n++) {
            $withoutLiteral ??= false === @preg_match($pattern, str_repeat('a', $n).'!') ? $n : null;
            $withLiteral ??= false === @preg_match($pattern, str_repeat('a', $n).'!b') ? $n : null;
        }

        $this->assertNull($withoutLiteral, $pattern.' failed without the required literal');
        $this->assertNotNull($withLiteral, $pattern.' did not fail with the required literal');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRequiredLiteralPatterns(): iterable
    {
        yield 'required literal after the loop' => ['/(a+)+b/'];
        yield 'lookahead body' => ['/(?=(a+)+b)/'];
    }

    /**
     * The model ignores the lookahead's constraint: (?=b) then a+ can never
     * both hold, so PCRE never backtracks (a…a! and a…a!b never fail up to
     * 64 bytes on 10.49). The verdict stays exponential, unreplayed.
     */
    #[Test]
    public function test_witness_the_engine_defuses_is_not_replayed(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(?=b)(a+)+$/', RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertFalse($analysis->replayed);
        $this->assertSame(RedosConfidence::Medium, $analysis->confidenceLevel());
    }

    /**
     * The backtrack counter grows x4 every two characters for /(a+)+$/ on
     * a^n! (JIT off): the smallest pcre.backtrack_limit that still lets
     * preg_match() finish is 2,560 at n=10, 10,240 at 12, 40,960 at 14 and
     * 163,840 at 16 on 10.49. A polynomial pattern gives no such curve:
     * /a*a*$/ needs 102 / 202 / 402 at n = 100 / 200 / 400.
     */
    #[Test]
    public function test_exponential_backtrack_curve_grows_four_times_every_two_characters(): void
    {
        $previous = self::smallestPassingBacktrackLimit('/(*NO_JIT)(a+)+$/', str_repeat('a', 10).'!');
        foreach ([12, 14, 16] as $n) {
            $current = self::smallestPassingBacktrackLimit('/(*NO_JIT)(a+)+$/', str_repeat('a', $n).'!');
            $this->assertEqualsWithDelta(4.0, $current / $previous, 0.05, 'n='.$n);
            $previous = $current;
        }
    }

    #[Test]
    #[DataProvider('providePolynomialPatterns')]
    public function test_confirmed_mode_does_not_replay_a_polynomial_verdict(string $pattern, int $degree, RedosSeverity $severity): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Polynomial, $analysis->complexity);
        $this->assertSame($degree, $analysis->degree);
        $this->assertNull($analysis->replayed);
        $this->assertSame($severity, $analysis->severity);
        $this->assertSame(RedosConfidence::Medium, $analysis->confidenceLevel());
    }

    /**
     * @return iterable<string, array{string, int, RedosSeverity}>
     */
    public static function providePolynomialPatterns(): iterable
    {
        yield 'degree 2' => ['/a*a*$/', 2, RedosSeverity::Medium];
        // Trips the backtrack limit at n=1411 on 10.49, yet stays unreplayed: polynomial.
        yield 'degree 3' => ['/a*a*a*$/', 3, RedosSeverity::High];
    }

    private static function smallestPassingBacktrackLimit(string $pattern, string $subject): int
    {
        $low = 1;
        $high = 10_000_000;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            ini_set('pcre.backtrack_limit', (string) $middle);
            if (false === @preg_match($pattern, $subject)) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}

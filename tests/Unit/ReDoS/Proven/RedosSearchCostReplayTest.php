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

use PHPRegex\Redos\Internal\SearchCostReplayer;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\LibrarySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the confirmed mode makes of a search-cost witness: the step replay
 * in the interpreter. The JIT is not measured: a pattern under the JIT can
 * crash PHP (PCRE2 10.40 to 10.49), so the replay runs it through the
 * engine, which never turns the JIT on.
 *
 * Oracle: PHP 8.4.26, PCRE2 10.49.
 */
final class RedosSearchCostReplayTest extends TestCase
{
    /**
     * The step replay counts the attempts inside the run after the prefix,
     * with the breaker holding the last required code unit, and through a
     * possessive any-character repeat the run does not read.
     */
    #[Test]
    #[DataProvider('provideWitnessesTheReplayCounts')]
    public function test_confirmed_mode_replays_the_witness(string $pattern): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertTrue($cost->replayed, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideWitnessesTheReplayCounts(): iterable
    {
        yield 'trim regex, with a prefix' => ['pattern' => '/^\s+|\s+$/'];
        yield 'star loop before a fat arrow' => ['pattern' => '/\s*=>/'];
        yield 'e-mail, a run word of two classes' => ['pattern' => '/\b[\w.%+-]+@[\w.-]+\.[a-z]{2,}\b/i'];
        yield 'possessive dot-star in another alternative' => ['pattern' => '/\s+$|x.*+y/s'];
        // An atomic or possessive repeat of more than one character set: the
        // counter sees the attempts grow.
        yield 'possessive repeat of a two-character word' => ['pattern' => '/(?:ab)++c/'];
        yield 'atomic loop body repeated' => ['pattern' => '/(?>a+b)+c/'];
        // The pinned offsets fall on whole run words of three characters:
        // 33.8 / 159.8 / 594.6 ms on "abc"x5k/10k/20k."!d", pcre.jit=0.
        yield 'three-character run word' => ['pattern' => '/(?:abc)+d/'];
    }

    /**
     * The model reads these witnesses exactly, so they stay reported when the
     * replay cannot count them: replayed false.
     *
     * The step counter does not see inside a possessive loop: /a++b/ costs
     * 6.1 / 23.8 ms on "a"x5k/10k."!b" with pcre.jit=0, yet 6 steps per
     * attempt. The "_" delimiter and a body holding every other delimiter
     * leave no place for the counting verbs (the pattern itself costs 23.8 /
     * 94.2 ms on "a"x10k/20k, pcre.jit=0).
     */
    #[Test]
    #[DataProvider('provideWitnessesTheReplayCannotCount')]
    public function test_search_cost_stays_reported_when_the_replay_cannot_count_it(string $pattern): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame(2, $cost->degree, $pattern);
        $this->assertFalse($cost->replayed, $pattern);
        $this->assertArrayNotHasKey('jit_linear', $cost->toArray(), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideWitnessesTheReplayCannotCount(): iterable
    {
        yield 'possessive loop' => ['pattern' => '/a++b/'];
        yield 'no place for the counting verbs' => ['pattern' => "_a+b|[\x01#~%!@;,)}>[]_"];
    }

    /**
     * The replay confirms nothing the search matches: with the prefix "x",
     * "^x" matches at the subject start, and only there (preg_match gives 1
     * at offset 0 on "x"."a"x256."!b", 0 from offset 1). With "y" no
     * attempt matches and each one reads the rest of the run.
     */
    #[Test]
    public function test_replay_rejects_a_witness_the_search_matches_at_its_start(): void
    {
        $replayer = new SearchCostReplayer();

        $this->assertTrue($replayer->replays('/^x|a+b/', 'y', 'a', '!b'), 'control: the attempts inside the run are counted');
        $this->assertFalse($replayer->replays('/^x|a+b/', 'x', 'a', '!b'));
    }

    /**
     * Attempts that each stop at a bound do not grow with the rest of the
     * run, though they take more than a step per run word between them:
     * /\s{1,150}$/ and /\s{1,180}$/ are linear (15.9 / 32.1 / 64.2 ms and
     * 19.0 / 38.0 / 76.2 ms on " "x8k/16k/32k."!", pcre.jit=0).
     */
    #[Test]
    #[DataProvider('provideBoundedRepeats')]
    public function test_replay_rejects_attempts_bounded_by_a_repeat(string $pattern): void
    {
        $replayer = new SearchCostReplayer();

        $this->assertTrue($replayer->replays('/\s+$/', '', ' ', '!'), 'control: unbounded attempts are counted');
        $this->assertFalse($replayer->replays($pattern, '', ' ', '!'), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBoundedRepeats(): iterable
    {
        yield 'bound of 150' => ['pattern' => '/\s{1,150}$/'];
        yield 'bound of 180' => ['pattern' => '/\s{1,180}$/'];
    }

    /**
     * A lookahead the model does not read: greedy "a+" always stops before
     * a character other than "a" or at the end, where the lookahead holds,
     * so the first attempt matches every run. The engine matches each witness
     * the model proposes: none is reported, in either mode.
     */
    #[Test]
    public function test_search_cost_is_not_reported_when_the_engine_matches_the_witness(): void
    {
        $pattern = '/a+(?=[^a]|$)/';
        $this->assertSame(1, preg_match($pattern, str_repeat('a', 100)));
        $this->assertSame(1, preg_match($pattern, str_repeat('a', 100).'!'));

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyze($pattern)->searchCost);
        $this->assertNull($analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)->searchCost);
    }

    /**
     * An attempt that succeeds inside the run ends the search: on
     * "ab"x1000."!c" the attempt at offset 1 matches "ba".
     */
    #[Test]
    public function test_search_cost_is_not_reported_when_an_attempt_matches_inside_the_run(): void
    {
        $this->assertSame(1, preg_match('/(?:ab)+c|ba/', str_repeat('ab', 1000).'!c', $matches, \PREG_OFFSET_CAPTURE));
        $this->assertSame(1, $matches[0][1]);

        $analysis = (new RedosAnalyzer())->analyze('/(?:ab)+c|ba/');
        $this->assertNull($analysis->searchCost);
        // The search proof gave up on the run, not on the attempt.
        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity);
    }

    /**
     * The pinned offsets fall on character boundaries of a multibyte run:
     * the replay confirms the witness the model found, unchanged.
     */
    #[Test]
    public function test_confirmed_mode_replays_the_witness_the_model_found_on_a_multibyte_run(): void
    {
        $analyzer = new RedosAnalyzer();
        $found = $analyzer->analyze('/[é]+$/u')->searchCost;
        $replayed = $analyzer->analyze('/[é]+$/u', RedosSeverity::Low, RedosMode::Confirmed)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $found);
        $this->assertInstanceOf(RedosSearchCost::class, $replayed);
        $this->assertTrue($replayed->replayed);
        $this->assertSame($found->toArray()['witness'], $replayed->toArray()['witness']);
    }

    /**
     * An atomic repeat of any character moves to the end of the subject in
     * one step, a capture inside the atomic group or not: 0.5 / 0.9 ms on
     * "a"x10k/20k with pcre.jit=0, against 537.6 / 2118.7 ms for the same
     * capture without the atomic group (reported).
     */
    #[Test]
    public function test_search_cost_is_not_reported_through_an_atomic_any_character_repeat(): void
    {
        $analyzer = new RedosAnalyzer();

        $this->assertNull($analyzer->analyze('/a(?>(.*))[xy]/s')->searchCost);
        $this->assertInstanceOf(RedosSearchCost::class, $analyzer->analyze('/a(.*)[xy]/s')->searchCost);
    }

    /**
     * The step replay runs whatever the JIT setting, and reports nothing
     * about the JIT.
     */
    #[Test]
    public function test_the_step_replay_runs_when_the_jit_is_off(): void
    {
        $previous = \ini_get('pcre.jit');
        ini_set('pcre.jit', '0');

        try {
            $cost = (new RedosAnalyzer())->analyze('/\s+$/', RedosSeverity::Low, RedosMode::Confirmed)->searchCost;
        } finally {
            ini_set('pcre.jit', false === $previous ? '1' : $previous);
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertTrue($cost->replayed);
        $this->assertArrayNotHasKey('jit_linear', $cost->toArray());
    }

    /**
     * With the JIT on, confirmed mode still never runs the pattern under it:
     * the JIT runs out of its stack on /(a|b)+c/ over "a"x8000."!c" ("JIT
     * stack limit exhausted"), and some pattern and subject pairs crash PHP
     * under it (PCRE2 10.40 to 10.49). The replay calls no preg function of
     * its own, so every run goes through the engine and its "(*NO_JIT)".
     */
    #[Test]
    public function test_confirmed_mode_never_runs_the_pattern_under_the_jit(): void
    {
        $previous = \ini_get('pcre.jit');
        ini_set('pcre.jit', '1');

        try {
            $cost = (new RedosAnalyzer())->analyze('/(a|b)+c/', RedosSeverity::Low, RedosMode::Confirmed)->searchCost;
        } finally {
            ini_set('pcre.jit', false === $previous ? '1' : $previous);
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertTrue($cost->replayed);
        $this->assertFalse((new \ReflectionClass(RedosSearchCost::class))->hasProperty('jitLinear'));
        $this->assertSame([], LibrarySource::functionCalls(LibrarySource::files()['src/Redos/Internal/SearchCostReplayer.php'], '/^preg_/'));
    }
}

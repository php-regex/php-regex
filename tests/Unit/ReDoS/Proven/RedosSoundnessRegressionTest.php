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
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Counterexamples to a "safe (proven)" verdict: for each pattern, one input
 * makes a single preg_match() call exhaust the backtrack limit.
 *
 * Engine: PHP 8.4.26 / PCRE2 10.49, pcre.jit 0, pcre.backtrack_limit
 * 1000000. Every row is called as written in the row: with a $matches
 * argument, or without one when the row says so. Each comment quotes what
 * the engine answered under those conditions.
 */
final class RedosSoundnessRegressionTest extends TestCase
{
    /**
     * The most pumps a replay tries (the confirmation runner's bound).
     */
    private const MAX_PUMPS = 64;

    private string|false $backtrackLimit = false;

    private string|false $jit = false;

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        $this->jit = ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '1000000');
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
    #[DataProvider('provideCounterexamples')]
    public function test_soundness_counterexample_is_not_proven_safe(string $pattern, string $input, bool $withMatches, int $offset = 0): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse(
            $analysis->isProvenSafe(),
            \sprintf('%s is "%s", but %s exhausts the backtrack limit (%s $matches, offset %d)', $pattern, $analysis->headline(), self::describe($input), $withMatches ? 'with' : 'without', $offset),
        );
    }

    #[Test]
    #[DataProvider('provideCounterexamples')]
    public function test_engine_fact_counterexample_exhausts_the_backtrack_limit(string $pattern, string $input, bool $withMatches, int $offset = 0): void
    {
        // PHP has no call without $matches at a non-zero offset: a named
        // offset argument passes $matches implicitly.
        $this->assertTrue($withMatches || 0 === $offset, 'a row without $matches runs at offset 0');
        $result = $withMatches ? @preg_match($pattern, $input, $matches, 0, $offset) : @preg_match($pattern, $input);

        $this->assertFalse($result, $pattern.' on '.self::describe($input));
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), $pattern.' on '.self::describe($input));
    }

    /**
     * A7: without $matches, PHP retries after an empty match at the start
     * (NOTEMPTY_ATSTART | ANCHORED); with $matches the same call matches at
     * once. The engine figures below are why the rows run without $matches.
     */
    #[Test]
    #[DataProvider('provideEmptyMatchRetryCounterexamples')]
    public function test_engine_fact_the_matches_argument_changes_the_outcome(string $pattern, string $input): void
    {
        $this->assertSame(1, preg_match($pattern, $input, $matches), $pattern.' with $matches');
        $this->assertFalse(@preg_match($pattern, $input), $pattern.' without $matches');
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    /**
     * @return iterable<string, array{pattern: string, input: string}>
     */
    public static function provideEmptyMatchRetryCounterexamples(): iterable
    {
        foreach (self::provideCounterexamples() as $name => $row) {
            if (!$row['withMatches']) {
                yield $name => ['pattern' => $row['pattern'], 'input' => $row['input']];
            }
        }
    }

    /**
     * The {,n} rows need PCRE2 10.43 or later: before it, "{,17}" is a
     * literal and the pattern means something else.
     *
     * @return iterable<string, array{pattern: string, input: string, withMatches: bool, offset?: int}>
     */
    public static function provideCounterexamples(): iterable
    {
        $upperBoundOnly = version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=');
        foreach (self::counterexamples() as $name => $row) {
            if ($upperBoundOnly || !str_contains($row['pattern'], '{,')) {
                yield $name => $row;
            }
        }
    }

    /**
     * A4 (the lookbehind forms) and A6: constructs outside the model. A
     * verdict on them is heuristic, never proven.
     */
    #[Test]
    #[DataProvider('provideOutOfModelPatterns')]
    public function test_out_of_model_construct_gives_a_heuristic_verdict(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern.' is "'.$analysis->headline().'"');
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideOutOfModelPatterns(): iterable
    {
        yield 'A4 non-atomic lookahead, short form' => ['pattern' => '/^(?*(a+)+)\w*\W/'];
        yield 'A4 non-atomic lookahead, napla' => ['pattern' => '/^(*napla:(a+)+)\w*\W/'];
        yield 'A4 non-atomic lookahead, long form' => ['pattern' => '/^(*non_atomic_positive_lookahead:(a+)+)\w*\W/'];
        // Both compile on PCRE2 10.49 (preg_match on '' returns 0).
        yield 'A4 non-atomic lookbehind, short form' => ['pattern' => '/(?<*a|ab)c/'];
        yield 'A4 non-atomic lookbehind, naplb' => ['pattern' => '/(*naplb:a|ab)c/'];
        yield 'A6 extended-more flag at the start' => ['pattern' => '/(?xx)^(?:[^x\x20]+)+(?:[ x]|$)/i'];
        yield 'A6 extended-more flag scoped to a group' => ['pattern' => '/^(?:[^x\x20]+)+(?xx:[ x]|$)/i'];
        yield 'A6 extended-more flag on a pattern without a class' => ['pattern' => '/(?xx)a b/'];
    }

    /**
     * A2: two runs of the same set inside one loop, before a character the
     * runs do not match. Engine, anchored /^(?:a+a+\s)+/ on "a" x n, JIT
     * off: 0.3 ms at 1,000, 1.0 ms at 2,000, 4.0 ms at 4,000 (quadratic per
     * attempt; unanchored it is cubic over the retries, out of scope).
     */
    #[Test]
    public function test_polynomial_ambiguity_inside_one_loop_is_counted(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(?:a+a+\s)+/');

        $this->assertSame(RedosComplexity::Polynomial, $analysis->complexity, $analysis->headline());
        $this->assertNotNull($analysis->degree);
        $this->assertGreaterThanOrEqual(2, $analysis->degree);
    }

    /**
     * B1: the loops of a branch PCRE tries and abandons count toward the
     * degree. Engine, /(?:a(?:a*a*\Bb)?)+/ on "a" x n, JIT off: 0.3 ms at
     * 50, 2.6 ms at 100 (x8 per doubling: cubic), false "Backtrack limit
     * exhausted" at 200.
     */
    #[Test]
    public function test_abandoned_branch_loops_count_toward_the_degree(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(?:a(?:a*a*\Bb)?)+/');

        $undercounted = RedosProof::Proven === $analysis->proof && (
            RedosComplexity::Linear === $analysis->complexity
            || (RedosComplexity::Polynomial === $analysis->complexity && ($analysis->degree ?? 2) < 3)
        );
        $this->assertFalse($undercounted, '/(?:a(?:a*a*\Bb)?)+/ is "'.$analysis->headline().'"');
    }

    #[Test]
    public function test_engine_fact_abandoned_branch_loops_exhaust_the_limit_at_two_hundred(): void
    {
        $this->assertSame(1, preg_match('/(?:a(?:a*a*\Bb)?)+/', str_repeat('a', 150), $matches));
        $this->assertFalse(@preg_match('/(?:a(?:a*a*\Bb)?)+/', str_repeat('a', 200), $matches));
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    /**
     * A proven verdict never rests on an over-approximation: a cycle whose
     * back-edges all come from a bounded repeat analysed as {m,} gives a
     * heuristic verdict, the abstraction listed. The lookbehind rows are the
     * second-pass hypothesis on lookbehind bodies, confirmed: a lookbehind
     * body is bounded, yet it is reported "Exponential (proven)".
     */
    #[Test]
    #[DataProvider('provideBoundedRepeatAbstractions')]
    public function test_proof_never_rests_on_a_bounded_repeat_abstraction(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern.' is "'.$analysis->headline().'"');
        $this->assertNotSame([], $analysis->abstractions, $pattern);
    }

    /**
     * Engine: 3,888 inputs each (prefixes '', '1', 'a', 'x', "a" x 12,
     * '1111'; pumps '1', '12', 'a', 'ax', 'x', '1.', ':', 'aac', '0';
     * suffixes '', '!', ':', ':1!', 'x', 'c', 'cd', 'xz', '!x'; n from 4 to
     * 200), with and without $matches: none fails.
     */
    #[Test]
    #[DataProvider('provideBoundedRepeatAbstractions')]
    public function test_engine_fact_bounded_repeat_abstraction_never_fails(string $pattern): void
    {
        foreach (['', '1', 'a', 'x', str_repeat('a', 12), '1111'] as $prefix) {
            foreach (['1', '12', 'a', 'ax', 'x', '1.', ':', 'aac', '0'] as $pump) {
                foreach (['', '!', ':', ':1!', 'x', 'c', 'cd', 'xz', '!x'] as $suffix) {
                    foreach ([4, 8, 12, 16, 24, 32, 64, 200] as $count) {
                        $input = $prefix.str_repeat($pump, $count).$suffix;
                        $this->assertNotFalse(@preg_match($pattern, $input, $matches), $pattern.' on '.self::describe($input));
                        $this->assertNotFalse(@preg_match($pattern, $input), $pattern.' on '.self::describe($input).' without $matches');
                    }
                }
            }
        }
    }

    /**
     * The lookbehind rows need PCRE2 10.43 or later: before it, a
     * lookbehind of variable length does not compile.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBoundedRepeatAbstractions(): iterable
    {
        yield 'four digit groups before a colon' => ['pattern' => '/^(\d{1,3}){4}:\d+$/'];
        yield 'four digit groups' => ['pattern' => '/^(?:\d{1,3}){4}$/'];

        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '<')) {
            return;
        }

        yield 'ambiguous bounded repeat in a lookbehind before a literal' => ['pattern' => '/(?<=(?:a|a){1,12}c)d/'];
        yield 'ambiguous bounded repeat in a lookbehind between literals' => ['pattern' => '/x(?<=(?:\w|\w){1,14}x)y/'];
        yield 'ambiguous bounded repeat in a lookbehind inside a loop' => ['pattern' => '/(?:x(?<=(?:a|a){1,8}x))+$/'];
    }

    /**
     * A cycle through a real unbounded loop keeps the proof. Engine:
     * "a" x 20 . "!" gives false, "Backtrack limit exhausted".
     */
    #[Test]
    public function test_cycle_through_an_unbounded_loop_keeps_the_proof(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a{1,20})+$/');

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertFalse(@preg_match('/(a{1,20})+$/', str_repeat('a', 20).'!', $matches));
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    /**
     * H2: a proven exponential verdict on a pattern using ^ under /m, with
     * no abstraction listed, must hold on the engine. When ^ under /m is
     * analysed as "may match anywhere", the verdict is about the abstracted
     * model and the abstraction must be listed (or the verdict heuristic).
     *
     * Engine, pumps "\n", "a", "a\n", "\na", "\n\n", "aa", "a\n\n", " ",
     * "\n " with prefixes '', 'a', "\n" and suffixes '', '!', 'b', ' ' at
     * n = 10, 20, 30, 40: none of these patterns fails.
     */
    #[Test]
    #[DataProvider('provideMultilineCaretWithoutBlowUp')]
    public function test_caret_under_multiline_is_exact_or_listed_in_abstractions(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertTrue(
            RedosProof::Proven !== $analysis->proof || $analysis->isProvenSafe() || [] !== $analysis->abstractions,
            \sprintf('%s is "%s" with no abstraction listed, but the engine never fails on it', $pattern, $analysis->headline()),
        );
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideMultilineCaretWithoutBlowUp(): iterable
    {
        yield 'caret on one branch only' => ['pattern' => '/(?:^a|a)+\z/m'];
        yield 'caret at the start of the loop body' => ['pattern' => '/(?:^\w+\n?)+!/m'];
        yield 'caret at the end of the loop body' => ['pattern' => '/(?:\w+\n?^)+!/m'];
        yield 'caret before a word run and an optional space' => ['pattern' => '/(?:^\w+\s?)+\z/m'];
    }

    /**
     * H2: the engine does fail on these (false at n=20 on "\n" x n . "!"),
     * but only through a newline: a witness built from a space or a word
     * character never reproduces. A proven exponential verdict without
     * abstractions must replay.
     */
    #[Test]
    #[DataProvider('provideMultilineCaretWithBlowUp')]
    public function test_caret_under_multiline_proven_witness_reproduces(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is proven safe, but "\n" x 20 . "!" exhausts the limit');
        if (RedosProof::Proven !== $analysis->proof || [] !== $analysis->abstractions) {
            // Not a proven claim about the pattern as written.
            return;
        }

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $this->assertTrue($analysis->replayed, \sprintf('%s: the proven witness %s does not reproduce', $pattern, $analysis->witness?->render() ?? 'null'));

        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);
        $limit = $analysis->confirmation->backtrackLimit ?? 100_000;
        ini_set('pcre.backtrack_limit', (string) $limit);
        $reproduced = false;
        for ($n = 1; !$reproduced && $n <= self::MAX_PUMPS; $n++) {
            $reproduced = false === @preg_match($pattern, $witness->build($n), $matches)
                && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error();
        }
        $this->assertTrue($reproduced, $pattern.': '.$witness->render().' does not reproduce');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideMultilineCaretWithBlowUp(): iterable
    {
        yield 'caret after a space-class character' => ['pattern' => '/(?:\s^|\s)+\z/m'];
        yield 'caret before a space-class character' => ['pattern' => '/(?:^\s|\s)+\z/m'];
    }

    #[Test]
    public function test_engine_fact_multiline_caret_blows_up_only_through_a_newline(): void
    {
        $this->assertFalse(@preg_match('/(?:\s^|\s)+\z/m', str_repeat("\n", 20).'!', $matches));
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
        $this->assertSame(0, preg_match('/(?:\s^|\s)+\z/m', str_repeat(' ', 40).'!', $matches));
        $this->assertSame(0, preg_match('/(?:^a|a)+\z/m', str_repeat('a', 40).'!', $matches));
        $this->assertSame(0, preg_match('/(?:^\w+\n?)+!/m', str_repeat("a\n", 40), $matches));
    }

    /**
     * @return iterable<string, array{pattern: string, input: string, withMatches: bool, offset?: int}>
     */
    private static function counterexamples(): iterable
    {
        // A1: the witness search tried one class of the pump label and the
        // shortest prefix only. All: false, "Backtrack limit exhausted".
        yield 'A1 digit loop before a literal digit' => ['pattern' => '/^(\d+)+0/', 'input' => '0'.str_repeat('1', 25), 'withMatches' => true];
        yield 'A1 non-space loop before a literal' => ['pattern' => '/^(\S+\s*)+!/', 'input' => str_repeat('a', 5000), 'withMatches' => true];
        yield 'A1 dot loop before a literal the dot also matches' => ['pattern' => '/(.+)+!/', 'input' => '!'.str_repeat('a', 25), 'withMatches' => true];
        yield 'A1 negated class loop pumped with newlines' => ['pattern' => '/([^b]+)+(.)/', 'input' => str_repeat("\n", 20), 'withMatches' => true];
        yield 'A1 word loop before a literal digit' => ['pattern' => '/^(\w+\d*)+0/', 'input' => '0'.str_repeat('a', 20), 'withMatches' => true];
        yield 'A1 nullable lazy loop before a start anchor' => ['pattern' => '/x?(?:\s*?)+\A/', 'input' => 'x'.str_repeat(' ', 30), 'withMatches' => true];

        // A2: polynomial ambiguity inside one strongly connected component.
        // Both: false, "Backtrack limit exhausted".
        yield 'A2 three word runs before a separator' => ['pattern' => '/^(?:\w+\s*\w+\s*\w+[;,])+/', 'input' => str_repeat('a', 3000), 'withMatches' => true];
        yield 'A2 counted loop of loops before a space' => ['pattern' => '/((a+){6}\s)+/', 'input' => str_repeat('a', 60), 'withMatches' => true];

        // A3: \b and \B at an attempt start that is not the subject start,
        // and in a lookaround body. All: false, "Backtrack limit exhausted".
        yield 'A3 non-boundary in a lookahead body' => ['pattern' => '/^a(?=\B(a+)+$)/', 'input' => str_repeat('a', 20).'!', 'withMatches' => true];
        yield 'A3 non-boundary at an unanchored start' => ['pattern' => '/\B(a+)+$/', 'input' => str_repeat('a', 20).'!', 'withMatches' => true];
        yield 'A3 boundary after a word character' => ['pattern' => '/\b-(a+)+$/', 'input' => 'x-'.str_repeat('a', 25).'!', 'withMatches' => true];

        // A4: non-atomic lookaheads backtrack into their body.
        // All: false, "Backtrack limit exhausted".
        yield 'A4 non-atomic lookahead, short form' => ['pattern' => '/^(?*(a+)+)\w*\W/', 'input' => str_repeat('a', 25), 'withMatches' => true];
        yield 'A4 non-atomic lookahead, napla' => ['pattern' => '/^(*napla:(a+)+)\w*\W/', 'input' => str_repeat('a', 25), 'withMatches' => true];
        yield 'A4 non-atomic lookahead, long form' => ['pattern' => '/^(*non_atomic_positive_lookahead:(a+)+)\w*\W/', 'input' => str_repeat('a', 25), 'withMatches' => true];

        // A5: ambiguity across the copies of a bounded repeat, nullable
        // bodies under {m,n}. All: false, "Backtrack limit exhausted"
        // ('/((a?){22}{)?/' from 8 characters, '/(|\W?){,17}\B/' on "a!b"
        // already).
        yield 'A5 bounded repeat of an optional separator' => ['pattern' => '/^(?:[a-z0-9]{1,8}-?){10}$/', 'input' => str_repeat('a', 40).'!', 'withMatches' => true];
        yield 'A5 bounded repeat of an ambiguous alternation' => ['pattern' => '/^(?:(?:a|a){4}){5}$/', 'input' => str_repeat('a', 20).'!', 'withMatches' => true];
        // The "!A" keeps the required "A" in the subject: without it PCRE2
        // before 10.43 rejects the subject at once.
        yield 'A5 bounded repeat of a nullable bounded repeat' => ['pattern' => '/^(?:a{0,16}){6}A/', 'input' => str_repeat('a', 48).'!A', 'withMatches' => true];
        yield 'A5 exact count above the unroll cutoff of a nullable body' => ['pattern' => '/((a?){22}{)?/', 'input' => str_repeat('a', 10), 'withMatches' => true];
        yield 'A5 upper-bound-only repeat of a nullable alternation' => ['pattern' => '/(|\W?){,17}\B/', 'input' => str_repeat('a!', 10).'b', 'withMatches' => true];

        // A6: (?xx) ignores a literal space inside a class; the class scan saw
        // it. All: false, "Backtrack limit exhausted".
        yield 'A6 extended-more flag at the start' => ['pattern' => '/(?xx)^(?:[^x\x20]+)+(?:[ x]|$)/i', 'input' => str_repeat('a', 25).' ', 'withMatches' => true];
        yield 'A6 extended-more flag scoped to a group' => ['pattern' => '/^(?:[^x\x20]+)+(?xx:[ x]|$)/i', 'input' => str_repeat('a', 25).' ', 'withMatches' => true];
        yield 'A6 extended-more flag switched on mid-pattern' => ['pattern' => '/^(?:[^x\x20]+)+(?xx)(?:[ x]|$)/i', 'input' => str_repeat('a', 25).' ', 'withMatches' => true];

        // A7: without $matches, an empty match at the start is retried
        // (NOTEMPTY_ATSTART | ANCHORED). All: false, "Backtrack limit
        // exhausted" without $matches; 1 with $matches.
        yield 'A7 empty first branch before an ambiguous loop' => ['pattern' => '/|(.+)+!/', 'input' => str_repeat('ab', 32), 'withMatches' => false];
        yield 'A7 lazy optional ambiguous group' => ['pattern' => '/(?:(a+)+b)??/', 'input' => str_repeat('a', 32), 'withMatches' => false];
        yield 'A7 bounded repeat of empty branches' => ['pattern' => '/(||){,17}/', 'input' => 'x', 'withMatches' => false];

        // B1: polynomial work in a higher-priority branch that fails before
        // the other branch's certain success. All: false, "Backtrack limit
        // exhausted" (1,501 bytes; 1,500 for the last row).
        yield 'B1 word run before a boundary and a dash, or one word character' => ['pattern' => '/(?:\w+\b-|\w)+/', 'input' => str_repeat('a', 1500).'!', 'withMatches' => true];
        yield 'B1 optional word run before a boundary and a dash' => ['pattern' => '/^(?:\w(?:\w+\b-)?)+/', 'input' => str_repeat('a', 1500).'!', 'withMatches' => true];
        yield 'B1 optional newline run before a multiline start anchor' => ['pattern' => '/(\s(\n+^)?)+/', 'input' => str_repeat("\n", 1500).'!', 'withMatches' => true];
        yield 'B1 dot run before a dash, or one non-space' => ['pattern' => '/(?:.+-|\S)+/', 'input' => str_repeat('a', 1500).'!', 'withMatches' => true];
        yield 'B1 dot run before a literal, or the pumped character' => ['pattern' => '/(?:.+x|a)+/', 'input' => str_repeat('a', 1500), 'withMatches' => true];

        // B2: bounded copies written out. All: false, "Backtrack limit
        // exhausted".
        yield 'B2 thirty optional copies before thirty mandatory ones' => ['pattern' => '/^'.str_repeat('a?', 30).str_repeat('a', 30).'$/', 'input' => str_repeat('a', 30), 'withMatches' => true];
        yield 'B2 twenty-four ambiguous alternations written out' => ['pattern' => '/^'.str_repeat('(?:a|a)', 24).'$/', 'input' => str_repeat('a', 24).'!', 'withMatches' => true];
        yield 'B2 sixteen digit groups written out' => ['pattern' => '/^'.str_repeat('\d{1,3}', 16).'$/', 'input' => str_repeat('1', 48).'!', 'withMatches' => true];
        yield 'B2 ten label groups written out' => ['pattern' => '/^'.str_repeat('[a-z0-9]{1,8}-?', 10).'$/', 'input' => str_repeat('a', 40).'!', 'withMatches' => true];

        // B3: \G holds at the call's offset and at PHP's retry offset. At
        // offset 1 both: false, "Backtrack limit exhausted" (offset 0: 0).
        // The last row: false without $matches (the empty match of a+\K is
        // retried at offset 1), 1 with $matches.
        yield 'B3 continuation anchor and non-boundary at offset 1' => ['pattern' => '/\G\B(a+)+$/', 'input' => 'x'.str_repeat('a', 25).'!', 'withMatches' => true, 'offset' => 1];
        yield 'B3 continuation anchor and boundary at offset 1' => ['pattern' => '/\G\b-(a+)+$/', 'input' => 'x-'.str_repeat('a', 25).'!', 'withMatches' => true, 'offset' => 1];
        yield 'B3 continuation anchor at the retry offset after an empty match' => ['pattern' => '/a+\K|\G\B(b+)+c/', 'input' => 'a'.str_repeat('b', 25), 'withMatches' => false];
    }

    private static function describe(string $input): string
    {
        return \strlen($input) > 40 ? json_encode(substr($input, 0, 40), \JSON_THROW_ON_ERROR).'... ('.\strlen($input).' bytes)' : json_encode($input, \JSON_THROW_ON_ERROR);
    }
}

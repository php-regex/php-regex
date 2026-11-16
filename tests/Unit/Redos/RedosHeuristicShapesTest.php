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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Redos\RedosProfiler;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A repeated group whose body the proof does not model, a reference to a
 * group inside it, a condition on a group, a recursion, is left to the
 * heuristic: never called safe, and never called proven.
 */
final class RedosHeuristicShapesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideUnmodelledBodies')]
    public function test_a_repeated_body_the_proof_does_not_model_is_never_called_safe(string $pattern): void
    {
        $this->assertTrue(self::compiles($pattern), 'Oracle: PCRE compiles the pattern.');

        $analysis = Regex::create(['cache' => null])->redos($pattern);

        $this->assertFalse($analysis->isProvenSafe());
        $this->assertNotSame(RedosSeverity::Safe, $analysis->severity);
        $this->assertSame('Potential backtracking (heuristic)', $analysis->headline());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUnmodelledBodies(): iterable
    {
        yield 'a reference to a group of the body' => ['pattern' => '/((a?)\2+)+/'];
        // The engine reads it in a constant number of steps: the heuristic
        // over-reports, and calls it no more than potential.
        yield 'a condition on the group itself' => ['pattern' => '/((?(1)|b))+/'];
        // With the start optimizations off, the engine stops the match on a
        // recursion that could loop, PREG_INTERNAL_ERROR, before any
        // backtracking.
        yield 'an optional recursion before an optional letter' => ['pattern' => '/((?R)?a?)+/'];
        yield 'an optional recursion before a possessive run' => ['pattern' => '/((?R)?a*+)+/'];
        yield 'an optional recursion before an optional atomic letter' => ['pattern' => '/((?R)?(?>a)?)+/'];
        yield 'an optional recursion before an atomic optional letter' => ['pattern' => '/((?R)?(?>a?))+/'];
    }

    /**
     * The reference shape is critical, and the engine agrees: with its
     * start optimizations off, each two more letters cost it more than
     * twice the steps before the "!" fails the match. Oracle (PCRE2 10.49,
     * JIT off): 60 steps at 6 letters, 411 at 10, 7,375 at 16.
     */
    #[Test]
    public function test_the_reference_shape_is_critical_as_the_engine_counts_it(): void
    {
        $pattern = '/(*NO_JIT)(*NO_START_OPT)((a?)\2+)+!/';
        $steps = [];
        foreach ([8, 10, 12] as $letters) {
            $steps[] = self::steps($pattern, str_repeat('a', $letters));
        }

        $this->assertGreaterThan(2 * $steps[0], $steps[1]);
        $this->assertGreaterThan(2 * $steps[1], $steps[2]);
        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos('/((a?)\2+)+/')->severity);
    }

    #[Test]
    public function test_the_engine_reads_the_condition_shape_in_constant_steps(): void
    {
        $pattern = '/(*NO_JIT)(*NO_START_OPT)((?(1)|b))+!/';

        $this->assertSame(self::steps($pattern, str_repeat('b', 6)), self::steps($pattern, str_repeat('b', 16)));
    }

    #[Test]
    public function test_the_engine_stops_the_recursion_shape_before_it_backtracks(): void
    {
        $this->assertFalse(@preg_match(PcreInterpreter::pattern('/(*NO_START_OPT)((?R)?a?)+!/'), str_repeat('a', 16)));
        $this->assertSame(\PREG_INTERNAL_ERROR, preg_last_error());
    }

    /**
     * An atomic group, a possessive loop, or a loop whose body ends with a
     * verb that cuts backtracking holds once its body has matched; until
     * then the engine backtracks inside the body as it does anywhere. A
     * nested loop the body can still fail after is critical, as it is
     * without the group, and the engine agrees: four more letters cost it
     * about sixteen times the steps before the match fails (start
     * optimizations off, PCRE2 10.49, JIT off): 641 -> 10 241 -> 163 841
     * for "(?>(a+)+b)" and "^(?>(\w+\s?)+:)", 385 -> 6 145 -> 98 305 for
     * "^(?:(?:a+)+b)++" and "^(*atomic:(?:a+)+b)", 384 -> 6 144 -> 98 304
     * with "(*COMMIT)", 4 376 -> 354 296 -> 16 777 216 when the possessive
     * loop needs thirty iterations the run never gives. An atomic script
     * run is no exception (386 -> 6 146 -> 98 306), nor one checked once
     * its body has matched (385 -> 6 145 -> 98 305).
     *
     * @return iterable<string, array{pattern: string, tail: string}>
     */
    public static function provideLoopsTheAtomicBodyMayStillFailAfter(): iterable
    {
        yield 'atomic group' => ['pattern' => '/(?>(a+)+b)/', 'tail' => '!b'];
        yield 'atomic group of word runs before a colon' => ['pattern' => '/^(?>(\w+\s?)+:)/', 'tail' => '!:'];
        yield 'possessive loop' => ['pattern' => '/^(?:(?:a+)+b)++/', 'tail' => '!b'];
        yield 'atomic verb' => ['pattern' => '/^(*atomic:(?:a+)+b)/', 'tail' => '!b'];
        yield 'loop ending with (*COMMIT)' => ['pattern' => '/^(?:(?:a+)+b(*COMMIT))+/', 'tail' => '!b'];
        yield 'possessive loop needing thirty iterations' => ['pattern' => '/^(?:(?:a+)+){30,}+b/', 'tail' => '!b'];
        yield 'atomic script run' => ['pattern' => '/^(*asr:(?:a+)+b)/', 'tail' => '!b'];
        yield 'script run' => ['pattern' => '/^(*sr:(?:a+)+b)/', 'tail' => '!b'];
    }

    #[Test]
    #[DataProvider('provideLoopsTheAtomicBodyMayStillFailAfter')]
    public function test_a_nested_loop_the_atomic_body_may_still_fail_after_is_critical(string $pattern, string $tail): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, str_repeat('a', 8).$tail);
        $long = self::steps($unoptimized, str_repeat('a', 12).$tail);
        $this->assertGreaterThan(8 * $short, $long, 'Oracle: the engine goes exponential.');

        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * A nested loop that ends the body is matched the first way through,
     * and nothing in the body can fail after it: the engine takes as many
     * steps on 16 letters as on 8 (5, 6, 4, 6 and 6 steps), and the heuristic
     * finds nothing. So does a loop needing at most one iteration whose
     * body may fail after its alternation (21 -> 29 -> 37).
     *
     * @return iterable<string, array{pattern: string, prefix: string, tail: string}>
     */
    public static function provideLoopsEndingTheAtomicBody(): iterable
    {
        yield 'atomic group' => ['pattern' => '/^(?>(?:a+)+)b/', 'prefix' => '', 'tail' => '!b'];
        yield 'possessive loop' => ['pattern' => '/^(?:(?:a+)+)++b/', 'prefix' => '', 'tail' => '!b'];
        yield 'loop ending an iteration of a loop ending the body' => ['pattern' => '/^(?>(?:x(a+)+)*)y/', 'prefix' => 'x', 'tail' => '!y'];
        yield 'loop before (*COMMIT)' => ['pattern' => '/^(?:(?:a+)+(*COMMIT))+b/', 'prefix' => '', 'tail' => '!b'];
        yield 'atomic script run' => ['pattern' => '/^(*asr:(?:a+)+)b/', 'prefix' => '', 'tail' => '!b'];
        yield 'alternation an iteration may fail after' => ['pattern' => '/^(?>(?:(?:a|a)x?)*)y/', 'prefix' => '', 'tail' => '!y'];
    }

    #[Test]
    #[DataProvider('provideLoopsEndingTheAtomicBody')]
    public function test_a_nested_loop_ending_the_atomic_body_is_safe_for_the_heuristic(string $pattern, string $prefix, string $tail): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, $prefix.str_repeat('a', 8).$tail);
        $long = self::steps($unoptimized, $prefix.str_repeat('a', 16).$tail);
        $this->assertLessThan(2 * $short, $long, 'Oracle: the engine stays linear.');

        $tree = Regex::create(['cache' => null])->parse($pattern);
        $profiler = new RedosProfiler(CharSetAnalyzer::forRegex($tree));
        $tree->accept($profiler);

        $this->assertSame(RedosSeverity::Safe, $profiler->getResult()['severity']);
    }

    /**
     * A call in a loop runs the group it calls at each iteration, as if
     * written there: an ambiguous alternation or a run it calls is
     * critical, as it is written in the loop, and the engine agrees on the
     * unit repeated then "!" (8 -> 12 -> 16 units): 3 065 -> 49 145 ->
     * 786 425 steps for "(?1)" of "(a|a)", 1 024 -> 16 384 -> 262 144 for a
     * run of letters before an optional space, 2 554 -> 40 954 -> 655 354
     * for "(a|a)" before a comma. A separator after the called run keeps
     * the iterations apart (27 -> 39 -> 51), as one before it (3 -> 3 ->
     * 3), and a call of disjoint alternatives reads one way (35 -> 51 ->
     * 67). A comma before the call keeps apart a run that is one of the
     * alternatives called, whether the group calls itself (31 -> 43 -> 55)
     * or not (27 -> 39 -> 51).
     *
     * @return iterable<string, array{pattern: string, prefix: string, unit: string, critical: bool}>
     */
    public static function provideCallsInALoop(): iterable
    {
        yield 'call of an ambiguous alternation' => ['pattern' => '/^(?:(?1))+(a|a)$/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'call of a run before an optional space' => ['pattern' => '/^(?:(?&w)\s?)+$(?(DEFINE)(?<w>[a-z]+))/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'call of an ambiguous alternation before a comma' => ['pattern' => '/^(?:(?1),)+(a|a)$/', 'prefix' => '', 'unit' => 'a,', 'critical' => true];
        yield 'call of a run before a comma' => ['pattern' => '/^(?:(?&w),)+$(?(DEFINE)(?<w>[a-z]+))/', 'prefix' => '', 'unit' => 'a,', 'critical' => false];
        yield 'call of a run after a letter it cannot take' => ['pattern' => '/^(a+)(?:x(?1))+$/', 'prefix' => 'a', 'unit' => 'xa', 'critical' => false];
        yield 'call of disjoint alternatives' => ['pattern' => '/^(a|b)(?:(?1))+$/', 'prefix' => '', 'unit' => 'a', 'critical' => false];
        yield 'recursive call of a list after a comma' => ['pattern' => '/^(?<value>\[(?:(?&value)(?:,(?&value))*)?\]|\d+)$/', 'prefix' => '[', 'unit' => '1,', 'critical' => false];
        yield 'call of alternatives after a comma' => ['pattern' => '/^(?:,(?&v))*$(?(DEFINE)(?<v>\[\]|\d+))/', 'prefix' => '', 'unit' => ',1', 'critical' => false];
    }

    #[Test]
    #[DataProvider('provideCallsInALoop')]
    public function test_a_call_in_a_loop_is_read_as_the_group_it_calls(string $pattern, string $prefix, string $unit, bool $critical): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, $prefix.str_repeat($unit, 8).'!');
        $long = self::steps($unoptimized, $prefix.str_repeat($unit, 12).'!');
        $this->assertSame($critical, $long > 4 * $short, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($critical, RedosSeverity::Critical === Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * Whether PCRE compiles the pattern: a refused pattern warns, where a
     * match the engine stops, as on a recursion that could loop, does not.
     */
    private static function compiles(string $pattern): bool
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });

        try {
            preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        return !$warned;
    }

    /**
     * The smallest backtrack limit under which the match attempt ends.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $saved = (string) ini_get('pcre.backtrack_limit');

        try {
            $low = 1;
            $high = 1 << 24;
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
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
        }
    }
}

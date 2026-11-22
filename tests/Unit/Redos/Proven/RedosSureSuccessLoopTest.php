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
use PHPRegex\Redos\RedosProof;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A loop read in two ways that no suffix makes fail. In "^(?:a|a)+." the
 * loop reads every "a", the dot fails at the end, the loop gives one "a"
 * back and the dot reads it: whatever character the loop could go on with,
 * the attempt is sure to succeed a character later. The engine never
 * abandons a run inside the loop longer than that, so the two ways around
 * it cost nothing, and the attempt is proven linear, as "^(?:a|b)+." is.
 *
 * A suffix that fails after the loop keeps the exponential verdict, and a
 * run abandoned after the loop, in a loop of its own, keeps the pattern out
 * of the proof: there the engine is quadratic. Where the model may count a
 * success the engine refuses, the loop is not read so: an atomic group the
 * model keeps with every way through it, or a bounded repeat it reads as
 * unbounded, would make a failing suffix look sure to succeed.
 *
 * Each row carries its engine fact, measured in the same test: the steps
 * one match attempt needs in the interpreter, auto-possession and start
 * optimizations off (PcreAttemptSteps). PHP 8.4.28, PCRE2 10.49, on 16 then
 * 32 "a" and a "!": 35 -> 67 steps for "^(?:a|a)+.", 36 -> 68 for
 * "(*NOTEMPTY)^(?:a|a)+\K.?", 37 -> 69 for "(*NOTEMPTY)^(?:a\K|a)+", the
 * same as "^(?:a|b)+." takes. On 6 then 12 units and the tail: 255 ->
 * 16 383 steps for "^(?:a|a)+b", 160 -> 454 for "^(?:a|a|ab)+(?:[ab]*c|a)"
 * on "ab", 475 -> 30 715 for "^(?:a|a)+(?>a+|b)a".
 */
final class RedosSureSuccessLoopTest extends TestCase
{
    /**
     * @param list<string> $tails
     */
    #[Test]
    #[DataProvider('provideLoopsNoSuffixMakesFail')]
    public function test_a_loop_no_suffix_makes_fail_is_proven_linear(string $pattern, array $tails): void
    {
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        foreach ($tails as $tail) {
            $short = PcreAttemptSteps::steps($counted, str_repeat('a', 16).$tail);
            $long = PcreAttemptSteps::steps($counted, str_repeat('a', 32).$tail);
            $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 16 then 32 "a" and %s.', $pattern, $short, $long, json_encode($tail)));
        }

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
        $this->assertTrue($analysis->isProvenSafe(), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, tails: list<string>}>
     */
    public static function provideLoopsNoSuffixMakesFail(): iterable
    {
        $tails = ['!', "\n", ''];

        yield 'a dot after the loop' => ['pattern' => '/^(?:a|a)+./', 'tails' => $tails];
        yield 'a dot after a loop that may read nothing' => ['pattern' => '/^(?:a|a)*./', 'tails' => $tails];
        yield 'a dot after branches of two lengths' => ['pattern' => '/^(?:a|aa)+./', 'tails' => $tails];
        yield 'a run of dots after the loop' => ['pattern' => '/^(?:a|a)+.+/', 'tails' => $tails];
        yield 'three dots after the loop' => ['pattern' => '/^(?:a|a)+.{3}/', 'tails' => $tails];
        yield 'the loop inside a loop that ends on a dot' => ['pattern' => '/^(?:(?:a|a)+.)+/', 'tails' => $tails];
        yield 'a dot after the loop, unanchored' => ['pattern' => '/(?:a|a)+./', 'tails' => $tails];
        // The match "\K" leaves empty is refused: the loop gives back an "a"
        // for the dot to read after it.
        yield 'an optional dot after \K under (*NOTEMPTY)' => ['pattern' => '/(*NOTEMPTY)^(?:a|a)+\K.?/', 'tails' => $tails];
        // The last iteration takes the branch without "\K" once the loop
        // gives one back: the "\K" of the iteration before stands before the
        // last "a".
        yield '\K in one branch of the loop under (*NOTEMPTY)' => ['pattern' => '/(*NOTEMPTY)^(?:a\K|a)+/', 'tails' => $tails];
    }

    /**
     * Where a suffix fails after every way out of the loop, every way
     * through it is tried: the verdict is the exponential witness. Where a
     * run abandoned after the loop reads a loop of its own from each
     * position of it, the attempt is quadratic, and no linear verdict is
     * proven.
     */
    #[Test]
    #[DataProvider('provideLoopsASuffixMakesFail')]
    public function test_a_loop_a_suffix_makes_fail_keeps_its_verdict(string $pattern, string $unit, string $tail, RedosComplexity $complexity): void
    {
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        $short = PcreAttemptSteps::steps($counted, str_repeat($unit, 6).$tail);
        $long = PcreAttemptSteps::steps($counted, str_repeat($unit, 12).$tail);
        $engine = match (true) {
            $long >= 16 * $short => RedosComplexity::Exponential,
            2 * $long >= 5 * $short => RedosComplexity::Polynomial,
            default => RedosComplexity::Linear,
        };
        $this->assertSame($complexity, $engine, \sprintf('Oracle: %s takes %d then %d steps on 6 then 12 %s and %s.', $pattern, $short, $long, json_encode($unit), json_encode($tail)));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
        if (RedosComplexity::Exponential === $complexity) {
            $this->assertSame([RedosProof::Proven, RedosComplexity::Exponential], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
        }
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, tail: string, complexity: RedosComplexity}>
     */
    public static function provideLoopsASuffixMakesFail(): iterable
    {
        yield 'the end after the loop' => ['pattern' => '/^(?:a|a)+$/', 'unit' => 'a', 'tail' => '!', 'complexity' => RedosComplexity::Exponential];
        yield 'a letter the loop does not read' => ['pattern' => '/^(?:a|a)+b/', 'unit' => 'a', 'tail' => '!', 'complexity' => RedosComplexity::Exponential];
        yield 'a dot then the end' => ['pattern' => '/^(?:a|a)+.$/', 'unit' => 'a', 'tail' => '!!', 'complexity' => RedosComplexity::Exponential];
        yield 'a lazy loop before the end' => ['pattern' => '/^(?:a|a)+?$/', 'unit' => 'a', 'tail' => '!', 'complexity' => RedosComplexity::Exponential];
        yield 'the loop inside a loop before the end' => ['pattern' => '/^(?:(?:a|a)+.)+$/', 'unit' => 'a', 'tail' => '!!', 'complexity' => RedosComplexity::Exponential];
        yield 'the empty match \K leaves, under (*NOTEMPTY)' => ['pattern' => '/(*NOTEMPTY)^(?:a|a)+\K/', 'unit' => 'a', 'tail' => '!', 'complexity' => RedosComplexity::Exponential];
        // Each "a" the first two branches read before a "b" sends the
        // attempt into "[ab]*", which reads the rest of the subject before it
        // fails on the "!": a run abandoned after the loop, from each
        // position of it.
        yield 'a loop after the loop reading what it reads' => ['pattern' => '/^(?:a|a|ab)+(?:[ab]*c|a)/', 'unit' => 'ab', 'tail' => '!', 'complexity' => RedosComplexity::Polynomial];
    }

    /**
     * The model keeps an atomic body with every way through it and reads a
     * bounded repeat above the cutoff as unbounded: either may count a
     * success the engine refuses, and make a suffix that fails after every
     * way out of the loop look sure to succeed. A run abandoned inside the
     * loop that is bounded but read in more ways than an acyclic stretch may
     * be is no proof either. A bound that takes more sets of states to tell
     * than the reading allows, a lookaround, a search that is the body of
     * one, and "$" (which the model reads with the newline it may stand
     * before) keep the loop out of the reading too, though the engine is
     * linear on those rows. The heuristics decide.
     */
    #[Test]
    #[DataProvider('provideLoopsTheModelMayMisread')]
    public function test_a_loop_whose_success_the_model_may_misread_is_left_to_the_heuristics(string $pattern, string $tail, RedosComplexity $complexity): void
    {
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 6).$tail);
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 12).$tail);
        $engine = $long >= 16 * $short ? RedosComplexity::Exponential : RedosComplexity::Linear;
        $this->assertSame($complexity, $engine, \sprintf('Oracle: %s takes %d then %d steps on 6 then 12 "a" and %s.', $pattern, $short, $long, json_encode($tail)));
        if (RedosComplexity::Linear === $complexity) {
            $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s is not linear.', $pattern));
        }

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, tail: string, complexity: RedosComplexity}>
     */
    public static function provideLoopsTheModelMayMisread(): iterable
    {
        // The atomic group keeps every "a" its run reads, and the "a" after
        // it fails: no way out of the loop succeeds.
        yield 'an atomic group kept with every way through it' => ['pattern' => '/^(?:a|a)+(?>a+|b)a/', 'tail' => '!', 'complexity' => RedosComplexity::Exponential];
        // Twenty characters at most before the end, where the "b" run is
        // thirty long: no way out of the loop succeeds.
        yield 'a bounded repeat read as unbounded' => ['pattern' => '/^(?:a|a)+a[^x]{0,20}(?:x|\z)/', 'tail' => str_repeat('b', 30), 'complexity' => RedosComplexity::Exponential];
        // The dots succeed only twenty characters before the end: the run
        // abandoned inside the loop is bounded, but read in more ways than
        // the margin allows, and PHP exhausts its backtrack limit on 60 "a"
        // and a newline.
        yield 'twenty dots after the loop' => ['pattern' => '/^(?:a|a)+.{20}/', 'tail' => "\n", 'complexity' => RedosComplexity::Exponential];
        // The runs abandoned inside the loop are bounded, but telling it
        // takes more sets of states than the reading allows.
        yield 'six dots after a loop with a longer branch' => ['pattern' => '/^(?:a|a|bc)+.{6}/', 'tail' => '!', 'complexity' => RedosComplexity::Linear];
        yield 'a lookahead after the loop' => ['pattern' => '/^(?:a|a)+(?:.|(?=x))/', 'tail' => '!', 'complexity' => RedosComplexity::Linear];
        yield 'the loop in the body of a lookahead' => ['pattern' => '/(?=(?:a|a)+.)a/', 'tail' => '!', 'complexity' => RedosComplexity::Linear];
        yield 'the end after the loop' => ['pattern' => '/^(?:a|a)+a(?:.|\n)*?$/', 'tail' => '!', 'complexity' => RedosComplexity::Linear];
    }

    /**
     * The pattern with "(*NO_START_OPT)" after the opening delimiter: PCRE2
     * would otherwise give up at once on a subject without a character the
     * pattern requires.
     */
    private static function withoutStartOptimizations(string $pattern): string
    {
        return $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);
    }
}

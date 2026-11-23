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
     * Under m, "^" does not hold after a newline that ends the subject
     * (preg_match('/\n^/m', "a\n") is 0), where the model of the pattern
     * reads it as holding: a success through it may not be the engine's,
     * and a loop of newlines before it is left to the heuristics. On a run
     * of newlines PHP takes 285 then 18 429 steps on 6 then 12 of them for
     * "\A(?:\n|\n)+\n^(?:\z|.)", in the interpreter.
     */
    #[Test]
    #[DataProvider('provideLoopsBeforeAStartOfLine')]
    public function test_a_loop_before_a_start_of_line_is_left_to_the_heuristics(string $pattern): void
    {
        $this->assertSame(0, preg_match('/\n^/m', "a\n"), 'Oracle: "^" holds after the newline that ends the subject.');
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        $short = PcreAttemptSteps::steps($counted, str_repeat("\n", 6));
        $long = PcreAttemptSteps::steps($counted, str_repeat("\n", 12));
        $this->assertGreaterThanOrEqual(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 6 then 12 newlines: linear.', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLoopsBeforeAStartOfLine(): iterable
    {
        yield 'a start of line before the end or a character' => ['pattern' => '/\A(?:\n|\n)+\n^(?:\z|.)/m'];
        yield 'the same, unanchored' => ['pattern' => '/(?:\n|\n)+\n^(?:\z|.)/m'];
        yield 'a start of line before the end, or another character' => ['pattern' => '/\A(?:\n|\n)+\n(?:^\z|[^\n])/m'];
        yield 'branches of two lengths' => ['pattern' => '/^(?:\n|\n\n)+(?:^\z|.)/m'];
        yield 'two runs of newlines' => ['pattern' => '/\A\n*\n*\n^(?:\z|.)/m'];
        yield 'non-word characters under (*NOTEMPTY)' => ['pattern' => '/(*NOTEMPTY)(?:\W|\W)*(?:^\z|.)/mD'];
        yield 'spaces then an optional newline' => ['pattern' => '/(?:\s|\s){1,}(?:^\z|.)\n?/m'];
    }

    /**
     * An empty group read twice over, "(?:|)", gives the attempt two ways to
     * the next character, and k of them 2^k: each way into the loop meets
     * each way the abandoned run reads it, about 4^k steps for one attempt
     * on one "a" and a newline (21, 273, 1 057, 4 161, 65 793 and 1 049 601
     * for k = 2, 4, 5, 6, 8 and 10 in the interpreter; PHP exhausts its
     * backtrack limit at k = 10). The ways count every empty route, the way
     * out to the dot included: 32 ways in times 33 at k = 5 is past the
     * margin, and the loop is not proven.
     */
    #[Test]
    #[DataProvider('provideEmptyRoutes')]
    public function test_the_ways_through_the_loop_count_every_empty_route(int $groups, bool $proven): void
    {
        $body = '';
        for ($group = 0; $group < $groups; $group++) {
            $body .= 0 === $group % 2 ? '(?:|)' : '(?:(?#'.$group.')|)';
        }

        $pattern = '/^(?:'.$body.'a)+./';
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        $one = PcreAttemptSteps::steps($counted, "a\n");
        $this->assertGreaterThanOrEqual(4 ** $groups, $one, \sprintf('Oracle: %s takes %d steps on one "a" and a newline.', $pattern, $one));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 8)."\n");
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 16)."\n");
        $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 16 "a" and a newline.', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame($proven, $analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{groups: int, proven: bool}>
     */
    public static function provideEmptyRoutes(): iterable
    {
        foreach ([2 => true, 4 => true, 5 => false, 6 => false, 8 => false, 10 => false] as $groups => $proven) {
            yield $groups.' empty groups' => ['groups' => $groups, 'proven' => $proven];
        }
    }

    /**
     * The routes into the loop and out of it count with those inside it: an
     * attempt that fails tries each way in with each way the run reads the
     * loop and each way out after it. Eight groups "(?:)*" before
     * "(?:a|a)+" and thirteen after it give one attempt on one "a" and a
     * newline 8 389 376 steps in the interpreter, and the JIT exhausts PHP's
     * backtrack limit too; eight written-apart groups before and ten after,
     * each side within the margin, take 525 313.
     */
    #[Test]
    #[DataProvider('provideRoutesAroundTheLoop')]
    public function test_the_routes_around_the_loop_count_with_the_ways_inside_it(string $before, string $after, int $steps, bool $proven): void
    {
        $pattern = '/^'.$before.'(?:a|a)+'.$after.'./';
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        $one = PcreAttemptSteps::steps($counted, "a\n");
        $this->assertGreaterThanOrEqual($steps, $one, \sprintf('Oracle: %s takes %d steps on one "a" and a newline.', $pattern, $one));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 8)."\n");
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 16)."\n");
        $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 16 "a" and a newline.', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame($proven, $analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{before: string, after: string, steps: int, proven: bool}>
     */
    public static function provideRoutesAroundTheLoop(): iterable
    {
        $apart = static function (int $count, int $offset): string {
            $groups = '';
            for ($group = 0; $group < $count; $group++) {
                $groups .= '(?:'.\chr(98 + ($group + $offset) % 24).'{0}|)';
            }

            return $groups;
        };

        yield 'two groups on each side' => ['before' => $apart(2, 0), 'after' => $apart(2, 11), 'steps' => 2 ** 4, 'proven' => true];
        yield 'eight empty loops before and thirteen after' => ['before' => str_repeat('(?:)*', 8), 'after' => str_repeat('(?:)*', 13), 'steps' => 2 ** 21, 'proven' => false];
        yield 'eight groups before and ten after' => ['before' => $apart(8, 0), 'after' => $apart(10, 11), 'steps' => 2 ** 18, 'proven' => false];
    }

    /**
     * The ways into the loop count the routes before another loop on the
     * way to it, and the ways out the routes after another loop past it:
     * "x+" before "(?:a|a)+" does not start the count again. Five groups
     * before "x+" and five after the loop give one attempt on "xa" and a
     * newline 2 177 steps in the interpreter, 32 ways to "x+", two into the
     * loop and 34 the run reads, past the margin; four on each side take
     * 577.
     */
    #[Test]
    #[DataProvider('provideRoutesPastAnotherLoop')]
    public function test_the_routes_past_another_loop_count_in_the_ways_around_this_one(string $pattern, string $subject, int $steps, bool $proven): void
    {
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        if ($steps > 1_000_000) {
            $this->assertFalse(PcreAttemptSteps::searchUnderLimit($counted, $subject, 1_000_000), 'Oracle: '.$pattern.' finishes on '.json_encode($subject).' under PHP\'s default backtrack limit.');
        } else {
            $one = PcreAttemptSteps::steps($counted, $subject);
            $this->assertGreaterThanOrEqual($steps, $one, \sprintf('Oracle: %s takes %d steps on %s.', $pattern, $one, json_encode($subject)));
        }

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame($proven, $analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, steps: int, proven: bool}>
     */
    public static function provideRoutesPastAnotherLoop(): iterable
    {
        $apart = static function (int $count, int $offset): string {
            $groups = '';
            for ($group = 0; $group < $count; $group++) {
                $groups .= '(?:'.\chr(98 + ($group + $offset) % 24).'{0}|)';
            }

            return $groups;
        };

        yield 'four groups on each side' => ['pattern' => '/^'.$apart(4, 0).'x+(?:a|a)+'.$apart(4, 11).'./', 'subject' => "xa\n", 'steps' => 577, 'proven' => true];
        yield 'five groups on each side' => ['pattern' => '/^'.$apart(5, 0).'x+(?:a|a)+'.$apart(5, 11).'./', 'subject' => "xa\n", 'steps' => 2_177, 'proven' => false];
        yield 'ten groups before another loop and eight after' => ['pattern' => '/^'.$apart(10, 0).'x+(?:a|a)+'.$apart(8, 11).'./', 'subject' => "xa\n", 'steps' => 528_385, 'proven' => false];
        yield 'ten groups before each of two loops' => ['pattern' => '/^'.$apart(10, 0).'y+'.$apart(10, 11).'x+(?:a|a)+./', 'subject' => "yxa\n", 'steps' => 6_291_456, 'proven' => false];
    }

    /**
     * An atomic group or a possessive repeat whose body reads nothing in
     * two ways is kept with every way through it, where the engine commits
     * to the first: after "\K" under (*NOTEMPTY) that way leaves the match
     * empty, the engine refuses it and gives back every way through the
     * loop, while the model counts the empty way the engine never takes. The
     * interpreter doubles its steps with each "a"; the JIT, which ignores
     * (*NOTEMPTY), matches the empty string at once.
     */
    #[Test]
    #[DataProvider('provideZeroWidthAtomicBodies')]
    public function test_a_zero_width_body_kept_with_every_way_is_left_to_the_heuristics(string $pattern): void
    {
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 8));
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 12));
        $this->assertGreaterThanOrEqual(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 12 "a": linear.', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideZeroWidthAtomicBodies(): iterable
    {
        yield 'an atomic group' => ['pattern' => '/(*NOTEMPTY)^(?:a|a)+(?>\K|)/'];
        yield 'a possessive optional group' => ['pattern' => '/(*NOTEMPTY)^(?:a|a)+(?:\K)?+/'];
        yield 'a possessive repeat of two branches' => ['pattern' => '/(*NOTEMPTY)^(?:a|a)+(?:\K|)++/'];
        yield 'an atomic optional group' => ['pattern' => '/(*NOTEMPTY)^(?:a|a)+(?>(?:\K)?)/'];
        yield 'a possessive star' => ['pattern' => '/(*NOTEMPTY)^(?:a|a)+(?:\K)*+/'];
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

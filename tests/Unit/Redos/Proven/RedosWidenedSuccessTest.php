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
 * The model reads a bounded repeat above the cutoff, or one whose copies
 * read the same input in two ways, as unbounded, and keeps an atomic body
 * other than one run with every way through it. Both widen what the
 * pattern reads, which only adds work to the model, but they also widen
 * what succeeds: "[^x]{0,20}(?:x|\z)" read as "[^x]*(?:x|\z)" accepts
 * whatever follows, where the engine fails once twenty characters are not
 * enough. A loop is set aside when every continuation after it succeeds;
 * read through such a repeat or body, that success is not the engine's,
 * and "^(?:a|a)+[^x]{0,20}(?:x|\z)" was safe (proven) while PHP exhausts
 * its backtrack limit on 20 "a" and 30 "b". A success the model reads
 * exactly still sets the loop aside: "[^x]*", the empty way through
 * ".{0,20}", and the longest run "(?>[^x]*)" commits to.
 *
 * Each row carries its engine fact, measured in the same test: the steps
 * one match attempt needs in the interpreter, auto-possession and start
 * optimizations off (PcreAttemptSteps). PHP 8.4.28, PCRE2 10.49, on 4 then
 * 8 "a" and 30 "b": 1 113 -> 18 873 steps for "^(?:a|a)+[^x]{0,17}(?:x|\z)",
 * 933 -> 15 813 for "^(?:a|a)+(?:[^x]|[^x]){0,3}(?:x|\z)", 631 -> 1 891
 * (3 823 on 12) for "^a*a*[^x]{0,20}(?:x|\z)"; on 4 then 8 "a" and a "!",
 * 46 -> 766 for "^(?:a+)+(?>.+|y)\b", 84 -> 1 404 for "^(?:a+)+(?:.*|y)?+\b", 13 -> 21
 * for "^(?:a|a)+[^x]*(?:x|\z)".
 */
final class RedosWidenedSuccessTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSuccessesTheModelWidens')]
    public function test_a_success_read_through_a_widened_part_does_not_set_a_loop_aside(string $pattern, string $tail, int $short, int $long): void
    {
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        $shortSteps = PcreAttemptSteps::steps($counted, str_repeat('a', $short).$tail);
        $longSteps = PcreAttemptSteps::steps($counted, str_repeat('a', $long).$tail);
        $this->assertGreaterThanOrEqual(5 * $shortSteps, 2 * $longSteps, \sprintf('Oracle: %s takes %d then %d steps on %d then %d "a" and %s: linear.', $pattern, $shortSteps, $longSteps, $short, $long, json_encode($tail)));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
        $this->assertNotSame(RedosComplexity::Linear, $analysis->complexity, $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, tail: string, short: int, long: int}>
     */
    public static function provideSuccessesTheModelWidens(): iterable
    {
        $run = str_repeat('b', 30);

        yield 'a bounded repeat past the cutoff' => ['pattern' => '/^(?:a|a)+[^x]{0,20}(?:x|\z)/', 'tail' => $run, 'short' => 4, 'long' => 8];
        yield 'one past the cutoff' => ['pattern' => '/^(?:a|a)+[^x]{0,17}(?:x|\z)/', 'tail' => $run, 'short' => 4, 'long' => 8];
        yield 'a bounded repeat that must read once' => ['pattern' => '/^(?:a|a)+[^x]{1,20}(?:x|\z)/', 'tail' => $run, 'short' => 4, 'long' => 8];
        yield 'a lazy bounded repeat' => ['pattern' => '/^(?:a|a)+[^x]{0,20}?(?:x|\z)/', 'tail' => $run, 'short' => 4, 'long' => 8];
        yield 'a bounded repeat in a group' => ['pattern' => '/^(?:a|a)+(?:[^x]{0,20})(?:x|\z)/', 'tail' => $run, 'short' => 4, 'long' => 8];
        yield 'a bounded repeat of bounded repeats' => ['pattern' => '/^(?:a|a)+(?:[^x]{1,5}){1,5}(?:x|\z)/', 'tail' => $run, 'short' => 4, 'long' => 6];
        yield 'copies that read the same input in two ways' => ['pattern' => '/^(?:a|a)+(?:[^x]|[^x]){0,3}(?:x|\z)/', 'tail' => $run, 'short' => 4, 'long' => 8];
        // A first alternative that may fail: the group may try "y".
        yield 'an atomic body kept with every way through it' => ['pattern' => '/^(?:a+)+(?>.+|y)\b/', 'tail' => '!', 'short' => 4, 'long' => 8];
        // The possessive repeat commits to ".*", after which no word
        // boundary holds before the "!".
        yield 'a possessive repeat kept with every way through it' => ['pattern' => '/^(?:a+)+(?:.*|y)?+\b/', 'tail' => '!', 'short' => 4, 'long' => 8];
        // Two loops over the same "a": a polynomial link, set aside as well
        // when its second loop reads on into the widened repeat.
        yield 'a link into a loop before a bounded repeat' => ['pattern' => '/^a*a*[^x]{0,20}(?:x|\z)/', 'tail' => $run, 'short' => 4, 'long' => 12];
    }

    #[Test]
    #[DataProvider('provideSuccessesTheModelReadsExactly')]
    public function test_a_success_read_exactly_still_sets_a_loop_aside(string $pattern, string $tail): void
    {
        $counted = PcreAttemptSteps::pinned(self::withoutStartOptimizations($pattern));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 8).$tail);
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 16).$tail);
        $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 16 "a" and %s.', $pattern, $short, $long, json_encode($tail)));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, tail: string}>
     */
    public static function provideSuccessesTheModelReadsExactly(): iterable
    {
        $run = str_repeat('b', 30);

        yield 'an unbounded run' => ['pattern' => '/^(?:a|a)+[^x]*(?:x|\z)/', 'tail' => $run];
        // The repeat reads nothing on the way to the success.
        yield 'the empty way through a bounded repeat' => ['pattern' => '/^(?:a|a)+.{0,20}/', 'tail' => $run];
        yield 'the longest run an atomic group commits to' => ['pattern' => '/^(?:a|a)+(?>[^x]*)(?:x|\z)/', 'tail' => $run];
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

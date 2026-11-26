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
 * A bounded repeat past the cutoff is read as unbounded, and then rejects
 * nothing: "[^x]{0,20}(?:x|\z)" read as "[^x]*(?:x|\z)" accepts whatever
 * follows, so an ambiguous loop before it has no witness. Its copies
 * written out, twenty-one characters that are not an "x" fail after every
 * split of the loop. Where an ambiguity is left without witness, the
 * proof is asked again with such repeats written out, up to 64 copies.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49, the interpreter, without
 * auto-possession or start optimizations, the steps of one attempt read
 * from the match limit, on a run of "a" and more "b" than the bound.
 */
final class RedosWrittenBoundTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAmbiguitiesBeforeABoundPastTheCutoff')]
    public function test_an_ambiguity_before_a_bound_past_the_cutoff_is_witnessed_on_its_copies(string $pattern, int $run, int $short, int $long, int $growth, RedosComplexity $complexity, ?int $degree): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $shortSteps = PcreAttemptSteps::steps($counted, str_repeat('a', $short).str_repeat('b', $run));
        $longSteps = PcreAttemptSteps::steps($counted, str_repeat('a', $long).str_repeat('b', $run));
        $this->assertGreaterThan($growth * $shortSteps, $longSteps, \sprintf('Oracle: %s takes %d then %d steps on %d then %d "a" and %d "b".', $pattern, $shortSteps, $longSteps, $short, $long, $run));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, $complexity, $degree], [$analysis->proof, $analysis->complexity, $analysis->degree], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string, run: int, short: int, long: int, growth: int, complexity: RedosComplexity, degree: int|null}>
     */
    public static function provideAmbiguitiesBeforeABoundPastTheCutoff(): iterable
    {
        yield 'twenty copies' => ['pattern' => '/^(?:a|a)+[^x]{0,20}(?:x|\z)/', 'run' => 30, 'short' => 4, 'long' => 8, 'growth' => 8, 'complexity' => RedosComplexity::Exponential, 'degree' => null];
        yield 'one past the cutoff' => ['pattern' => '/^(?:a|a)+[^x]{0,17}(?:x|\z)/', 'run' => 30, 'short' => 4, 'long' => 8, 'growth' => 8, 'complexity' => RedosComplexity::Exponential, 'degree' => null];
        yield 'sixty-four copies' => ['pattern' => '/^(?:a|a)+[^x]{0,64}(?:x|\z)/', 'run' => 70, 'short' => 4, 'long' => 8, 'growth' => 8, 'complexity' => RedosComplexity::Exponential, 'degree' => null];
        yield 'two loops, quadratic' => ['pattern' => '/^a*a*[^x]{0,20}(?:x|\z)/', 'run' => 30, 'short' => 8, 'long' => 32, 'growth' => 6, 'complexity' => RedosComplexity::Polynomial, 'degree' => 2];
    }

    /**
     * An ambiguity the copies leave without witness too keeps the
     * heuristics' verdict: "(?:.|$)" accepts the "a" the loop gives back
     * before a newline that is not the last, which the model does not tell
     * from the final one. The engine is linear.
     */
    #[Test]
    public function test_an_ambiguity_its_copies_leave_without_witness_keeps_the_heuristics(): void
    {
        $pattern = '/\Ab{0,20}(?:a|a)+(?:.|$)/';
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 8)."\nx");
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 16)."\nx");
        $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 16 "a", a newline and an "x".', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Heuristic, RedosComplexity::Unknown], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * Past 64 copies the bound stays read as unbounded, and the ambiguity
     * without witness: the heuristics decide.
     */
    #[Test]
    public function test_a_bound_past_sixty_four_copies_is_not_written_out(): void
    {
        $pattern = '/^(?:a|a)+[^x]{0,65}(?:x|\z)/';
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 4).str_repeat('b', 70));
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 8).str_repeat('b', 70));
        $this->assertGreaterThan(8 * $short, $long, \sprintf('Oracle: %s takes %d then %d steps on 4 then 8 "a" and seventy "b".', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Heuristic, RedosComplexity::Unknown], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }
}

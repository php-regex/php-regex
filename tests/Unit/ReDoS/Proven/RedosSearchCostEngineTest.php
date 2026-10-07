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

use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search-cost verdict replayed on the running PCRE2, without a clock:
 * the steps of one attempt are the smallest pcre.backtrack_limit that lets it
 * finish (PcreAttemptSteps). A reported witness gives attempts whose steps
 * grow linearly with what is left of the run, at every start inside it; an
 * immune pattern gives bounded attempts past the search start.
 *
 * Measured on PHP 8.4.26 / PCRE2 10.49, interpreter, auto-possession off,
 * k = 250 / 500 / 750 / 1000 run words before the end of a 1000-word run:
 * 251 / 501 / 751 / 1001 steps for /\s+$/, /a+b/, /x?a+b/, /[é]+x/u,
 * /^\s+x/m, /.*\n+x/; 252 / 502 / 752 / 1002 for /[a-z]+\d/, /a+?b/,
 * /(?:ab)+c/ (run "ab"), /(\w+)\s*=/; 1003 / 2003 / 3003 / 4003 for
 * /(?:a|b)+c/ (run "ab"); 501 / 1001 / 1501 / 2001 for /\s+(?=x$)/. Each
 * attempt fails.
 */
final class RedosSearchCostEngineTest extends TestCase
{
    private const QUARTER = 250;

    #[Test]
    #[DataProvider('provideWitnesses')]
    public function test_witness_attempt_steps_grow_linearly_with_the_rest_of_the_run(string $pattern, RedosMode $mode): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, $mode)->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);

        $subject = $cost->build(4 * self::QUARTER);
        $pinned = PcreAttemptSteps::pinned($pattern);

        $steps = [];
        foreach ([1, 2, 3, 4] as $quarters) {
            $offset = \strlen($cost->prefix) + (4 - $quarters) * self::QUARTER * \strlen($cost->run);
            $this->assertSame(0, preg_match($pinned, $subject, $matches, 0, $offset), \sprintf('%s: the attempt %d run words before the breaker matches.', $pattern, $quarters * self::QUARTER));
            $steps[] = PcreAttemptSteps::steps($pinned, $subject, $offset);
        }

        $growth = [$steps[1] - $steps[0], $steps[2] - $steps[1], $steps[3] - $steps[2]];
        $this->assertSame([$growth[0], $growth[0], $growth[0]], $growth, $pattern.': steps '.implode(' / ', $steps).' are not linear in the rest of the run.');
        $this->assertGreaterThanOrEqual(self::QUARTER, $growth[0], $pattern.': fewer steps than run words.');
    }

    /**
     * @return iterable<string, array{pattern: string, mode: RedosMode}>
     */
    public static function provideWitnesses(): iterable
    {
        foreach (RedosSearchCostTest::provideQuadraticSearches() as $name => $row) {
            yield $name => ['pattern' => $row['pattern'], 'mode' => RedosMode::Theoretical];
        }
        foreach (RedosSearchCostTest::provideLookaroundSearches() as $name => $row) {
            yield $name.', confirmed' => ['pattern' => $row['pattern'], 'mode' => RedosMode::Confirmed];
        }
    }

    /**
     * The first attempt, at the subject start, is not like the others: "^"
     * holds there (2000 steps against 1001 for /(?:a|^)a+b/), a lookbehind
     * does not (3 steps). The attempts inside the run, past the subject
     * start, grow linearly with what is left of it all the same. A
     * lookbehind is not in the model: reported once the replay confirms it.
     */
    #[Test]
    #[DataProvider('provideWitnessesWithAnotherFirstAttempt')]
    public function test_witness_attempts_past_the_subject_start_grow_linearly(string $pattern, RedosMode $mode): void
    {
        $analyzer = new RedosAnalyzer();
        $cost = $analyzer->analyze($pattern, RedosSeverity::Low, $mode)->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        if (RedosMode::Confirmed === $mode) {
            $this->assertTrue($cost->replayed, $pattern);
            $this->assertNull(self::searchCostOf($analyzer->analyze($pattern)), $pattern);
        }

        foreach ([1, 2, 3, 100] as $words) {
            $this->assertSame(0, preg_match($pattern, $cost->build($words)), \sprintf('%s on %d run words', $pattern, $words));
        }

        $subject = $cost->build(4 * self::QUARTER);
        $pinned = PcreAttemptSteps::pinned($pattern);
        $steps = [];
        foreach ([1, 2, 3] as $quarters) {
            $offset = \strlen($cost->prefix) + (4 - $quarters) * self::QUARTER * \strlen($cost->run);
            $this->assertSame(0, preg_match($pinned, $subject, $matches, 0, $offset), \sprintf('%s: the attempt %d run words before the breaker matches.', $pattern, $quarters * self::QUARTER));
            $steps[] = PcreAttemptSteps::steps($pinned, $subject, $offset);
        }

        $growth = [$steps[1] - $steps[0], $steps[2] - $steps[1]];
        $this->assertSame([$growth[0], $growth[0]], $growth, $pattern.': steps '.implode(' / ', $steps).' are not linear in the rest of the run.');
        $this->assertGreaterThanOrEqual(self::QUARTER, $growth[0], $pattern.': fewer steps than run words.');
    }

    /**
     * Interpreter: 8.1 / 31.5 / 124.7 ms on "a"x5k/10k/20k."!b" for the
     * alternation, 108.7 / 427.5 ms on " "x4k/8k."!" and 5.2 / 20.3 ms on
     * "a"x4k/8k."!b" for the lookbehinds (pcre.jit=0).
     *
     * @return iterable<string, array{pattern: string, mode: RedosMode}>
     */
    public static function provideWitnessesWithAnotherFirstAttempt(): iterable
    {
        yield 'alternation with an anchored alternative' => ['pattern' => '/(?:a|^)a+b/', 'mode' => RedosMode::Theoretical];
        yield 'lookbehind before the run' => ['pattern' => '/(?<=\s)\s+$/', 'mode' => RedosMode::Confirmed];
        yield 'lookbehind before a literal run' => ['pattern' => '/(?<=a)a+b/', 'mode' => RedosMode::Confirmed];
    }

    /**
     * The caret and \A test the subject start, not the offset: an attempt
     * pinned past it fails at once (2 steps at most, against 1001 at offset
     * 0 on "a"×1000."cb"). The "=" row: only the attempt at "=" enters the
     * run.
     */
    #[Test]
    #[DataProvider('provideOneLongAttempt')]
    public function test_immune_pattern_attempts_past_the_search_start_are_bounded(string $pattern, string $subject): void
    {
        $this->assertNull(self::searchCostOf((new RedosAnalyzer())->analyze($pattern)), $pattern);

        $pinned = PcreAttemptSteps::pinned($pattern);
        $this->assertGreaterThan(1000, PcreAttemptSteps::steps($pinned, $subject, 0), $pattern.': the first attempt is not the long one, the row proves nothing.');
        for ($offset = 1; $offset <= \strlen($subject); $offset += 37) {
            $this->assertLessThanOrEqual(2, PcreAttemptSteps::steps($pinned, $subject, $offset), \sprintf('%s from offset %d', $pattern, $offset));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideOneLongAttempt(): iterable
    {
        yield 'caret' => ['pattern' => '/^a+b/', 'subject' => str_repeat('a', 1000).'cb'];
        yield '\A' => ['pattern' => '/\Aa+b/', 'subject' => str_repeat('a', 1000).'cb'];
        yield 'run entered by another character' => ['pattern' => '/=\s+$/', 'subject' => '='.str_repeat(' ', 1000).'x'];
    }

    /**
     * \G holds wherever an attempt is pinned, so a pinned attempt shows
     * nothing: the search itself does. The first attempt fails at once; under
     * a per-attempt limit of 20 the anchored search finishes, the unanchored
     * one (which retries inside the run) does not.
     */
    #[Test]
    #[DataProvider('provideAnchoredAgainstUnanchored')]
    public function test_anchored_search_finishes_under_a_limit_the_unanchored_search_exceeds(string $anchored, string $unanchored, string $subject): void
    {
        $analyzer = new RedosAnalyzer();
        $this->assertNull(self::searchCostOf($analyzer->analyze($anchored)), $anchored);
        $this->assertInstanceOf(RedosSearchCost::class, $analyzer->analyze($unanchored)->searchCost, $unanchored);

        $this->assertSame(0, PcreAttemptSteps::searchUnderLimit(PcreAttemptSteps::counted($anchored), $subject, 20), $anchored);
        $this->assertFalse(PcreAttemptSteps::searchUnderLimit(PcreAttemptSteps::counted($unanchored), $subject, 20), $unanchored);
    }

    /**
     * @return iterable<string, array{anchored: string, unanchored: string, subject: string}>
     */
    public static function provideAnchoredAgainstUnanchored(): iterable
    {
        $literal = 'c'.str_repeat('a', 1000).'cb';
        yield '\G' => ['anchored' => '/\Ga+b/', 'unanchored' => '/a+b/', 'subject' => $literal];
        yield 'A modifier' => ['anchored' => '/a+b/A', 'unanchored' => '/a+b/', 'subject' => $literal];
        yield 'caret' => ['anchored' => '/^a+b/', 'unanchored' => '/a+b/', 'subject' => $literal];
        yield 'caret under m, newline-free run' => ['anchored' => '/^[a-z]+\d/m', 'unanchored' => '/[a-z]+\d/', 'subject' => '-'.str_repeat('a', 1000).'-1'];
    }

    /**
     * A run that can hold a newline does not make /^\s+$/m quadratic: a
     * newline after a whitespace character lets $ match, so on a subject the
     * search does not match only the attempts at the subject start and at
     * the one line start inside the run are long.
     */
    #[Test]
    #[DataProvider('provideCaretDollarSubjects')]
    public function test_caret_and_dollar_under_m_make_at_most_two_long_attempts(string $subject): void
    {
        $this->assertNull(self::searchCostOf((new RedosAnalyzer())->analyze('/^\s+$/m')));

        $this->assertSame(0, preg_match('/^\s+$/m', $subject));
        $pinned = PcreAttemptSteps::pinned('/^\s+$/m');
        $long = [];
        for ($offset = 0; $offset <= \strlen($subject); $offset++) {
            if (PcreAttemptSteps::steps($pinned, $subject, $offset) > 3) {
                $long[] = $offset;
            }
        }
        $this->assertLessThanOrEqual(2, \count($long), 'long attempts at '.implode(', ', $long));
    }

    /**
     * Long attempts: [0, 1] on the first subject, [0] on the second.
     *
     * @return iterable<string, array{subject: string}>
     */
    public static function provideCaretDollarSubjects(): iterable
    {
        yield 'newline then a space run' => ['subject' => "\n".str_repeat(' ', 300).'x'];
        yield 'space run' => ['subject' => str_repeat(' ', 300).'x'];
    }

    /**
     * Only " breaks [^"]+ and it completes the match; without one, PCRE2's
     * required-code-unit check ends every attempt in one step.
     */
    #[Test]
    public function test_required_code_unit_ends_every_attempt_of_a_run_it_cannot_break(): void
    {
        $this->assertNull(self::searchCostOf((new RedosAnalyzer())->analyze('/[^"]+"/')));

        $subject = str_repeat('a', 1000);
        $pinned = PcreAttemptSteps::pinned('/[^"]+"/');
        for ($offset = 0; $offset < 1000; $offset += 37) {
            $this->assertSame(1, PcreAttemptSteps::steps($pinned, $subject, $offset), 'offset '.$offset);
        }
        $this->assertSame(1, preg_match('/[^"]+"/', $subject.'"', $matches, \PREG_OFFSET_CAPTURE));
        $this->assertSame(0, $matches[0][1]);
    }

    /**
     * Engine fact the verdict's wording relies on: pcre.backtrack_limit is
     * counted per start position, so it does not stop the search cost. With
     * the limit at the steps of the longest attempt, the search over
     * " "×2000."x" finishes, its 2000 attempts summing to about 1000 times
     * that limit; one step less and the first attempt trips it.
     */
    #[Test]
    public function test_backtrack_limit_is_counted_per_attempt_and_does_not_stop_the_search_cost(): void
    {
        $subject = str_repeat(' ', 2000).'x';
        $longest = PcreAttemptSteps::steps(PcreAttemptSteps::pinned('/\s+$/'), $subject, 0);
        // 2001 on 10.49: one step per character of the run.
        $this->assertGreaterThanOrEqual(2000, $longest);

        $this->assertSame(0, PcreAttemptSteps::searchUnderLimit(PcreAttemptSteps::counted('/\s+$/'), $subject, $longest));
        $this->assertFalse(PcreAttemptSteps::searchUnderLimit(PcreAttemptSteps::counted('/\s+$/'), $subject, $longest - 1));
    }

    /**
     * The search cost of an analysis, failing when RedosAnalysis has no such
     * property: reading a missing one gives null with a warning, and a null
     * is what the negative rows expect.
     */
    private static function searchCostOf(RedosAnalysis $analysis): ?RedosSearchCost
    {
        self::assertObjectHasProperty('searchCost', $analysis, 'RedosAnalysis has no searchCost property.');

        return $analysis->searchCost;
    }
}

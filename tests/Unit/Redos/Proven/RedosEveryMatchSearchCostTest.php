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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Backtrack\BacktrackProver;
use PHPRegex\Redos\Backtrack\SearchCostProver;
use PHPRegex\Redos\Backtrack\SearchCostReplayer;
use PHPRegex\Redos\Backtrack\SearchCostReplayerInterface;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search of the functions that look for every match.
 *
 * preg_match_all(), preg_replace(), preg_filter(), preg_replace_callback(),
 * preg_replace_callback_array() and preg_split() go on after each match;
 * after an empty one PHP tries the same offset again with
 * PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED, then moves one character on
 * (ext/pcre/php_pcre.c, since PHP 5.6.13; PHP 4.0.1 with PCRE_NOTEMPTY). A
 * pattern that matches the empty string at each position of a run, and
 * whose retry there reads to the end of the run, costs those functions a
 * number of steps quadratic in the run, where preg_match() stops at the
 * first empty match. PCRE2's JIT takes no PCRE2_ANCHORED at match time, so
 * the retry always runs in the interpreter.
 *
 * PHP 8.4.28, PCRE2 10.49, "(?:a+\d)?" on 2 000, 4 000, 8 000 and 16 000
 * "a": preg_match_all() 2.27, 8.61, 34.08 and 130.96 ms with pcre.jit=0,
 * 1.75, 6.75, 26.74 and 105.54 ms with pcre.jit=1, preg_match() 0.01 ms; the
 * retry pinned at the run's start then half-way takes 2 002 then 1 002
 * steps on 2 000 "a" (auto-possession off, PcreAttemptSteps).
 */
final class RedosEveryMatchSearchCostTest extends TestCase
{
    private const QUARTER = 250;

    /**
     * Each first attempt inside the run ends on an empty match, and each
     * retry, which refuses it, reads the rest of the run then fails: the
     * cost is reported for the functions that look for every match, and
     * the search of preg_match() keeps its verdict.
     */
    #[Test]
    #[DataProvider('provideEveryMatchSearches')]
    public function test_every_match_search_cost_is_reported_when_each_retry_reads_the_run(string $pattern, string $run, bool $exact, bool $counted): void
    {
        $interpreted = PcreInterpreter::pattern($pattern);
        foreach ([1, 2, 3, 100] as $words) {
            $subject = str_repeat($run, $words).'!';
            $this->assertSame(1, preg_match($interpreted, $subject, $matches, \PREG_OFFSET_CAPTURE), \sprintf('Oracle: %s finds no match in %d run words.', $pattern, $words));
            $this->assertSame('', $matches[0][0], \sprintf('Oracle: preg_match() does not stop at an empty match with %s on %d run words.', $pattern, $words));
            $this->assertLessThanOrEqual(\strlen($run), $matches[0][1], \sprintf('Oracle: preg_match() stops past the first run word with %s on %d run words.', $pattern, $words));
        }

        // Every position, or every other one where the pattern itself
        // refuses an empty match at the start of each call of the loop.
        $this->assertGreaterThanOrEqual(50, preg_match_all($interpreted, str_repeat($run, 100).'!'), 'Oracle: preg_match_all() skips most positions of 100 run words with '.$pattern.'.');

        $this->assertRetriesGrowWithTheRestOfTheRun($pattern, $run, $counted);

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertNull($analysis->searchCost, $pattern.': preg_match() stops at the first empty match.');

        $confirmed = $analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)->everyMatchSearchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $confirmed, $pattern.' in confirmed mode');
        $this->assertSame($counted, $confirmed->replayed, $pattern);

        $cost = $analysis->everyMatchSearchCost;
        if (!$exact) {
            $this->assertNull($cost, $pattern.' is reported before the engine replay.');

            return;
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame(2, $cost->degree, $pattern);
        $this->assertSame(['', $run], [$cost->prefix, $cost->run], $pattern);
        $this->assertNull($cost->replayed, $pattern);
        $this->assertGreaterThanOrEqual(50, preg_match_all($interpreted, $cost->build(100)), $pattern.' skips most positions of the witness on 100 run words.');
    }

    /**
     * @return iterable<string, array{pattern: string, run: string, exact: bool, counted: bool}>
     */
    public static function provideEveryMatchSearches(): iterable
    {
        yield 'optional group' => ['pattern' => '/(?:a+\d)?/', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield 'optional group ending with a literal' => ['pattern' => '/(?:a+b)?/', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield 'lazy optional group' => ['pattern' => '/(?:a+\d)??/', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield 'empty branch first' => ['pattern' => '/(?:|a+\d)/', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield 'empty branch last' => ['pattern' => '/(?:a+\d|)/', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield 'capturing group' => ['pattern' => '/((?:a+\d)?)/', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield 'optional group under u' => ['pattern' => '/(?:é+\d)?/u', 'run' => 'é', 'exact' => true, 'counted' => true];
        yield 'A modifier' => ['pattern' => '/(?:a+\d)?/A', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield '\G' => ['pattern' => '/\G(?:a+\d)?/', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield 'caret under m on a run of newlines' => ['pattern' => '/^(?:\n+\d)?/m', 'run' => "\n", 'exact' => true, 'counted' => true];
        yield 'empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)(?:a+\d)?/', 'run' => 'a', 'exact' => true, 'counted' => true];
        yield '\K before an item that fails' => ['pattern' => '/(?:a+\K\d)?/', 'run' => 'a', 'exact' => true, 'counted' => true];
        // The step counter sees nothing inside a possessive repeat of one
        // set, though the retry reads the run: 2.22, 8.42, 32.72 and
        // 129.50 ms through preg_match_all() on 2 000 to 16 000 "a" with
        // pcre.jit=0.
        yield 'possessive run' => ['pattern' => '/(?:a++\d)?/', 'run' => 'a', 'exact' => true, 'counted' => false];
        yield 'lookahead after the run' => ['pattern' => '/(?:[a-z]+(?=\d))?/', 'run' => 'a', 'exact' => false, 'counted' => true];
    }

    /**
     * A class run: the witness holds any character the class reads, which
     * the engine replays through every function that looks for every
     * match.
     */
    #[Test]
    #[DataProvider('provideClassRuns')]
    public function test_every_match_search_cost_reads_a_run_of_a_class(string $pattern, string $run): void
    {
        $interpreted = PcreInterpreter::pattern($pattern);
        $subject = str_repeat($run, 100).'!';
        $this->assertSame(1, preg_match($interpreted, $subject, $matches, \PREG_OFFSET_CAPTURE), 'Oracle: '.$pattern);
        $this->assertSame(['', 0], $matches[0], 'Oracle: '.$pattern.' does not stop at an empty match at 0.');
        $this->assertRetriesGrowWithTheRestOfTheRun($pattern, $run, true);

        $cost = (new RedosAnalyzer())->analyze($pattern)->everyMatchSearchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame('', $cost->prefix, $pattern);
        $this->assertSame(1, preg_match('/^.$/su', $cost->run), $pattern.': the run word is not one character.');
        $this->assertRetriesGrowWithTheRestOfTheRun($pattern, $cost->run, true);
        $this->assertGreaterThanOrEqual(100, preg_match_all($interpreted, $cost->build(100)), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, run: string}>
     */
    public static function provideClassRuns(): iterable
    {
        yield 'negated class before its own character' => ['pattern' => '/(?:[^,]*,)?/', 'run' => 'a'];
        yield 'white space before a letter' => ['pattern' => '/(?:\s+x)?/', 'run' => ' '];
    }

    /**
     * The retry swallows the run, reads nothing or one character, reads a
     * bounded number of them, or holds at the subject's start alone: the
     * search of every match is linear, and nothing is reported.
     */
    #[Test]
    #[DataProvider('provideShortRetries')]
    public function test_every_match_search_cost_is_not_reported_when_the_retry_is_short(string $pattern, string $run): void
    {
        $subject = str_repeat($run, 4 * self::QUARTER).'!';
        $retry = PcreAttemptSteps::pinned(self::retried($pattern));
        $far = PcreAttemptSteps::steps($retry, $subject, self::QUARTER * \strlen($run));
        $near = PcreAttemptSteps::steps($retry, $subject, 2 * self::QUARTER * \strlen($run));
        $this->assertLessThan(self::QUARTER, $far - $near, \sprintf('Oracle: the retry of %s grows with the rest of the run (%d then %d steps).', $pattern, $far, $near));

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyze($pattern)->everyMatchSearchCost, $pattern);
        $this->assertNull($analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)->everyMatchSearchCost, $pattern.' in confirmed mode');
    }

    /**
     * @return iterable<string, array{pattern: string, run: string}>
     */
    public static function provideShortRetries(): iterable
    {
        yield 'star loop' => ['pattern' => '/a*/', 'run' => 'a'];
        yield 'star loop of another class' => ['pattern' => '/\d*/', 'run' => 'a'];
        yield 'optional literal' => ['pattern' => '/x?/', 'run' => 'a'];
        yield 'lazy star loop' => ['pattern' => '/(?:a|b)*?/', 'run' => 'a'];
        yield 'lookahead alone' => ['pattern' => '/(?=a)/', 'run' => 'a'];
        yield 'word boundary' => ['pattern' => '/\b/', 'run' => 'a'];
        yield 'caret under m' => ['pattern' => '/^/m', 'run' => 'a'];
        yield 'dollar' => ['pattern' => '/$/', 'run' => 'a'];
        yield 'caret' => ['pattern' => '/^(?:a+\d)?/', 'run' => 'a'];
        yield '\A' => ['pattern' => '/\A(?:a+\d)?/', 'run' => 'a'];
        yield 'caret under m on a run without newline' => ['pattern' => '/^(?:a+\d)?/m', 'run' => 'a'];
        yield 'bounded repeat at the unrolling cutoff' => ['pattern' => '/(?:a{1,16}\d)?/', 'run' => 'a'];
        yield 'bounded repeat above the unrolling cutoff' => ['pattern' => '/(?:a{1,17}\d)?/', 'run' => 'a'];
        yield '\K after the run' => ['pattern' => '/a*\K/', 'run' => 'a'];
    }

    /**
     * Where preg_match() itself pays for the run, the cost is the search
     * cost of every call, or none the proof finds; never one of the
     * functions that look for every match alone.
     */
    #[Test]
    #[DataProvider('provideSearchesPaidByPregMatch')]
    public function test_every_match_search_cost_is_not_reported_when_preg_match_pays_the_run(string $pattern, string $run, bool $searchCost): void
    {
        $subject = str_repeat($run, 4 * self::QUARTER).'!';
        $found = preg_match(PcreInterpreter::pattern($pattern), $subject, $matches, \PREG_OFFSET_CAPTURE);
        $this->assertTrue(1 !== $found || $matches[0][1] >= 4 * self::QUARTER * \strlen($run), 'Oracle: '.$pattern.' matches inside the run.');
        $pinned = PcreAttemptSteps::pinned($pattern);
        $far = PcreAttemptSteps::steps($pinned, $subject, self::QUARTER * \strlen($run));
        $near = PcreAttemptSteps::steps($pinned, $subject, 2 * self::QUARTER * \strlen($run));
        $this->assertGreaterThanOrEqual(self::QUARTER, $far - $near, \sprintf('Oracle: the attempt of %s does not grow with the rest of the run (%d then %d steps).', $pattern, $far, $near));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertNull($analysis->everyMatchSearchCost, $pattern);
        $this->assertSame($searchCost, null !== $analysis->searchCost, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, run: string, searchCost: bool}>
     */
    public static function provideSearchesPaidByPregMatch(): iterable
    {
        yield 'empty match refused everywhere' => ['pattern' => '/(*NOTEMPTY)(?:a+\d)?/', 'run' => 'a', 'searchCost' => true];
        yield 'trailing white space' => ['pattern' => '/\s+$/', 'run' => ' ', 'searchCost' => true];
        // The empty match holds at the subject's end alone, after every
        // attempt inside the run failed: preg_match() is quadratic, and the
        // search proof, which wants no attempt to match anywhere, finds no
        // witness.
        yield 'optional group before the dollar' => ['pattern' => '/(?:a+\d)?$/', 'run' => 'a', 'searchCost' => false];
    }

    /**
     * Under (*NOTEMPTY) no match is empty, so PHP never retries: the
     * search of every match is the search of preg_match(). Tied to the
     * search start by \G, neither costs a run here.
     */
    #[Test]
    public function test_every_match_search_cost_is_not_reported_when_no_match_is_empty(): void
    {
        $pattern = '/(*NOTEMPTY)\G(?:a+\d)?/';
        $this->assertSame(0, preg_match_all(PcreInterpreter::pattern($pattern), str_repeat('a', 100).'!'), 'Oracle: '.$pattern.' matches.');

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertNull($analysis->searchCost, $pattern);
        $this->assertNull($analysis->everyMatchSearchCost, $pattern);
    }

    /**
     * The replay says what the engine shows: the search from a quarter of
     * the run must stop on an empty match within a run word, the retry
     * there must fail, and the limits may leave it unable to count.
     *
     * @param array{backtrackLimit?: int} $options
     */
    #[Test]
    #[DataProvider('provideReplays')]
    public function test_replay_of_every_match_says_what_the_engine_shows(string $pattern, string $run, array $options, ?bool $replays): void
    {
        $subject = str_repeat($run, 256).'!';
        $quarter = 64 * \strlen($run);
        $found = preg_match(PcreInterpreter::pattern($pattern), $subject, $matches, \PREG_OFFSET_CAPTURE, $quarter);
        $retried = preg_match(PcreInterpreter::pattern(self::retried($pattern)).'A', $subject, $retriedMatches, 0, $quarter);
        // The oracle of each outcome, as the engine gives it.
        if (true === $replays) {
            $this->assertSame([1, ['', $quarter], 0], [$found, $matches[0], $retried], 'Oracle: '.$pattern);
        } elseif (false === $replays) {
            $this->assertTrue(1 !== $found || '' !== $matches[0][0] || 1 === $retried, 'Oracle: the search stops on an empty match the retry cannot leave with '.$pattern);
        } else {
            $this->assertSame([1, ''], [$found, $matches[0][0]], 'Oracle: '.$pattern);
        }

        $this->assertSame($replays, (new SearchCostReplayer())->replaysEveryMatch($pattern, '', $run, '!', new ConfirmationOptions(...$options)), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, run: string, options: array{backtrackLimit?: int}, replays: bool|null}>
     */
    public static function provideReplays(): iterable
    {
        yield 'each retry reads the rest of the run' => ['pattern' => '/(?:a+\d)?/', 'run' => 'a', 'options' => [], 'replays' => true];
        yield 'the search matches the run' => ['pattern' => '/a*/', 'run' => 'a', 'options' => [], 'replays' => false];
        yield 'the retry matches a character' => ['pattern' => '/(?:a|b)*?/', 'run' => 'a', 'options' => [], 'replays' => false];
        // The search stops at once on its first, empty, choice; the retry
        // reads the rest of the run, more steps than the limit.
        yield 'limits too low for the retry' => ['pattern' => '/(?:a+\d)??/', 'run' => 'a', 'options' => ['backtrackLimit' => 50], 'replays' => null];
        yield 'limits too low for the search' => ['pattern' => '/(?:a+\d)?/', 'run' => 'a', 'options' => ['backtrackLimit' => 50], 'replays' => null];
    }

    /**
     * A pattern that leaves no delimiter for the counting verbs cannot be
     * replayed.
     */
    #[Test]
    public function test_replay_of_every_match_needs_a_place_for_its_verbs(): void
    {
        $this->assertFalse((new SearchCostReplayer())->replaysEveryMatch("_(?:a+\\d)?|[\x01#~%!@;,)}>[]_", '', 'a', '!'));
    }

    /**
     * The analysis keeps both costs apart: "search_cost" stays the one of
     * every call, "every_match_search_cost" holds the other.
     */
    #[Test]
    public function test_every_match_search_cost_is_serialized_under_its_own_key(): void
    {
        $analyzer = new RedosAnalyzer();

        $json = $analyzer->analyze('/(?:a+\d)?/')->jsonSerialize();
        $this->assertNull($json['search_cost']);
        $this->assertIsArray($json['every_match_search_cost']);
        $this->assertSame(2, $json['every_match_search_cost']['degree']);
        $this->assertSame('a', $json['every_match_search_cost']['witness']['run']);

        $json = $analyzer->analyze('/\s+$/')->jsonSerialize();
        $this->assertIsArray($json['search_cost']);
        $this->assertNull($json['every_match_search_cost']);
    }

    /**
     * Confirmation keeps the cost of every match, as it keeps the other.
     */
    #[Test]
    public function test_every_match_search_cost_survives_the_confirmation_of_the_attempt(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(?:a+\d)?/', RedosSeverity::Low, RedosMode::Confirmed);
        $copy = $analysis->withConfirmation(null, $analysis->confidence, $analysis->witness, $analysis->replayed);

        $this->assertSame($analysis->everyMatchSearchCost, $copy->everyMatchSearchCost);
    }

    /**
     * A library failure inside the proof of every match gives no cost, as
     * the other proof does; anything else surfaces.
     */
    #[Test]
    public function test_every_match_search_cost_is_null_when_its_replay_throws_a_library_exception(): void
    {
        $this->assertInstanceOf(RedosSearchCost::class, self::findEveryMatch('/(?:a+\d)?/', new SearchCostReplayer()), 'control: the witness is found and replayed');

        $failing = new class implements SearchCostReplayerInterface {
            public bool $called = false;

            public function replays(string $pattern, string $prefix, string $run, string $breaker, ?ConfirmationOptions $options = null): bool
            {
                throw new \LogicException('The plain replay is not asked.');
            }

            public function replaysEveryMatch(string $pattern, string $prefix, string $run, string $breaker, ?ConfirmationOptions $options = null): bool
            {
                $this->called = true;

                throw new ResourceLimitException('The replay ran out of its limits.', ErrorCode::PatternTooLong);
            }
        };

        $this->assertNull(self::findEveryMatch('/(?:a+\d)?/', $failing));
        $this->assertTrue($failing->called, 'The failure was met inside the proof.');
    }

    /**
     * The retry of each first attempt, pinned 750, 500 and 250 run words
     * before the end, fails and takes at least one more step per run word
     * than the next; a possessive run hides its steps from the counter.
     */
    private function assertRetriesGrowWithTheRestOfTheRun(string $pattern, string $run, bool $counted): void
    {
        $subject = str_repeat($run, 4 * self::QUARTER).'!';
        $retry = PcreAttemptSteps::pinned(self::retried($pattern));
        $steps = [];
        foreach ([3, 2, 1] as $quarters) {
            $offset = (4 - $quarters) * self::QUARTER * \strlen($run);
            $this->assertSame(0, preg_match($retry, $subject, $matches, 0, $offset), \sprintf('Oracle: the retry of %s %d run words before the end matches.', $pattern, $quarters * self::QUARTER));
            $steps[] = PcreAttemptSteps::steps($retry, $subject, $offset);
        }

        if (!$counted) {
            $this->assertSame($steps[0], $steps[2], 'Oracle: the counter sees the possessive run of '.$pattern.'.');

            return;
        }

        $this->assertGreaterThanOrEqual(self::QUARTER, $steps[0] - $steps[1], \sprintf('Oracle: the retries of %s take %s steps.', $pattern, implode(', ', $steps)));
        $this->assertGreaterThanOrEqual(self::QUARTER, $steps[1] - $steps[2], \sprintf('Oracle: the retries of %s take %s steps.', $pattern, implode(', ', $steps)));
    }

    /**
     * The pattern as PHP retries it after an empty match: the attempt
     * refuses an empty match at its start (PCRE2_NOTEMPTY_ATSTART); the
     * caller pins it (PCRE2_ANCHORED).
     */
    private static function retried(string $pattern): string
    {
        return $pattern[0].'(*NOTEMPTY_ATSTART)'.substr($pattern, 1);
    }

    /**
     * What the proof of every match finds after a fresh per-attempt proof,
     * replayed by the replayer given.
     */
    private static function findEveryMatch(string $pattern, SearchCostReplayerInterface $replayer): ?RedosSearchCost
    {
        $ast = RegexParser::create()->parse($pattern);
        $options = new RedosOptions();
        $outcome = (new BacktrackProver($options->maxStates, $options->maxSteps, $options->boundedRepeatCutoff))->prove($ast);
        self::assertSame(RedosComplexity::Linear, $outcome->result?->complexity, $pattern);

        return (new SearchCostProver($replayer))->findEveryMatch($pattern, $ast, $outcome, true);
    }
}

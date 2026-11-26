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

use PHPRegex\Redos\Backtrack\SearchCostReplayer;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\SearchCostCause;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search of a loop that looks for every match with preg_match()
 * itself: it calls it again where each match ended, one byte on after an
 * empty one, and makes no retry there. The generator nette/utils'
 * Strings::matchAll() returns under "lazy" is that loop (nette/utils 4.1,
 * src/Utils/Strings.php), which matchLoop() below runs as it is written
 * there.
 *
 * Each call pays the run when the paths its first attempt tries before its
 * match read to the end of the run, the match short or empty. PHP 8.4.28,
 * PCRE2 10.49, on 2,000, 4,000 and 8,000 "a": "(?:a+\d)?" costs the loop
 * 1.18, 3.74 and 12.99 ms with pcre.jit=1, 1.73, 5.30 and 18.44 ms with
 * pcre.jit=0; "(?:a+\d)??", whose empty match comes first, 0.53, 1.06 and
 * 2.12 ms, where preg_match_all() pays its retry after each one.
 */
final class RedosMatchLoopSearchCostTest extends TestCase
{
    private const QUARTER = 250;

    /**
     * Each call of the loop starts inside the run and ends on a match of
     * 64 run words at most, an empty one too, which the attempt settles on
     * once the paths it tries first have read to the end of the run: the
     * attempt pinned further from the end takes more steps.
     */
    #[Test]
    #[DataProvider('provideLoopsWhoseCallsReadTheRun')]
    public function test_match_loop_search_cost_is_reported_when_each_call_reads_the_run(string $pattern, string $run, bool $exact): void
    {
        $this->assertGreaterThanOrEqual(15, self::matchLoop(PcreInterpreter::pattern($pattern), str_repeat($run, 4 * self::QUARTER).'!'), 'Oracle: the loop skips most of the run with '.$pattern.'.');
        $this->assertAttemptsGrowWithTheRestOfTheRun($pattern, $run);

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyzeMatchLoop($pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertNull($analysis->searchCost, $pattern);

        $confirmed = $analyzer->analyzeMatchLoop($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->everyMatchSearchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $confirmed, $pattern.' in confirmed mode');
        $this->assertSame([SearchCostCause::ShortMatch, '', $run, true], [$confirmed->cause, $confirmed->prefix, $confirmed->run, $confirmed->replayed], $pattern);
        $this->assertGreaterThanOrEqual(15, self::matchLoop(PcreInterpreter::pattern($pattern), $confirmed->build(4 * self::QUARTER)), $pattern.' skips most of its witness.');

        $cost = $analysis->everyMatchSearchCost;
        if (!$exact) {
            $this->assertNull($cost, $pattern.' is reported before the engine replay.');

            return;
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame([SearchCostCause::ShortMatch, '', $run, null], [$cost->cause, $cost->prefix, $cost->run, $cost->replayed], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, run: string, exact: bool}>
     */
    public static function provideLoopsWhoseCallsReadTheRun(): iterable
    {
        yield 'optional group' => ['pattern' => '/(?:a+\d)?/', 'run' => 'a', 'exact' => true];
        yield 'optional group ending with a literal' => ['pattern' => '/(?:a+b)?/', 'run' => 'a', 'exact' => true];
        yield 'empty branch last' => ['pattern' => '/(?:a+\d|)/', 'run' => 'a', 'exact' => true];
        yield 'possessive run' => ['pattern' => '/(?:a++\d)?/', 'run' => 'a', 'exact' => true];
        yield 'short match after the run' => ['pattern' => '/a(?:a+\d)?/', 'run' => 'a', 'exact' => true];
        yield 'first branch reads the run' => ['pattern' => '/a*b|a/', 'run' => 'a', 'exact' => true];
        yield 'match of five characters' => ['pattern' => '/a{5}(?:a+\d)?/', 'run' => 'a', 'exact' => true];
        // PHP's retry after this empty match ends on the "a": its own loop
        // is not read, this one is.
        yield 'empty match before a branch of one character' => ['pattern' => '/(?:a+\d)?|a/', 'run' => 'a', 'exact' => true];
        yield 'A modifier' => ['pattern' => '/(?:a+\d)?/A', 'run' => 'a', 'exact' => true];
        yield '\G' => ['pattern' => '/\G(?:a+\d)?/', 'run' => 'a', 'exact' => true];
        yield 'empty match refused where each call starts' => ['pattern' => '/(*NOTEMPTY_ATSTART)a?(?:a+\d)?/', 'run' => 'a', 'exact' => true];
        yield 'empty matches on ASCII under u' => ['pattern' => '/(?:a+\d)?/u', 'run' => 'a', 'exact' => true];
        yield 'empty matches on the last character of one byte under u' => ['pattern' => '/(?:\x7f+\d)?/u', 'run' => "\x7f", 'exact' => true];
        yield 'empty matches on a byte past ASCII without u' => ['pattern' => '/(?:\xe9+\d)?/', 'run' => "\xe9", 'exact' => true];
        yield 'short matches of two bytes under u' => ['pattern' => '/é(?:é+\d)?/u', 'run' => 'é', 'exact' => true];
        yield 'lookahead after the run' => ['pattern' => '/(?:[a-z]+(?=\d))?/', 'run' => 'a', 'exact' => false];
    }

    /**
     * The match comes before the paths that would read the run, reads the
     * run itself, or the attempt reads a bounded part of it: the loop is
     * linear, and nothing is reported in either mode.
     */
    #[Test]
    #[DataProvider('provideLoopsWhoseCallsReadNoRun')]
    public function test_match_loop_search_cost_is_not_reported_when_no_call_reads_the_run(string $pattern): void
    {
        $subject = str_repeat('a', 4 * self::QUARTER).'!';
        $found = preg_match(PcreInterpreter::pattern($pattern), $subject, $matches, \PREG_OFFSET_CAPTURE);
        $pinned = PcreAttemptSteps::pinned($pattern);
        $far = PcreAttemptSteps::steps($pinned, $subject, self::QUARTER);
        $near = PcreAttemptSteps::steps($pinned, $subject, 2 * self::QUARTER);
        $covers = 1 === $found && $matches[0][1] + \strlen($matches[0][0]) >= 2 * self::QUARTER;
        $this->assertTrue($far - $near < self::QUARTER || $covers, \sprintf('Oracle: the attempt of %s grows with the rest of the run (%d then %d steps), and its match stops inside it.', $pattern, $far, $near));

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyzeMatchLoop($pattern)->everyMatchSearchCost, $pattern);
        $this->assertNull($analyzer->analyzeMatchLoop($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->everyMatchSearchCost, $pattern.' in confirmed mode');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLoopsWhoseCallsReadNoRun(): iterable
    {
        yield 'lazy optional group' => ['pattern' => '/(?:a+\d)??/'];
        yield 'empty branch first' => ['pattern' => '/(?:|a+\d)/'];
        yield 'star loop' => ['pattern' => '/a*/'];
        yield 'optional literal' => ['pattern' => '/x?/'];
        yield 'bounded repeat at the unrolling cutoff' => ['pattern' => '/(?:a{1,16}\d)?/'];
        yield 'short branch first' => ['pattern' => '/a|a*b/'];
        yield 'match that reads the run' => ['pattern' => '/a+(?:a+\d)?/'];
        yield 'caret' => ['pattern' => '/^(?:a+\d)?/'];
    }

    /**
     * After the empty match where a run word starts, the loop calls
     * preg_match() one byte on, inside the run word: on a run of "ab",
     * "b(?:ab)*" matches from that "b" to the end of the run, and the loop
     * makes two calls in all, though the attempt at each "a" reads the rest
     * of the run before its empty match. Nothing is reported in either
     * mode, and the replay follows the loop past that empty match.
     */
    #[Test]
    #[DataProvider('provideLoopsThatGoOnInsideARunWord')]
    public function test_match_loop_search_cost_follows_the_call_one_byte_after_an_empty_match(string $pattern): void
    {
        $subject = str_repeat('ab', 2 * self::QUARTER);
        $interpreted = PcreInterpreter::pattern($pattern);
        $this->assertSame(2, self::matchLoop($interpreted, $subject), 'Oracle: the loop of '.$pattern.' does not make two calls.');
        $this->assertSame(1, preg_match($interpreted, $subject, $matches, \PREG_OFFSET_CAPTURE, 2 * self::QUARTER + 1));
        $this->assertSame(\strlen($subject), $matches[0][1] + \strlen($matches[0][0]), 'Oracle: the call after the empty match stops before the end of the run.');
        $pinned = PcreAttemptSteps::pinned($pattern);
        $this->assertSame(1, preg_match($pinned, $subject, $matches, \PREG_OFFSET_CAPTURE, 2 * self::QUARTER));
        $this->assertSame(['', 2 * self::QUARTER], $matches[0], 'Oracle: the attempt where a run word starts does not end on an empty match.');
        // One step at least per run word of the 250 characters between them.
        $this->assertGreaterThanOrEqual(intdiv(self::QUARTER, 2), PcreAttemptSteps::steps($pinned, $subject, self::QUARTER) - PcreAttemptSteps::steps($pinned, $subject, 2 * self::QUARTER), 'Oracle: that attempt does not read the run.');

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyzeMatchLoop($pattern)->everyMatchSearchCost, $pattern);
        $this->assertNull($analyzer->analyzeMatchLoop($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->everyMatchSearchCost, $pattern.' in confirmed mode');
        $this->assertFalse((new SearchCostReplayer())->replaysMatchLoop($pattern, '', 'ab', ''), $pattern.' replayed');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLoopsThatGoOnInsideARunWord(): iterable
    {
        yield 'long branch first' => ['pattern' => '/b(?:ab)*|(?:(?:ab)+\d)?/'];
        yield 'long branch inside the optional group' => ['pattern' => '/(?:(?:ab)+\d|b(?:ab)*)?/'];
    }

    /**
     * The replay follows the calls the loop makes after the pinned attempt
     * across a run word of "ab": the one on the "b" ends on an empty match
     * at once, and the loop goes on to the next "a", whose attempt reads the
     * run again; behind "\G", that call finds no match, and the loop ends.
     */
    #[Test]
    #[DataProvider('provideMatchLoopReplaysOnARunWordOfTwoCharacters')]
    public function test_replay_of_the_match_loop_follows_the_calls_across_a_run_word(string $pattern, int $calls, bool $replays): void
    {
        $subject = str_repeat('ab', 256);
        $this->assertSame($calls, self::matchLoop(PcreInterpreter::pattern($pattern), $subject), 'Oracle: the loop of '.$pattern.' makes another number of calls.');
        $pinned = PcreAttemptSteps::pinned($pattern);
        $this->assertSame(1, preg_match($pinned, $subject, $matches, \PREG_OFFSET_CAPTURE, 128));
        $this->assertSame(['', 128], $matches[0], 'Oracle: the attempt pinned a quarter through the run does not end on an empty match.');
        $this->assertGreaterThanOrEqual(64, PcreAttemptSteps::steps($pinned, $subject, 128) - PcreAttemptSteps::steps($pinned, $subject, 256), 'Oracle: that attempt does not read the run.');

        $this->assertSame($replays, (new SearchCostReplayer())->replaysMatchLoop($pattern, '', 'ab', ''), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, calls: int, replays: bool}>
     */
    public static function provideMatchLoopReplaysOnARunWordOfTwoCharacters(): iterable
    {
        yield 'an empty match on each character' => ['pattern' => '/(?:(?:ab)+\d)?/', 'calls' => 512, 'replays' => true];
        yield 'no match on the "b" behind \G' => ['pattern' => '/\G(?:(?:ab)+\d)?(?<!a)/', 'calls' => 1, 'replays' => false];
    }

    /**
     * The loop makes no retry after an empty match, where PHP's own does:
     * "(?:a+\d)??" settles on the empty string before it tries the run,
     * so each call of the loop takes a few steps, and the retry
     * preg_match_all() makes there reads the rest of the run. The cost of
     * the functions that look for every match is not the cost of this
     * loop, and the reverse: after the empty match of "(?:a+\d)?|a", which
     * the attempt finds once it has read the run, PHP's retry ends on the
     * "a", and that loop is not read.
     */
    #[Test]
    public function test_match_loop_makes_no_retry_after_an_empty_match(): void
    {
        $lazy = '/(?:a+\d)??/';
        $subject = str_repeat('a', 4 * self::QUARTER).'!';
        $attempt = PcreAttemptSteps::pinned($lazy);
        $retry = PcreAttemptSteps::pinned('/(*NOTEMPTY_ATSTART)(?:a+\d)??/');
        $this->assertSame(PcreAttemptSteps::steps($attempt, $subject, self::QUARTER), PcreAttemptSteps::steps($attempt, $subject, 2 * self::QUARTER), 'Oracle: the attempt of '.$lazy.' grows with the run.');
        $this->assertGreaterThanOrEqual(self::QUARTER, PcreAttemptSteps::steps($retry, $subject, self::QUARTER) - PcreAttemptSteps::steps($retry, $subject, 2 * self::QUARTER), 'Oracle: the retry of '.$lazy.' does not grow with the run.');
        $this->assertSame(4 * self::QUARTER + 1, self::matchLoop(PcreInterpreter::pattern($lazy), $subject), 'Oracle: the loop skips a position.');

        $analyzer = new RedosAnalyzer();
        $this->assertSame(SearchCostCause::EmptyMatchRetry, $analyzer->analyze($lazy)->everyMatchSearchCost?->cause, $lazy.' through the functions that look for every match');
        $this->assertNull($analyzer->analyzeMatchLoop($lazy)->everyMatchSearchCost, $lazy.' through the loop');

        $branch = '/(?:a+\d)?|a/';
        $this->assertNull($analyzer->analyze($branch)->everyMatchSearchCost, $branch.' through the functions that look for every match');
        $this->assertSame(SearchCostCause::ShortMatch, $analyzer->analyzeMatchLoop($branch)->everyMatchSearchCost?->cause, $branch.' through the loop');
    }

    /**
     * Where preg_match() itself pays for the run, the cost is the search
     * cost of every call, in the analysis of the loop as in the other.
     */
    #[Test]
    public function test_match_loop_keeps_the_search_cost_of_every_call(): void
    {
        $pattern = '/\s+$/';
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern), str_repeat(' ', 100).'!'), 'Oracle: '.$pattern.' matches.');

        $analysis = (new RedosAnalyzer())->analyzeMatchLoop($pattern);
        $this->assertInstanceOf(RedosSearchCost::class, $analysis->searchCost);
        $this->assertNull($analysis->everyMatchSearchCost);
    }

    /**
     * The loop goes on from where the reported match starts, which "\K"
     * moves: after the empty match "a\K(?:a+\d)?" reports one "a" in, the
     * next call starts two characters after the last. Such a pattern is
     * not read.
     */
    #[Test]
    public function test_match_loop_search_cost_is_not_reported_for_a_pattern_that_moves_the_start_of_its_match(): void
    {
        $pattern = '/a\K(?:a+\d)?/';
        $this->assertSame(2 * self::QUARTER, self::matchLoop(PcreInterpreter::pattern($pattern), str_repeat('a', 4 * self::QUARTER)), 'Oracle: the loop of '.$pattern.' makes a call at each character.');

        $analyzer = new RedosAnalyzer();
        $this->assertInstanceOf(RedosSearchCost::class, $analyzer->analyze($pattern)->everyMatchSearchCost, 'control: the functions that look for every match are read');
        $this->assertNull($analyzer->analyzeMatchLoop($pattern)->everyMatchSearchCost, $pattern);
        $this->assertNull($analyzer->analyzeMatchLoop($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->everyMatchSearchCost, $pattern.' in confirmed mode');
    }

    /**
     * After an empty match the loop goes one byte on, which under u is
     * inside a character written with several: PCRE2 refuses the offset,
     * preg_match() returns false, and the loop ends after its first match.
     * No run of such a character is read; a run of characters of one byte
     * is.
     */
    #[Test]
    #[DataProvider('provideRunsTheLoopStopsInside')]
    public function test_match_loop_search_cost_is_not_reported_when_the_loop_stops_inside_a_character(string $pattern, string $run): void
    {
        $this->assertSame(1, self::matchLoop(PcreInterpreter::pattern($pattern), str_repeat($run, 4 * self::QUARTER)), 'Oracle: the loop of '.$pattern.' goes on.');
        $this->assertSame(\PREG_BAD_UTF8_OFFSET_ERROR, preg_last_error(), 'Oracle: the loop ended on another error.');

        $analyzer = new RedosAnalyzer();
        $this->assertSame($run, $analyzer->analyze($pattern)->everyMatchSearchCost?->run, 'control: the functions that look for every match are read on that run');
        $this->assertNull($analyzer->analyzeMatchLoop($pattern)->everyMatchSearchCost, $pattern);
        $this->assertNull($analyzer->analyzeMatchLoop($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->everyMatchSearchCost, $pattern.' in confirmed mode');
    }

    /**
     * @return iterable<string, array{pattern: string, run: string}>
     */
    public static function provideRunsTheLoopStopsInside(): iterable
    {
        yield 'a letter of two bytes' => ['pattern' => '/(?:é+\d)?/u', 'run' => 'é'];
        yield 'the first character of two bytes' => ['pattern' => '/(?:\x{80}+\d)?/u', 'run' => "\u{80}"];
    }

    /**
     * The analysis of the loop writes its cost under the same key as the
     * other, with the cause of short matches.
     */
    #[Test]
    public function test_match_loop_search_cost_is_serialized_as_the_cost_of_every_match(): void
    {
        $json = (new RedosAnalyzer())->analyzeMatchLoop('/(?:a+\d)?/')->jsonSerialize();

        $this->assertNull($json['search_cost']);
        $this->assertIsArray($json['every_match_search_cost']);
        $this->assertSame(['short_match', 'a'], [$json['every_match_search_cost']['cause'], $json['every_match_search_cost']['witness']['run']]);
    }

    /**
     * The replay pins the attempt a quarter and half-way through the run:
     * it must match within 64 run words, an empty match too, and take one
     * more step at least per run word left; the limits may leave it unable
     * to count.
     *
     * @param array{backtrackLimit?: int} $options
     */
    #[Test]
    #[DataProvider('provideMatchLoopReplays')]
    public function test_replay_of_the_match_loop_says_what_the_engine_shows(string $pattern, array $options, ?bool $replays): void
    {
        $subject = str_repeat('a', 256);
        $pinned = PcreAttemptSteps::pinned($pattern);
        $far = PcreAttemptSteps::steps($pinned, $subject, 64);
        $near = PcreAttemptSteps::steps($pinned, $subject, 128);
        $found = preg_match($pinned, $subject, $matches, \PREG_OFFSET_CAPTURE, 64);
        $short = 1 === $found && $matches[0][1] + \strlen($matches[0][0]) <= 128;
        // The oracle of each outcome, as the engine gives it.
        if (true === $replays) {
            $this->assertTrue($short && $far - $near >= 64, \sprintf('Oracle: the attempt of %s at a quarter of the run matches past 64 characters, or does not read the run (%d then %d steps).', $pattern, $far, $near));
        } elseif (false === $replays) {
            $this->assertTrue(!$short || $far - $near < 64, \sprintf('Oracle: the attempt of %s reads the run before a short match (%d then %d steps).', $pattern, $far, $near));
        } else {
            $this->assertGreaterThan($options['backtrackLimit'] ?? 0, $far, 'Oracle: the attempt of '.$pattern.' fits the limit.');
        }

        $this->assertSame($replays, (new SearchCostReplayer())->replaysMatchLoop($pattern, '', 'a', '', new ConfirmationOptions(...$options)), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, options: array{backtrackLimit?: int}, replays: bool|null}>
     */
    public static function provideMatchLoopReplays(): iterable
    {
        yield 'each attempt reads the rest of the run before an empty match' => ['pattern' => '/(?:a+\d)?/', 'options' => [], 'replays' => true];
        yield 'each attempt reads the rest of the run before a short match' => ['pattern' => '/a(?:a+\d)?/', 'options' => [], 'replays' => true];
        yield 'the match reads 64 characters' => ['pattern' => '/a{64}(?:a+\d)?/', 'options' => [], 'replays' => true];
        yield 'the match reads 65 characters' => ['pattern' => '/a{65}(?:a+\d)?/', 'options' => [], 'replays' => false];
        yield 'the attempt reads no run' => ['pattern' => '/(?:a+\d)??/', 'options' => [], 'replays' => false];
        yield 'the match reads the run' => ['pattern' => '/a+(?:a+\d)?/', 'options' => [], 'replays' => false];
        yield 'the attempt fails' => ['pattern' => '/a+\d/', 'options' => [], 'replays' => false];
        yield 'limits too low for the attempt' => ['pattern' => '/(?:a+\d)?/', 'options' => ['backtrackLimit' => 50], 'replays' => null];
    }

    /**
     * The replay of the loop follows no retry: after the match "\K" leaves
     * empty, PHP's own loop retries where it ends, and that retry reads
     * the rest of the run, which the replay of its short matches refuses;
     * a loop that calls preg_match() itself makes none.
     */
    #[Test]
    public function test_replay_of_the_match_loop_makes_no_retry_after_a_kept_empty_match(): void
    {
        $pattern = '/a\K(?:(?:ba)+\d)?|b(?:ab)+/';
        $subject = str_repeat('ab', 256);
        $pinned = PcreAttemptSteps::pinned($pattern);
        $this->assertSame(1, preg_match($pinned, $subject, $matches, \PREG_OFFSET_CAPTURE, 128), 'Oracle: the attempt a quarter through the run fails.');
        $this->assertSame(['', 129], $matches[0], 'Oracle: the attempt does not stop on an empty match after one character.');
        $this->assertGreaterThanOrEqual(64, PcreAttemptSteps::steps($pinned, $subject, 128) - PcreAttemptSteps::steps($pinned, $subject, 256), 'Oracle: the attempt does not read the run.');
        $retry = PcreAttemptSteps::pinned('/(*NOTEMPTY_ATSTART)a\K(?:(?:ba)+\d)?|b(?:ab)+/');
        $this->assertSame(1, preg_match($retry, $subject, $retried, \PREG_OFFSET_CAPTURE, 129), 'Oracle: the retry fails.');
        $this->assertSame(512, $retried[0][1] + \strlen($retried[0][0]), 'Oracle: the retry stops before the end of the run.');

        $replayer = new SearchCostReplayer();
        $this->assertFalse($replayer->replaysShortMatches($pattern, '', 'ab', ''), 'control: the retry reads the run');
        $this->assertTrue($replayer->replaysMatchLoop($pattern, '', 'ab', ''));
    }

    /**
     * A pattern that leaves no delimiter for the counting verbs cannot be
     * replayed.
     */
    #[Test]
    public function test_replay_of_the_match_loop_needs_a_place_for_its_verbs(): void
    {
        $this->assertFalse((new SearchCostReplayer())->replaysMatchLoop("_(?:a+\\d)?|[\x01#~%!@;,)}>[]_", '', 'a', ''));
    }

    /**
     * The loop of nette/utils' Strings::matchAll() under lazy, as its
     * generator runs it: how many matches it makes.
     */
    private static function matchLoop(string $pattern, string $subject): int
    {
        $offset = 0;
        $counter = 0;
        $m = [];
        while ($offset <= \strlen($subject) - ($counter ? 1 : 0) && @preg_match($pattern, $subject, $m, \PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $m[0][1] + max(1, \strlen($m[0][0]));
            $counter++;
        }

        return $counter;
    }

    /**
     * The attempt pinned 750, 500 and 250 run words before the end matches,
     * and takes at least one more step per run word than the next.
     */
    private function assertAttemptsGrowWithTheRestOfTheRun(string $pattern, string $run): void
    {
        $subject = str_repeat($run, 4 * self::QUARTER).'!';
        // The counter sees nothing inside a possessive repeat of one
        // character: the replay counts it in a group of its own.
        $pinned = PcreAttemptSteps::pinned(str_replace('a++', '(?:a)++', $pattern));
        $steps = [];
        foreach ([3, 2, 1] as $quarters) {
            $offset = (4 - $quarters) * self::QUARTER * \strlen($run);
            $this->assertSame(1, preg_match($pinned, $subject, $matches, 0, $offset), \sprintf('Oracle: the attempt of %s %d run words before the end fails.', $pattern, $quarters * self::QUARTER));
            $steps[] = PcreAttemptSteps::steps($pinned, $subject, $offset);
        }

        $this->assertGreaterThanOrEqual(self::QUARTER, $steps[0] - $steps[1], \sprintf('Oracle: the attempts of %s take %s steps.', $pattern, implode(', ', $steps)));
        $this->assertGreaterThanOrEqual(self::QUARTER, $steps[1] - $steps[2], \sprintf('Oracle: the attempts of %s take %s steps.', $pattern, implode(', ', $steps)));
    }
}

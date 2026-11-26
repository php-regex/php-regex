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
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\LibrarySource;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Tests\Support\ProcessPipes;
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
     * Under limits too low for the replay to finish its count, the witness
     * stays reported, as the model reads it exactly, and "replayed" is
     * null: the replay could not measure, it did not fail to reproduce.
     * The engine settings are the caller's afterwards.
     *
     * Oracle (PHP 8.4, PCRE2 10.49 and PHP 8.1, PCRE2 10.39, JIT off): on
     * "ab" x 64 . "!c" the search matches nowhere under the default limits,
     * and gives up at once under a backtrack or a recursion limit of 1.
     */
    #[Test]
    #[DataProvider('provideRestrictivePolicies')]
    public function test_confirmed_search_replay_respects_the_callers_engine_limits(ConfirmationOptions $options, string $setting, int $error): void
    {
        $subject = str_repeat('ab', 64).'!c';
        $this->assertSame([0, \PREG_NO_ERROR], self::engine('/(*NO_JIT)(?:ab)+c/', $subject));
        $this->assertSame([false, $error], self::engine('/(*NO_JIT)(?:ab)+c/', $subject, $setting));

        $before = [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')];
        $analyzer = new RedosAnalyzer();
        $control = $analyzer->analyze('/(?:ab)+c/', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $cost = $analyzer->analyze('/(?:ab)+c/', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low, $options))->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $control);
        $this->assertTrue($control->replayed);
        $this->assertSame(['prefix' => '', 'run' => 'ab', 'breaker' => '!c'], $control->toArray()['witness']);
        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertNull($cost->replayed);
        $this->assertSame($control->toArray()['witness'], $cost->toArray()['witness']);
        $this->assertSame($before, [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')]);
    }

    /**
     * @return iterable<string, array{options: ConfirmationOptions, setting: string, error: int}>
     */
    public static function provideRestrictivePolicies(): iterable
    {
        yield 'backtracking' => ['options' => new ConfirmationOptions(backtrackLimit: 1), 'setting' => 'pcre.backtrack_limit', 'error' => \PREG_BACKTRACK_LIMIT_ERROR];
        yield 'recursion' => ['options' => new ConfirmationOptions(recursionLimit: 1), 'setting' => 'pcre.recursion_limit', 'error' => \PREG_RECURSION_LIMIT_ERROR];
    }

    /**
     * The step replay counts the attempts inside the run after the prefix,
     * with the breaker holding the last required code unit, and through a
     * possessive any-character repeat the run does not read.
     */
    #[Test]
    #[DataProvider('provideWitnessesTheReplayCounts')]
    public function test_confirmed_mode_replays_the_witness(string $pattern): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;

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
     * A possessive or atomic repeat of one character set reads the run
     * without a backtracking frame, so the step counter sees none of its
     * reads: the attempts of the pattern as written take as many steps
     * wherever they start. Each character read through a group of its own,
     * "(?:a)++", is counted once, and the group changes no match: the replay
     * counts the run that way and confirms the witness. The model reads an
     * atomic body whose branches each hold one run and start apart as those
     * runs, "(?>a+|c)" as "(?:(?>a+)|c)": that witness is reported before
     * the replay too. Under u, PCRE2 reads a class of every character one
     * character at a time: it is counted too.
     *
     * Oracle, pcre.jit=0, PHP 8.4.28, PCRE2 10.49, on the run word repeated
     * 2k/4k/8k times then the breaker: /a++b/ 1.0 / 4.1 / 17.0 ms,
     * /(?>a+)b/ 1.1 / 4.2 / 16.8 ms, /(?>a+|c)b/ 1.1 / 4.2 / 17.7 ms,
     * /a[\s\S]*+b/u 17.0 / 65.6 / 254.9 ms, /(?>.*)[xy]/ 2.7 / 10.9 /
     * 45.1 ms, /^\s++x/m 1.1 / 4.2 / 16.5 ms.
     */
    #[Test]
    #[DataProvider('provideRunsGivingNothingBack')]
    public function test_confirmed_mode_counts_the_reads_of_a_run_that_gives_nothing_back(string $pattern, string $counted, string $run, string $breaker, bool $exact): void
    {
        $subject = str_repeat($run, 1000).$breaker;
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern), $subject), 'Oracle: '.$pattern.' matches the witness.');
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($counted), $subject), 'Oracle: '.$counted.' matches the witness.');
        $steps = [];
        $growth = [];
        foreach ([750, 500, 250] as $words) {
            $offset = (1000 - $words) * \strlen($run);
            $steps[] = PcreAttemptSteps::steps(PcreAttemptSteps::pinned($pattern), $subject, $offset);
            $growth[] = PcreAttemptSteps::steps(PcreAttemptSteps::pinned($counted), $subject, $offset);
        }
        $this->assertSame([$steps[0], $steps[0], $steps[0]], $steps, 'Oracle: the step counter sees the run '.$pattern.' reads.');
        $this->assertGreaterThanOrEqual(250, $growth[0] - $growth[1], 'Oracle: the attempts of '.$counted.' do not grow with the rest of the run.');
        $this->assertSame($growth[0] - $growth[1], $growth[1] - $growth[2], 'Oracle: the attempts of '.$counted.' are not linear in the rest of the run.');

        $analyzer = new RedosAnalyzer();
        $cost = $analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame([2, $run, true], [$cost->degree, $cost->run, $cost->replayed], $pattern);
        $this->assertSame($exact, $analyzer->analyze($pattern)->searchCost instanceof RedosSearchCost, $pattern.' before the replay');
    }

    /**
     * @return iterable<string, array{pattern: string, counted: string, run: string, breaker: string, exact: bool}>
     */
    public static function provideRunsGivingNothingBack(): iterable
    {
        yield 'possessive loop' => ['pattern' => '/a++b/', 'counted' => '/(?:a)++b/', 'run' => 'a', 'breaker' => '!b', 'exact' => true];
        yield 'atomic loop' => ['pattern' => '/(?>a+)b/', 'counted' => '/(?>(?:a)+)b/', 'run' => 'a', 'breaker' => '!b', 'exact' => true];
        yield 'atomic loop before another branch' => ['pattern' => '/(?>a+|c)b/', 'counted' => '/(?>(?:a)+|c)b/', 'run' => 'a', 'breaker' => '!b', 'exact' => true];
        yield 'possessive [\s\S]* under u' => ['pattern' => '/a[\s\S]*+b/u', 'counted' => '/a(?:[\s\S])*+b/u', 'run' => 'a', 'breaker' => 'b', 'exact' => true];
        yield 'atomic dot-star without s' => ['pattern' => '/(?>.*)[xy]/', 'counted' => '/(?>(?:.)*)[xy]/', 'run' => '!', 'breaker' => '', 'exact' => true];
        // Under m each line starts an attempt; without it only the first one
        // reads the run.
        yield 'possessive loop at line starts' => ['pattern' => '/^\s++x/m', 'counted' => '/^(?:\s)++x/m', 'run' => "\n", 'breaker' => '!x', 'exact' => true];
    }

    /**
     * A possessive or atomic repeat of any character, which PCRE2 moves to
     * the end of the subject in one step, is replayed as written: through a
     * group, each character it skips would be counted. Here the atomic group
     * commits to ".*+" at once, so no attempt reads the run of "a": 0.1 /
     * 0.2 / 0.3 ms on "a"x2k/4k/8k."!b" (pcre.jit=0, PHP 8.4.28, PCRE2
     * 10.49), and the attempts take as many steps with each "a" counted.
     * The model keeps the atomic body with every way through it, and the
     * replay confirms no witness: nothing is reported.
     */
    #[Test]
    public function test_confirmed_mode_does_not_count_a_repeat_of_any_character_as_reads(): void
    {
        $pattern = '/(?>.*+|a+)b/s';
        $counted = '/(?>.*+|(?:a)+)b/s';
        $subject = str_repeat('a', 1000).'!b';
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern), $subject), 'Oracle: '.$pattern.' matches the run.');
        $steps = [];
        foreach ([250, 500, 750] as $offset) {
            $steps[] = PcreAttemptSteps::steps(PcreAttemptSteps::pinned($counted), $subject, $offset);
        }
        $this->assertSame([$steps[0], $steps[0], $steps[0]], $steps, 'Oracle: the attempts of '.$counted.' read the rest of the run.');

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyze($pattern)->searchCost, $pattern);
        $this->assertNull($analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost, $pattern.' in confirmed mode');
    }

    /**
     * The model reads these witnesses exactly, so they stay reported when the
     * replay cannot count them: replayed false.
     *
     * The "_" delimiter and a body holding every other delimiter leave no
     * place for the counting verbs (the pattern itself costs 23.8 / 94.2 ms
     * on "a"x10k/20k, pcre.jit=0), nor a "?" delimiter for the group that
     * counts a possessive run.
     */
    #[Test]
    #[DataProvider('provideWitnessesTheReplayCannotCount')]
    public function test_search_cost_stays_reported_when_the_replay_cannot_count_it(string $pattern): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame(2, $cost->degree, $pattern);
        $this->assertFalse($cost->replayed, $pattern);
        $this->assertArrayNotHasKey('jit_linear', $cost->toArray(), $pattern);
        if ('_' !== $pattern[0]) {
            $subject = str_repeat('a', 1000).'!b';
            $pinned = PcreAttemptSteps::pinned($pattern);
            $this->assertSame(PcreAttemptSteps::steps($pinned, $subject, 250), PcreAttemptSteps::steps($pinned, $subject, 750), 'Oracle: the step counter sees the run '.$pattern.' reads.');
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideWitnessesTheReplayCannotCount(): iterable
    {
        yield 'no place for the counting verbs' => ['pattern' => "_a+b|[\x01#~%!@;,)}>[]_"];
        // A group cannot go where its "?" is the delimiter, and the body
        // leaves no other delimiter to move to: the possessive run is
        // replayed as written, and the step counter sees none of its reads.
        yield 'possessive loop, "?" as the delimiter, the body holding every other one' => ['pattern' => "?a++b|[\x01#~%!@;,]?"];
    }

    /**
     * A group cannot go where its "?", ":" or ")" is the delimiter: the
     * replay moves the pattern to a delimiter its body does not hold, as the
     * engine does for its own verb, and counts the possessive run through
     * the group there. The pattern matches what it matches between slashes.
     *
     * Oracle, pcre.jit=0, PHP 8.4.28, PCRE2 10.49 (and 10.34, 10.39, 10.40):
     * "?a++b?", ":a++b:" and ")a++b)" match "aab" and not "a"x10."!b".
     */
    #[Test]
    #[DataProvider('provideDelimitersAGroupHolds')]
    public function test_confirmed_mode_moves_the_delimiter_a_group_would_end(string $pattern): void
    {
        $subject = str_repeat('a', 1000).'!b';
        $slashes = '/'.substr($pattern, 1, -1).'/';
        foreach (['aab', 'b', $subject] as $tried) {
            $this->assertSame(preg_match($slashes, $tried), preg_match($pattern, $tried), \sprintf('Oracle: %s and %s disagree on %s.', $pattern, $slashes, substr($tried, 0, 8)));
        }
        $pinned = PcreAttemptSteps::pinned('/(?:a)++b/');
        $this->assertGreaterThanOrEqual(250, PcreAttemptSteps::steps($pinned, $subject, 250) - PcreAttemptSteps::steps($pinned, $subject, 500), 'Oracle: the attempts of /(?:a)++b/ do not grow with the rest of the run.');

        $cost = (new RedosAnalyzer())->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame([2, 'a', true], [$cost->degree, $cost->run, $cost->replayed], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideDelimitersAGroupHolds(): iterable
    {
        yield 'possessive loop, "?" as the delimiter' => ['pattern' => '?a++b?'];
        yield 'possessive loop, ":" as the delimiter' => ['pattern' => ':a++b:'];
        yield 'possessive loop, ")" as the delimiter' => ['pattern' => ')a++b)'];
        // The body holds the first delimiter tried.
        yield 'possessive loop holding a control character, "?" as the delimiter' => ['pattern' => "?a++b|\x01?"];
    }

    /**
     * A repeat after "\E" repeats the last character "\Q" quoted, which a
     * group put where it stands would quote too: the replay ends the quote
     * before the group and quotes the character inside it, "\E(?:\Qa\E)",
     * the "\E" after it then ending no quote. The group changes no match,
     * and the step counter sees each character the possessive repeat reads
     * through it, as it sees those of the greedy one.
     *
     * Oracle, pcre.jit=0, PHP 8.4.28, PCRE2 10.49 (and 10.34, 10.39, 10.40):
     * "/\Q\E(?:\Qa\E)\E++b/" matches "aab" and not "..b", as "/\Qa\E++b/"
     * does, and "/\Q\E(?:\Q.\E)\E++b/" "..b" and not "aab".
     */
    #[Test]
    #[DataProvider('provideQuotedRepeats')]
    public function test_replay_reads_a_quoted_repeat_through_a_group_of_its_own(string $pattern, string $counted, string $run): void
    {
        $subject = str_repeat($run, 1000).'!b';
        foreach ([$run.$run.'b', 'xb', $subject] as $tried) {
            $this->assertSame(preg_match(PcreInterpreter::pattern($pattern), $tried), preg_match(PcreInterpreter::pattern($counted), $tried), \sprintf('Oracle: %s and %s disagree on %s.', $pattern, $counted, substr($tried, 0, 8)));
        }
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern), $subject), 'Oracle: '.$pattern.' matches the witness.');
        $pinned = PcreAttemptSteps::pinned($counted);
        $this->assertGreaterThanOrEqual(250, PcreAttemptSteps::steps($pinned, $subject, 250) - PcreAttemptSteps::steps($pinned, $subject, 500), 'Oracle: the attempts of '.$counted.' do not grow with the rest of the run.');

        $cost = (new RedosAnalyzer())->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame([$run, true], [$cost->run, $cost->replayed], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, counted: string, run: string}>
     */
    public static function provideQuotedRepeats(): iterable
    {
        yield 'greedy' => ['pattern' => '/\Qa\E+b/', 'counted' => '/\Q\E(?:\Qa\E)\E+b/', 'run' => 'a'];
        yield 'possessive' => ['pattern' => '/\Qa\E++b/', 'counted' => '/\Q\E(?:\Qa\E)\E++b/', 'run' => 'a'];
        yield 'possessive, a quote left empty before the repeat' => ['pattern' => '/\Qa\E\Q\E++b/', 'counted' => '/\Q\E(?:\Qa\E)\E\Q\E++b/', 'run' => 'a'];
        yield 'possessive of a sign the quote keeps' => ['pattern' => '/\Q.\E++b/', 'counted' => '/\Q\E(?:\Q.\E)\E++b/', 'run' => '.'];
        // A "\E" that ends no quote: the character before it is written
        // as it stands, in a plain group.
        yield 'possessive of a class before an \E that ends no quote' => ['pattern' => '/[a]\E++b/', 'counted' => '/(?:[a])\E++b/', 'run' => 'a'];
        yield 'possessive of an escape before an \E that ends no quote' => ['pattern' => '/\x61\E++b/', 'counted' => '/(?:\x61)\E++b/', 'run' => 'a'];
        // The quote stands elsewhere: the repeated character takes a plain group.
        yield 'possessive before a quoted branch' => ['pattern' => '/a++b|\Qx\E/', 'counted' => '/(?:a)++b|\Qx\E/', 'run' => 'a'];
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
     * The search tries the attempts the breaker starts after every attempt
     * of the run: a match there, or an empty one at the subject's end,
     * leaves each of them made, reading to the end of the run. Without "\K"
     * a match starts where its attempt did: "/(?:a+\d)?$/" matches "" at
     * 256 on "a"x256, and "/(?:a+\d)?!/" matches "!" at 256 on "a"x256."!",
     * where the attempts pinned in the run fail. With "\K" an earlier
     * attempt may report its match there: "/a+b|a+\K$/" matches "" at 256
     * and "/a+b|a+\K!/" "!" at 256, each from its first attempt, which reads
     * the run twice, and the search is linear.
     */
    #[Test]
    public function test_replay_accepts_a_match_after_the_run_alone(): void
    {
        $subject = str_repeat('a', 256);
        $this->assertSame(1, preg_match('/(?:a+\d)?$/', $subject, $matches, \PREG_OFFSET_CAPTURE), 'Oracle: /(?:a+\d)?$/ finds no match.');
        $this->assertSame(['', 256], $matches[0], 'Oracle: /(?:a+\d)?$/ matches before the end.');
        $this->assertSame(0, preg_match('/(?:a+\d)?$/A', $subject, $matches, 0, 64), 'Oracle: the attempt of /(?:a+\d)?$/ inside the run matches.');
        $this->assertSame(1, preg_match('/(?:a+\d)?!/', $subject.'!', $matches, \PREG_OFFSET_CAPTURE), 'Oracle: /(?:a+\d)?!/ finds no match.');
        $this->assertSame(['!', 256], $matches[0], 'Oracle: /(?:a+\d)?!/ matches before the breaker.');
        $this->assertSame(0, preg_match('/(?:a+\d)?!/A', $subject.'!', $matches, 0, 64), 'Oracle: the attempt of /(?:a+\d)?!/ inside the run matches.');
        $this->assertSame(1, preg_match('/a+b|a+\K$/', $subject, $matches, \PREG_OFFSET_CAPTURE), 'Oracle: /a+b|a+\K$/ finds no match.');
        $this->assertSame(['', 256], $matches[0], 'Oracle: /a+b|a+\K$/ reports its match before the end.');
        $this->assertSame(1, preg_match('/a+b|a+\K$/A', $subject), 'Oracle: the attempt of /a+b|a+\K$/ at the subject start fails.');
        $this->assertSame(1, preg_match('/a+b|a+\K!/', $subject.'!', $matches, \PREG_OFFSET_CAPTURE), 'Oracle: /a+b|a+\K!/ finds no match.');
        $this->assertSame(['!', 256], $matches[0], 'Oracle: /a+b|a+\K!/ reports its match before the breaker.');
        $this->assertSame(1, preg_match('/a+b|a+\K!/A', $subject.'!'), 'Oracle: the attempt of /a+b|a+\K!/ at the subject start fails.');

        $replayer = new SearchCostReplayer();
        $this->assertTrue($replayer->replays('/(?:a+\d)?$/', '', 'a', ''));
        $this->assertTrue($replayer->replays('/(?:a+\d)?!/', '', 'a', '!'));
        $this->assertFalse($replayer->replays('/a+b|a+\K$/', '', 'a', ''));
        $this->assertFalse($replayer->replays('/a+b|a+\K!/', '', 'a', '!'));
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
        $this->assertNull($analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost);
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
        $replayed = $analyzer->analyze('/[é]+$/u', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;

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
            $cost = (new RedosAnalyzer())->analyze('/\s+$/', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
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
            $cost = (new RedosAnalyzer())->analyze('/(a|b)+c/', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        } finally {
            ini_set('pcre.jit', false === $previous ? '1' : $previous);
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertTrue($cost->replayed);
        $this->assertFalse((new \ReflectionClass(RedosSearchCost::class))->hasProperty('jitLinear'));
        $this->assertSame([], LibrarySource::functionCalls(LibrarySource::files()['src/Redos/Backtrack/SearchCostReplayer.php'], '/^preg_/'));
    }

    /**
     * The limits stop the replay only through the search on the witness:
     * PCRE2 counts the backtrack limit per attempt, and each attempt the
     * replay pins is one the search finished. At the least limit the search
     * needs the replay confirms; one below, the search gives up and the
     * replay is not made.
     *
     * Oracle (PHP 8.4, PCRE2 10.49 and PHP 8.1, PCRE2 10.39): the counted
     * search on "ab" x 256 . "!c" finishes at a backtrack limit of 258 and
     * gives up at 257.
     */
    #[Test]
    public function test_the_least_limit_the_search_needs_lets_every_pinned_attempt_finish(): void
    {
        $counted = '/(*NO_JIT)(*NO_AUTO_POSSESS)(?:ab)+c/';
        $subject = str_repeat('ab', 256).'!c';
        $this->assertSame([0, \PREG_NO_ERROR], self::engine($counted, $subject, 'pcre.backtrack_limit', '258'));
        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], self::engine($counted, $subject, 'pcre.backtrack_limit', '257'));

        $analyzer = new RedosAnalyzer();
        $enough = $analyzer->analyze('/(?:ab)+c/', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low, new ConfirmationOptions(backtrackLimit: 258)))->searchCost;
        $short = $analyzer->analyze('/(?:ab)+c/', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low, new ConfirmationOptions(backtrackLimit: 257)))->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $enough);
        $this->assertTrue($enough->replayed);
        $this->assertInstanceOf(RedosSearchCost::class, $short);
        $this->assertNull($short->replayed);
    }

    /**
     * Oracle (PHP 8.4, PCRE2 10.49 and PHP 8.1, PCRE2 10.39): the counted
     * search on "ab" x 256 . "!c" finishes at a recursion limit of 258 and
     * gives up at 257, and the replay follows it as for the backtrack limit.
     */
    #[Test]
    public function test_the_least_recursion_limit_the_search_needs_lets_the_replay_confirm(): void
    {
        $counted = '/(*NO_JIT)(*NO_AUTO_POSSESS)(?:ab)+c/';
        $subject = str_repeat('ab', 256).'!c';
        $this->assertSame([0, \PREG_NO_ERROR], self::engine($counted, $subject, 'pcre.recursion_limit', '258'));
        $this->assertSame([false, \PREG_RECURSION_LIMIT_ERROR], self::engine($counted, $subject, 'pcre.recursion_limit', '257'));

        $analyzer = new RedosAnalyzer();
        $enough = $analyzer->analyze('/(?:ab)+c/', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low, new ConfirmationOptions(recursionLimit: 258)))->searchCost;
        $short = $analyzer->analyze('/(?:ab)+c/', new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low, new ConfirmationOptions(recursionLimit: 257)))->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $enough);
        $this->assertTrue($enough->replayed);
        $this->assertInstanceOf(RedosSearchCost::class, $short);
        $this->assertNull($short->replayed);
    }

    /**
     * Where ini_set() is disabled the engine runs nothing under the limits
     * the replay asks for: the witness stays, as the model reads it exactly,
     * and "replayed" is null, not a replay that failed to reproduce.
     */
    #[Test]
    public function test_the_replay_is_not_made_where_ini_set_is_disabled(): void
    {
        $script = 'require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).';'
            .'$cost = (new \PHPRegex\Redos\RedosAnalyzer())->analyze("/(?:ab)+c/", new \PHPRegex\Redos\RedosOptions(\PHPRegex\Redos\RedosMode::Confirmed, \PHPRegex\Redos\RedosSeverity::Low))->searchCost;'
            .'echo json_encode(["ini_set" => function_exists("ini_set"), "cost" => $cost?->toArray()]);';
        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-d', 'disable_functions=ini_set', '-r', $script],
            ProcessPipes::descriptors(),
            $pipes,
        );
        $this->assertIsResource($process);
        [$output, $errors] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors.$output);

        $this->assertSame(
            ['ini_set' => false, 'cost' => ['degree' => 2, 'cause' => 'unanchored_search', 'witness' => ['prefix' => '', 'run' => 'ab', 'breaker' => '!c'], 'replayed' => null]],
            json_decode($output, true),
        );
    }

    /**
     * What preg_match() answers, with the setting at the value when one is
     * named, and its last error; the setting is put back.
     *
     * @return array{int|false, int}
     */
    private static function engine(string $pattern, string $subject, ?string $setting = null, string $value = '1'): array
    {
        $previous = null === $setting ? false : ini_set($setting, $value);

        try {
            return [@preg_match($pattern, $subject), preg_last_error()];
        } finally {
            if (null !== $setting && false !== $previous) {
                ini_set($setting, $previous);
            }
        }
    }
}

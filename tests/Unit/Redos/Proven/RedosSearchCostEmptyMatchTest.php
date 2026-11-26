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
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search cost under the options that refuse an empty match.
 *
 * (*NOTEMPTY) refuses an empty match at every position: a pattern that
 * matches the empty string, such as "(?:a+\d)?", then tries its non-empty
 * match at each position of a run, where the bare pattern stops on the
 * empty match of its first attempt. The search never ends on an empty
 * match, so PHP never retries one: preg_match_all(), preg_replace() and
 * preg_split() run the same failing search. (*NOTEMPTY_ATSTART) refuses
 * one only at the search start: the attempt after it matches empty, unless
 * the empty match holds at the subject's start alone, as in "(?:^|a+b)". A
 * pattern that cannot match empty runs the same way under both.
 *
 * A match is empty where it ends where it starts, and "\K" sets its start
 * where it stands (pcre2api, PCRE2_NOTEMPTY): under (*NOTEMPTY), a match
 * that reads "a+" and then "\K" is refused too, so "a+\K" gives back every
 * "a+" at every position. Under (*NOTEMPTY_ATSTART), the start "\K" sets
 * past the search start is not the search start: the attempt there
 * matches.
 *
 * The JIT ignores both options: every engine fact is read in the
 * interpreter, "(*NO_JIT)". PHP 8.4.28, PCRE2 10.49, on the run repeated
 * 1 000 times then "!": the attempt pinned 1 000 / 750 / 500 / 250 run
 * words before the end takes 1 002 / 752 / 502 / 252 steps for
 * "(*NOTEMPTY)(?:a+\d)?" (auto-possession off, PcreAttemptSteps), and the
 * search takes 4.2 then 16.4 ms on 4 000 then 8 000 run words, where the
 * bare pattern matches "" at 0. "(*NOTEMPTY_ATSTART)(?:a+\d)?" matches ""
 * at 1 in no time. "(*NOTEMPTY)a+\K" takes 29, 115 then 458 ms on 2 000,
 * 4 000 then 8 000 "a", where "a+\K" matches "" at the run's end at once.
 * A run that gives nothing back reads the rest of the run all the same:
 * "(*NOTEMPTY)a++\K" takes 1.0, 4.0 then 15.7 ms there, "(*NOTEMPTY)(?:a++\K)"
 * 1.1, 4.0 then 15.9, "(*NOTEMPTY)(?>a+\K)" 1.1, 4.2 then 16.1,
 * "(*NOTEMPTY)(?>a+)\K" 1.1, 4.1 then 16.0, and the atomic groups nested,
 * "(*NOTEMPTY)(?>(?>a+)\K)" and "(*NOTEMPTY)(?>(?>a+\K))", 1.1, 4.1 then
 * 15.9; "(*NOTEMPTY)(?>a+?\K)", whose attempts read one "a" each, 0.1, 0.2
 * then 0.4 ms.
 */
final class RedosSearchCostEmptyMatchTest extends TestCase
{
    use PcreGates;

    private const QUARTER = 250;

    /**
     * Every attempt inside the run reads what is left of it, then fails,
     * where the bare pattern finds an empty match: the search is
     * quadratic, and the witness is reported under the confidence rules of
     * any other, from the theoretical mode when the model reads it exactly,
     * once the engine replay confirms it through a lookaround.
     */
    #[Test]
    #[DataProvider('provideSearchesRefusingTheEmptyMatch')]
    public function test_search_cost_is_reported_when_the_attempts_refuse_an_empty_match(string $pattern, string $prefix, string $run, string $breaker, bool $exact): void
    {
        // PHP 8.5 refuses "\K" in a lookaround.
        $this->requiresCompile($pattern);
        $interpreted = PcreInterpreter::pattern($pattern);
        foreach ([1, 2, 3, 100] as $words) {
            $subject = $prefix.str_repeat($run, $words).$breaker;
            $this->assertSame(0, preg_match($interpreted, $subject), \sprintf('Oracle: %s matches %d run words.', $pattern, $words));
            $this->assertSame(0, preg_match_all($interpreted, $subject), \sprintf('Oracle: preg_match_all() matches %d run words with %s.', $words, $pattern));
            $this->assertSame($subject, preg_replace($interpreted, '-', $subject), \sprintf('Oracle: preg_replace() replaces in %d run words with %s.', $words, $pattern));
            $this->assertSame([$subject], preg_split($interpreted, $subject), \sprintf('Oracle: preg_split() splits %d run words with %s.', $words, $pattern));
        }

        $bare = self::bare($pattern);
        $this->assertSame(1, preg_match(PcreInterpreter::pattern($bare), $prefix.str_repeat($run, 100).$breaker, $matches), 'Oracle: '.$bare.' finds no match.');
        $this->assertSame('', $matches[0], 'Oracle: '.$bare.' finds a non-empty match.');
        $this->assertAttemptsGrowWithTheRestOfTheRun($pattern, $prefix, $run, $breaker);

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);

        $confirmed = $analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $confirmed, $pattern.' in confirmed mode');
        $this->assertTrue($confirmed->replayed, $pattern);

        $cost = $analysis->searchCost;
        if (!$exact) {
            $this->assertNull($cost, $pattern.' is reported before the engine replay.');

            return;
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame(2, $cost->degree, $pattern);
        $this->assertSame([$prefix, $run], [$cost->prefix, $cost->run], $pattern);
        $this->assertNull($cost->replayed, $pattern);
        foreach ([1, 2, 3, 100] as $words) {
            $this->assertSame(0, preg_match($interpreted, $cost->build($words)), \sprintf('%s matches the witness on %d run words.', $pattern, $words));
        }
        $this->assertAttemptsGrowWithTheRestOfTheRun($pattern, $cost->prefix, $cost->run, $cost->breaker);
    }

    /**
     * @return iterable<string, array{pattern: string, prefix: string, run: string, breaker: string, exact: bool}>
     */
    public static function provideSearchesRefusingTheEmptyMatch(): iterable
    {
        yield 'optional group' => ['pattern' => '/(*NOTEMPTY)(?:a+\d)?/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield 'optional group of a class' => ['pattern' => '/(*NOTEMPTY)(?:\s+x)?/', 'prefix' => '', 'run' => ' ', 'breaker' => '!', 'exact' => true];
        yield 'lazy optional group' => ['pattern' => '/(*NOTEMPTY)(?:a+\d)??/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield 'empty branch tried first' => ['pattern' => '/(*NOTEMPTY)(?:|a+\d)/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield 'optional group under u' => ['pattern' => '/(*NOTEMPTY)(?:é+\d)?/u', 'prefix' => '', 'run' => 'é', 'breaker' => '!', 'exact' => true];
        yield 'after another option' => ['pattern' => '/(*NO_JIT)(*NOTEMPTY)(?:a+\d)?/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        // The bare pattern matches "" at the end of the subject.
        yield 'star loop before the dollar' => ['pattern' => '/(*NOTEMPTY)\s*$/', 'prefix' => '', 'run' => ' ', 'breaker' => '!', 'exact' => true];
        yield 'optional group before the dollar' => ['pattern' => '/(*NOTEMPTY)(?:a+\d)?$/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        // The empty match holds at the subject's start alone, where the
        // search starts.
        yield 'caret or a run, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)(?:^|a+b)/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        // The first attempt matches the bare run: the witness opens with a
        // character it fails on.
        yield 'optional trim regex' => ['pattern' => '/(*NOTEMPTY)(?:^\s+|\s+$)?/', 'prefix' => '!', 'run' => ' ', 'breaker' => '!', 'exact' => true];
        yield 'lookahead in an optional group' => ['pattern' => '/(*NOTEMPTY)(?:[a-z]+(?=\d))?/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => false];
        // "\K" with nothing read after it leaves the match empty: the bare
        // pattern matches "" at the run's end.
        yield '\K at the end' => ['pattern' => '/(*NOTEMPTY)a+\K/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield '\K before an optional item' => ['pattern' => '/(*NOTEMPTY)a+\Kb?/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield '\K before a word boundary' => ['pattern' => '/(*NOTEMPTY)\w+\K\b/', 'prefix' => '', 'run' => '0', 'breaker' => '!', 'exact' => true];
        yield '\K in a capturing group' => ['pattern' => '/(*NOTEMPTY)(a+\K)/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield '\K in an optional group' => ['pattern' => '/(*NOTEMPTY)(?:a+\K)?/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield '\K in a loop' => ['pattern' => '/(*NOTEMPTY)(?:a\K)+/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield '\K in the first branch' => ['pattern' => '/(*NOTEMPTY)a+\K|b/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => true];
        yield '\K under u' => ['pattern' => '/(*NOTEMPTY)é+\K/u', 'prefix' => '', 'run' => 'é', 'breaker' => '!', 'exact' => true];
        // "\K" in a lookahead sets the start where the lookahead stands.
        yield '\K in a lookahead' => ['pattern' => '/(*NOTEMPTY)a+(?=\K)/', 'prefix' => '', 'run' => 'a', 'breaker' => '!', 'exact' => false];
    }

    /**
     * A possessive or atomic run of one character set reads what is left of
     * the run in one go and gives nothing back: the attempt passes "\K" at
     * the run's end, the match it reports there is empty and refused, and
     * the attempt fails. The search is quadratic all the same, where the
     * bare pattern matches "" at the run's end at once. The engine's step
     * counter sees none of those reads, which leave no backtracking frame:
     * the attempts take as many steps wherever they start. Read through a
     * group of its own, "(?:a)++", each character is counted once, and the
     * group changes no match: the replay counts the run that way, and
     * confirms the witness. The model reads an atomic body whose branches
     * each hold one run, "\K" around it, and start apart as those runs, each
     * an atomic group of its own, "(?>a+\K)" as "(?>a+)\K"; it keeps any
     * other body with every way through it, as one whose branches start
     * alike: that witness is reported once the replay confirms it.
     */
    #[Test]
    #[DataProvider('provideRunsGivingNothingBackBeforeKeep')]
    public function test_search_cost_is_replayed_through_a_run_that_gives_nothing_back_before_keep(string $pattern, string $counted, string $run, bool $exact): void
    {
        $subject = str_repeat($run, 4 * self::QUARTER).'!';
        foreach ([1, 2, 3, 100] as $words) {
            $witness = str_repeat($run, $words).'!';
            $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern), $witness), \sprintf('Oracle: %s matches %d run words.', $pattern, $words));
            $this->assertSame(0, preg_match(PcreInterpreter::pattern($counted), $witness), \sprintf('Oracle: %s matches %d run words.', $counted, $words));
        }

        $bare = self::bare($pattern);
        $this->assertSame(1, preg_match(PcreInterpreter::pattern($bare), $subject, $matches), 'Oracle: '.$bare.' finds no match.');
        $this->assertSame('', $matches[0], 'Oracle: '.$bare.' finds a non-empty match.');
        $pinned = PcreAttemptSteps::pinned($pattern);
        $steps = [];
        foreach ([1, 2, 3] as $quarters) {
            $steps[] = PcreAttemptSteps::steps($pinned, $subject, (4 - $quarters) * self::QUARTER * \strlen($run));
        }
        $this->assertSame([$steps[0], $steps[0], $steps[0]], $steps, 'Oracle: the step counter sees the run '.$pattern.' reads.');
        $this->assertAttemptsGrowWithTheRestOfTheRun($counted, '', $run, '!');

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern);

        $confirmed = $analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $confirmed, $pattern.' in confirmed mode');
        $this->assertSame([2, $run, true], [$confirmed->degree, $confirmed->run, $confirmed->replayed], $pattern);

        $cost = $analysis->searchCost;
        if (!$exact) {
            $this->assertNull($cost, $pattern.' is reported before the engine replay.');

            return;
        }

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame([2, $run, null], [$cost->degree, $cost->run, $cost->replayed], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, counted: string, run: string, exact: bool}>
     */
    public static function provideRunsGivingNothingBackBeforeKeep(): iterable
    {
        yield '\K after a possessive run' => ['pattern' => '/(*NOTEMPTY)a++\K/', 'counted' => '/(*NOTEMPTY)(?:a)++\K/', 'run' => 'a', 'exact' => true];
        yield '\K after a possessive run, in a group' => ['pattern' => '/(*NOTEMPTY)(?:a++\K)/', 'counted' => '/(*NOTEMPTY)(?:(?:a)++\K)/', 'run' => 'a', 'exact' => true];
        yield '\K after a possessive run of a class' => ['pattern' => '/(*NOTEMPTY)[a-z]++\K/', 'counted' => '/(*NOTEMPTY)(?:[a-z])++\K/', 'run' => 'a', 'exact' => true];
        yield '\K after a possessive counted run' => ['pattern' => '/(*NOTEMPTY)a{2,}+\K/', 'counted' => '/(*NOTEMPTY)(?:a){2,}+\K/', 'run' => 'a', 'exact' => true];
        yield '\K after a possessive run under u' => ['pattern' => '/(*NOTEMPTY)é++\K/u', 'counted' => '/(*NOTEMPTY)(?:é)++\K/u', 'run' => 'é', 'exact' => true];
        yield '\K after an atomic run' => ['pattern' => '/(*NOTEMPTY)(?>a+)\K/', 'counted' => '/(*NOTEMPTY)(?>(?:a)+)\K/', 'run' => 'a', 'exact' => true];
        yield '\K inside an atomic group' => ['pattern' => '/(*NOTEMPTY)(?>a+\K)/', 'counted' => '/(*NOTEMPTY)(?>(?:a)+\K)/', 'run' => 'a', 'exact' => true];
        yield '\K after an atomic run, inside an atomic group' => ['pattern' => '/(*NOTEMPTY)(?>(?>a+)\K)/', 'counted' => '/(*NOTEMPTY)(?>(?>(?:a)+)\K)/', 'run' => 'a', 'exact' => true];
        yield '\K inside nested atomic groups' => ['pattern' => '/(*NOTEMPTY)(?>(?>a+\K))/', 'counted' => '/(*NOTEMPTY)(?>(?>(?:a)+\K))/', 'run' => 'a', 'exact' => true];
        yield '\K inside an atomic group, before another branch' => ['pattern' => '/(*NOTEMPTY)(?>a+\K|b)/', 'counted' => '/(*NOTEMPTY)(?>(?:a)+\K|b)/', 'run' => 'a', 'exact' => true];
        yield '\K inside an atomic group, before a branch starting alike' => ['pattern' => '/(*NOTEMPTY)(?>a+\K|ab)/', 'counted' => '/(*NOTEMPTY)(?>(?:a)+\K|ab)/', 'run' => 'a', 'exact' => false];
    }

    /**
     * A lazy run inside an atomic group commits to its first character:
     * each attempt reads one "a", passes "\K", and the match it reports
     * there is refused with nothing to give back. The search is linear, and
     * though the model keeps the atomic body with every way through it, the
     * replay, which counts each character read, does not confirm a witness:
     * nothing is reported.
     */
    #[Test]
    public function test_search_cost_is_not_reported_when_each_attempt_commits_to_one_character_before_keep(): void
    {
        $pattern = '/(*NOTEMPTY)(?>a+?\K)/';
        $counted = '/(*NOTEMPTY)(?>(?:a)+?\K)/';
        $subject = str_repeat('a', 4 * self::QUARTER).'!';
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern), $subject), 'Oracle: '.$pattern.' matches the run.');
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($counted), $subject), 'Oracle: '.$counted.' matches the run.');
        $pinned = PcreAttemptSteps::pinned($counted);
        $steps = [];
        foreach ([1, 2, 3] as $quarters) {
            $steps[] = PcreAttemptSteps::steps($pinned, $subject, (4 - $quarters) * self::QUARTER);
        }
        $this->assertSame([$steps[0], $steps[0], $steps[0]], $steps, 'Oracle: the attempts of '.$counted.' read the rest of the run.');

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyze($pattern)->searchCost, $pattern);
        $this->assertNull($analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost, $pattern.' in confirmed mode');
    }

    /**
     * A greedy repeat of any character under s moves to the end of the
     * subject in one step, and the atomic group around it gives nothing
     * back: each attempt passes "\K" at the subject's end at once and
     * fails, whatever is left of the run. The search is linear, 0.12, 0.21
     * then 0.42 ms on 2 000, 4 000 then 8 000 "a" and "!" for
     * "(*NOTEMPTY)(?>.*\K)" under s (pcre.jit=0, PHP 8.4.28, PCRE2 10.49),
     * where the dot without s reads the run one character at a time: 50 ms
     * on 8 000 "a". The atomic group read as the run it holds, no run is
     * read through that repeat: nothing is reported.
     */
    #[Test]
    #[DataProvider('provideRepeatsOfAnyCharacterBeforeKeep')]
    public function test_search_cost_is_not_reported_through_a_repeat_of_any_character_in_an_atomic_group(string $pattern, string $possessive): void
    {
        $subject = str_repeat('a', 4 * self::QUARTER).'!';
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern), $subject), 'Oracle: '.$pattern.' matches the run.');
        $steps = [];
        foreach ([0, 1, 2, 3] as $quarters) {
            $offset = $quarters * self::QUARTER;
            $steps[] = PcreAttemptSteps::steps(PcreAttemptSteps::pinned($pattern), $subject, $offset);
            $this->assertSame(
                preg_match(PcreInterpreter::pattern($possessive).'A', $subject, $expected, 0, $offset),
                preg_match(PcreInterpreter::pattern($pattern).'A', $subject, $actual, 0, $offset),
                \sprintf('Oracle: %s and %s disagree at offset %d.', $pattern, $possessive, $offset),
            );
            $this->assertSame($expected, $actual, \sprintf('Oracle: %s and %s disagree at offset %d.', $pattern, $possessive, $offset));
        }
        $this->assertSame([$steps[0], $steps[0], $steps[0], $steps[0]], $steps, 'Oracle: the attempts of '.$pattern.' take more steps where more of the run is left.');
        $this->assertLessThanOrEqual(8, $steps[0], 'Oracle: the attempts of '.$pattern.' backtrack.');

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyze($pattern)->searchCost, $pattern);
        $this->assertNull($analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost, $pattern.' in confirmed mode');
        $this->assertNull($analyzer->analyze($possessive)->searchCost, $possessive);
    }

    /**
     * Each row with the possessive repeat it amounts to.
     *
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideRepeatsOfAnyCharacterBeforeKeep(): iterable
    {
        yield '\K after a dot-star' => ['pattern' => '/(*NOTEMPTY)(?>.*\K)/s', 'possessive' => '/(*NOTEMPTY).*+\K/s'];
        yield '\K after an atomic dot-star' => ['pattern' => '/(*NOTEMPTY)(?>(?>.*)\K)/s', 'possessive' => '/(*NOTEMPTY).*+\K/s'];
        yield '\K after a captured dot-star' => ['pattern' => '/(*NOTEMPTY)(?>(.*)\K)/s', 'possessive' => '/(*NOTEMPTY)(.*+)\K/s'];
        yield '\K after a dot-star, after a literal' => ['pattern' => '/a(?>.*\K)[yz]/s', 'possessive' => '/a.*+\K[yz]/s'];
    }

    /**
     * An attempt at or right after the search start matches, an empty
     * match past the search start included under (*NOTEMPTY_ATSTART): the
     * search makes two attempts at most, and nothing is reported.
     */
    #[Test]
    #[DataProvider('provideSearchesMatchingAtOnce')]
    public function test_search_cost_is_not_reported_when_the_search_matches_at_once(string $pattern, string $run, string $breaker): void
    {
        foreach ([1, 2, 3, 100] as $words) {
            $this->assertSame(1, preg_match(PcreInterpreter::pattern($pattern), str_repeat($run, $words).$breaker, $matches, \PREG_OFFSET_CAPTURE), \sprintf('Oracle: %s finds no match in %d run words.', $pattern, $words));
            $this->assertLessThanOrEqual(1, $matches[0][1], \sprintf('Oracle: %s matches past its second attempt in %d run words.', $pattern, $words));
        }

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyze($pattern)->searchCost, $pattern);
        $this->assertNull($analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost, $pattern.' in confirmed mode');
    }

    /**
     * @return iterable<string, array{pattern: string, run: string, breaker: string}>
     */
    public static function provideSearchesMatchingAtOnce(): iterable
    {
        yield 'optional group, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)(?:a+\d)?/', 'run' => 'a', 'breaker' => '!'];
        yield 'empty branch, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)(?:|a+\d)/', 'run' => 'a', 'breaker' => '!'];
        yield 'star loop' => ['pattern' => '/(*NOTEMPTY)a*/', 'run' => 'a', 'breaker' => '!'];
        yield 'optional group with a one-character branch' => ['pattern' => '/(*NOTEMPTY)(?:a+\d|a)?/', 'run' => 'a', 'breaker' => '!'];
        yield 'possessive run' => ['pattern' => '/(*NOTEMPTY)a++/', 'run' => 'a', 'breaker' => '!'];
        yield 'atomic run' => ['pattern' => '/(*NOTEMPTY)(?>a+)/', 'run' => 'a', 'breaker' => '!'];
    }

    /**
     * The attempt at the search start matches past "\K", so the search ends
     * there, though the match it reports may stand at the run's end: "\K"
     * leaves it empty past the search start, which (*NOTEMPTY_ATSTART) and
     * a pattern without the options accept, or the match reads a character
     * after the last "\K" it passed. Nothing is reported.
     */
    #[Test]
    #[DataProvider('provideSearchesMatchingPastKeepAtOnce')]
    public function test_search_cost_is_not_reported_when_the_first_attempt_matches_past_keep(string $pattern, string $run, string $breaker): void
    {
        $first = PcreInterpreter::pattern($pattern).'A';
        foreach ([1, 2, 3, 100] as $words) {
            $this->assertSame(1, preg_match($first, str_repeat($run, $words).$breaker), \sprintf('Oracle: the attempt of %s at the search start fails on %d run words.', $pattern, $words));
        }

        $analyzer = new RedosAnalyzer();
        $this->assertNull($analyzer->analyze($pattern)->searchCost, $pattern);
        $this->assertNull($analyzer->analyze($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low))->searchCost, $pattern.' in confirmed mode');
    }

    /**
     * @return iterable<string, array{pattern: string, run: string, breaker: string}>
     */
    public static function provideSearchesMatchingPastKeepAtOnce(): iterable
    {
        yield '\K at the end, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)a+\K/', 'run' => 'a', 'breaker' => '!'];
        yield '\K before an optional item, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)a+\Kb?/', 'run' => 'a', 'breaker' => '!'];
        yield '\K at the end, no option' => ['pattern' => '/a+\K/', 'run' => 'a', 'breaker' => '!'];
        yield '\K before the run' => ['pattern' => '/(*NOTEMPTY)\Ka+/', 'run' => 'a', 'breaker' => '!'];
        // The second branch matches the character the first one read last.
        yield '\K in one branch, a character in the other' => ['pattern' => '/(*NOTEMPTY)a+\K|a/', 'run' => 'a', 'breaker' => '!'];
        // The last iteration takes the empty branch: the "\K" of the one
        // before stands before the last character.
        yield '\K or nothing after each character' => ['pattern' => '/(*NOTEMPTY)(?:a(?:\K|))+/', 'run' => 'a', 'breaker' => '!'];
        yield '\K after a possessive run, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)a++\K/', 'run' => 'a', 'breaker' => '!'];
        yield '\K inside an atomic group, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)(?>a+\K)/', 'run' => 'a', 'breaker' => '!'];
        yield '\K inside nested atomic groups, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)(?>(?>a+)\K)/', 'run' => 'a', 'breaker' => '!'];
        yield '\K after a possessive run, no option' => ['pattern' => '/a++\K/', 'run' => 'a', 'breaker' => '!'];
        yield '\K inside an atomic group, no option' => ['pattern' => '/(?>a+\K)/', 'run' => 'a', 'breaker' => '!'];
    }

    /**
     * Tied to the search start, the attempts past it fail at once whatever
     * the option: only the first one reads the run.
     */
    #[Test]
    #[DataProvider('provideAnchoredSearches')]
    public function test_search_cost_is_not_reported_when_the_attempts_are_tied_to_the_search_start(string $pattern): void
    {
        $subject = str_repeat('a', 4 * self::QUARTER).'!';
        $pinned = PcreAttemptSteps::pinned($pattern);
        $this->assertGreaterThan(4 * self::QUARTER, PcreAttemptSteps::steps($pinned, $subject, 0), 'Oracle: the first attempt of '.$pattern.' does not read the run.');
        for ($offset = 1; $offset <= \strlen($subject); $offset += 37) {
            $this->assertLessThanOrEqual(2, PcreAttemptSteps::steps($pinned, $subject, $offset), \sprintf('Oracle: %s from offset %d', $pattern, $offset));
        }

        $this->assertNull((new RedosAnalyzer())->analyze($pattern)->searchCost, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAnchoredSearches(): iterable
    {
        yield 'caret' => ['pattern' => '/(*NOTEMPTY)^(?:a+\d)?/'];
        yield '\A' => ['pattern' => '/(*NOTEMPTY)\A(?:a+\d)?/'];
        yield 'caret, empty match refused at the search start' => ['pattern' => '/(*NOTEMPTY_ATSTART)^(?:a+\d)?/'];
    }

    /**
     * No match of these patterns is empty: each attempt takes the steps it
     * takes without the option, and the search cost is the bare pattern's.
     */
    #[Test]
    #[DataProvider('providePatternsThatCannotMatchEmpty')]
    public function test_search_cost_is_the_bare_patterns_when_no_match_is_empty(string $options, string $shape, string $run, string $breaker): void
    {
        $pattern = '/'.$options.$shape.'/';
        $bare = '/'.$shape.'/';
        $subject = str_repeat($run, 4 * self::QUARTER).$breaker;
        $this->assertSame(preg_match(PcreInterpreter::pattern($bare), $subject), preg_match(PcreInterpreter::pattern($pattern), $subject), 'Oracle: '.$pattern.' and '.$bare.' disagree on the search.');
        foreach ([0, 1, 2] as $quarters) {
            $offset = $quarters * self::QUARTER * \strlen($run);
            $this->assertSame(
                PcreAttemptSteps::steps(PcreAttemptSteps::pinned($bare), $subject, $offset),
                PcreAttemptSteps::steps(PcreAttemptSteps::pinned($pattern), $subject, $offset),
                \sprintf('Oracle: %s and %s take different steps from offset %d.', $pattern, $bare, $offset),
            );
        }

        $analyzer = new RedosAnalyzer();
        $this->assertSame($analyzer->analyze($bare)->searchCost?->toArray(), $analyzer->analyze($pattern)->searchCost?->toArray(), $pattern);
    }

    /**
     * @return iterable<string, array{options: string, shape: string, run: string, breaker: string}>
     */
    public static function providePatternsThatCannotMatchEmpty(): iterable
    {
        foreach (['(*NOTEMPTY)', '(*NOTEMPTY_ATSTART)'] as $options) {
            yield $options.', literal run' => ['options' => $options, 'shape' => 'a+b', 'run' => 'a', 'breaker' => '!b'];
            yield $options.', trailing whitespace' => ['options' => $options, 'shape' => '\s+$', 'run' => ' ', 'breaker' => '!'];
            yield $options.', two-character run word' => ['options' => $options, 'shape' => '(?:ab)+c', 'run' => 'ab', 'breaker' => 'dc'];
            yield $options.', anchored run' => ['options' => $options, 'shape' => '^a+b', 'run' => 'a', 'breaker' => 'cb'];
        }
    }

    /**
     * The attempts pinned 250, 500 and 750 run words before the breaker
     * fail, each taking at least one more step per run word than the next.
     */
    private function assertAttemptsGrowWithTheRestOfTheRun(string $pattern, string $prefix, string $run, string $breaker): void
    {
        $subject = $prefix.str_repeat($run, 4 * self::QUARTER).$breaker;
        $pinned = PcreAttemptSteps::pinned($pattern);
        $steps = [];
        foreach ([1, 2, 3] as $quarters) {
            $offset = \strlen($prefix) + (4 - $quarters) * self::QUARTER * \strlen($run);
            $this->assertSame(0, preg_match($pinned, $subject, $matches, 0, $offset), \sprintf('Oracle: the attempt of %s %d run words before the breaker matches.', $pattern, $quarters * self::QUARTER));
            $steps[] = PcreAttemptSteps::steps($pinned, $subject, $offset);
        }

        $growth = [$steps[1] - $steps[0], $steps[2] - $steps[1]];
        $this->assertSame([$growth[0], $growth[0]], $growth, \sprintf('Oracle: the steps %s of %s are not linear in the rest of the run.', implode(' / ', $steps), $pattern));
        $this->assertGreaterThanOrEqual(self::QUARTER, $growth[0], \sprintf('Oracle: %s takes fewer steps than run words.', $pattern));
    }

    /**
     * The pattern without the options that refuse an empty match.
     */
    private static function bare(string $pattern): string
    {
        return str_replace(['(*NOTEMPTY)', '(*NOTEMPTY_ATSTART)'], '', $pattern);
    }
}

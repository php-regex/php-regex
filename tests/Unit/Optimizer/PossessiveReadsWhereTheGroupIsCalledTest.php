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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A group runs where it stands and wherever a call runs it: "(?1)",
 * "(?-1)", "(?+1)", "(?&n)", "(?P>n)", "\g<n>", and "(?R)" for the whole
 * pattern. A call gives back what the group matched to what follows the
 * call: "(?&n)a(?(DEFINE)(?<n>a+b?))" matches "aa", where
 * "(?&n)a(?(DEFINE)(?<n>a++b?))" matches nothing. The possessive rewrite
 * reads what follows the group at every place it runs, its own place left
 * out in a DEFINE, which never runs there, and a call inside a script run
 * left unread, as the script check reads what the group matched.
 */
final class PossessiveReadsWhereTheGroupIsCalledTest extends TestCase
{
    use PcreGates;

    /**
     * The rows that hold from a PCRE2 newer than the floor: the interpreter
     * of PCRE2 10.42 and before backtracks into no call of the whole
     * pattern, where the JIT and later releases do. The rewrite stays greedy
     * on every engine.
     */
    private const NEWER_RELEASES = ['10.44' => ['/x(?R)?a+b?()/', '/x(?0)?a+b?()/']];

    /**
     * The characters of the subjects each pair is run on, every string of
     * them up to five long.
     */
    private const ALPHABET = ['a', 'b', 'c', 'd', 'x'];

    #[Test]
    #[DataProvider('provideGroupsCalledWhereWhatFollowsTakesBack')]
    public function test_a_group_called_where_what_follows_takes_back_what_it_matched_stays_greedy(string $pattern, string $possessive, string $subject, string $rewritten): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);

        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($rewritten), \sprintf('Oracle: %s and %s differ.', $pattern, $rewritten));

        $this->assertSame($rewritten, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string, rewritten: string}>
     */
    public static function provideGroupsCalledWhereWhatFollowsTakesBack(): iterable
    {
        yield 'a character after the call, the group defined' => ['pattern' => '/(?&n)a(?(DEFINE)(?<n>a+b?))/', 'possessive' => '/(?&n)a(?(DEFINE)(?<n>a++b?))/', 'subject' => 'aa', 'rewritten' => '/(?&n)a(?(DEFINE)(?<n>a+b?))/'];
        yield 'a call by number, the group used in place' => ['pattern' => '/(a+b?)c(?1)a/', 'possessive' => '/(a++b?)c(?1)a/', 'subject' => 'acaa', 'rewritten' => '/(a+b?)c(?1)a/'];
        yield 'a call, the group followed in place by what it gives back' => ['pattern' => '/(a+b?)a(?1)c/', 'possessive' => '/(a++b?)a(?1)c/', 'subject' => 'aaac', 'rewritten' => '/(a+b?)a(?1)c/'];
        yield 'a relative call back' => ['pattern' => '/(a+b?)c(?-1)a/', 'possessive' => '/(a++b?)c(?-1)a/', 'subject' => 'acaa', 'rewritten' => '/(a+b?)c(?-1)a/'];
        yield 'a relative call back with \g' => ['pattern' => '/(a+b?)c\g<-1>a/', 'possessive' => '/(a++b?)c\g<-1>a/', 'subject' => 'acaa', 'rewritten' => '/(a+b?)c\g<-1>a/'];
        yield 'a relative call forward' => ['pattern' => '/(?+1)a(a+b?)c/', 'possessive' => '/(?+1)a(a++b?)c/', 'subject' => 'aaac', 'rewritten' => '/(?+1)a(a+b?)c/'];
        yield 'a call by name with \g' => ['pattern' => '/(?<n>a+b?)c\g<n>a/', 'possessive' => '/(?<n>a++b?)c\g<n>a/', 'subject' => 'acaa', 'rewritten' => '/(?<n>a+b?)c\g<n>a/'];
        yield 'a call by name, Python syntax' => ['pattern' => '/(?<n>a+b?)c(?P>n)a/', 'possessive' => '/(?<n>a++b?)c(?P>n)a/', 'subject' => 'acaa', 'rewritten' => '/(?<n>a+b?)c(?P>n)a/'];
        // The printer writes the call with angle brackets.
        yield 'a call by number with \g' => ['pattern' => "/(a+b?)c\\g'1'a/", 'possessive' => "/(a++b?)c\\g'1'a/", 'subject' => 'acaa', 'rewritten' => '/(a+b?)c\g<1>a/'];
        yield 'a call of a group named R' => ['pattern' => '/(?<R>a+b?)c(?&R)a/', 'possessive' => '/(?<R>a++b?)c(?&R)a/', 'subject' => 'acaa', 'rewritten' => '/(?<R>a+b?)c(?&R)a/'];
        yield 'the whole pattern recursed' => ['pattern' => '/x(?R)?a+b?()/', 'possessive' => '/x(?R)?a++b?()/', 'subject' => 'xxaa', 'rewritten' => '/x(?R)?a+b?()/'];
        yield 'the whole pattern called by number' => ['pattern' => '/x(?0)?a+b?()/', 'possessive' => '/x(?0)?a++b?()/', 'subject' => 'xxaa', 'rewritten' => '/x(?0)?a+b?()/'];
        yield 'a group calling itself' => ['pattern' => '/(x(?1)?a+b?)c/', 'possessive' => '/(x(?1)?a++b?)c/', 'subject' => 'xxaac', 'rewritten' => '/(x(?1)?a+b?)c/'];
        yield 'a call from a called group' => ['pattern' => '/(?&o)a(?(DEFINE)(?<o>x(?&i))(?<i>a+b?))/', 'possessive' => '/(?&o)a(?(DEFINE)(?<o>x(?&i))(?<i>a++b?))/', 'subject' => 'xaa', 'rewritten' => '/(?&o)a(?(DEFINE)(?<o>x(?&i))(?<i>a+b?))/'];
        yield 'a call that must run again' => ['pattern' => '/(a+b?)c(?1){2}d/', 'possessive' => '/(a++b?)c(?1){2}d/', 'subject' => 'acaaad', 'rewritten' => '/(a+b?)c(?1){2}d/'];
        yield 'a call at the end of a group' => ['pattern' => '/(a+b?)c(?:x(?1))a/', 'possessive' => '/(a++b?)c(?:x(?1))a/', 'subject' => 'acxaa', 'rewritten' => '/(a+b?)c(?:x(?1))a/'];
        yield 'a call inside a lookahead' => ['pattern' => '/(?=(?1)a)(a+b?)c/', 'possessive' => '/(?=(?1)a)(a++b?)c/', 'subject' => 'aac', 'rewritten' => '/(?=(?1)a)(a+b?)c/'];
        yield 'a call inside a negative lookahead' => ['pattern' => '/(?!(?1)a)(a+b?)c/', 'possessive' => '/(?!(?1)a)(a++b?)c/', 'subject' => 'aac', 'rewritten' => '/(?!(?1)a)(a+b?)c/'];
        yield 'a group inside a called group, both defined' => ['pattern' => '/(?&h)c(?(DEFINE)(?<h>x(?<n>a+b?)a))/', 'possessive' => '/(?&h)c(?(DEFINE)(?<h>x(?<n>a++b?)a))/', 'subject' => 'xaac', 'rewritten' => '/(?&h)c(?(DEFINE)(?<h>x(?<n>a+b?)a))/'];
        // The script check reads what the call matched: "α1a" mixes Greek
        // and Latin, "α1" does not, and nothing after the run takes the "a"
        // back.
        yield 'a call inside a script run' => ['pattern' => '/([1a]+b?)x(*sr:\p{Greek}(?1))/u', 'possessive' => '/([1a]++b?)x(*sr:\p{Greek}(?1))/u', 'subject' => "1x\u{3B1}1a", 'rewritten' => '/([1a]+b?)x(*sr:\p{Greek}(?1))/u'];
    }

    #[Test]
    #[DataProvider('provideGroupsCalledOnlyWhereNothingTakesBack')]
    public function test_a_group_called_only_where_nothing_takes_back_what_it_matched_is_made_possessive(string $pattern, string $possessive): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($possessive), \sprintf('Oracle: %s and %s differ.', $pattern, $possessive));

        $this->assertSame($possessive, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideGroupsCalledOnlyWhereNothingTakesBack(): iterable
    {
        yield 'a character after the call, the group defined' => ['pattern' => '/(?&n)c(?(DEFINE)(?<n>a+b?))/', 'possessive' => '/(?&n)c(?(DEFINE)(?<n>a++b?))/'];
        yield 'a call by number, the group used in place' => ['pattern' => '/(a+b?)c(?1)d/', 'possessive' => '/(a++b?)c(?1)d/'];
        yield 'a relative call back' => ['pattern' => '/(a+b?)c(?-1)d/', 'possessive' => '/(a++b?)c(?-1)d/'];
        yield 'a relative call forward' => ['pattern' => '/(?+1)c(a+b?)d/', 'possessive' => '/(?+1)c(a++b?)d/'];
        yield 'a call of a group named R' => ['pattern' => '/(?<R>a+b?)c(?&R)d/', 'possessive' => '/(?<R>a++b?)c(?&R)d/'];
        yield 'the whole pattern recursed' => ['pattern' => '/x(?R)?ca+b?()/', 'possessive' => '/x(?R)?ca++b?()/'];
        yield 'the whole pattern recursed at its end' => ['pattern' => '/x(?R)?|a+b?/', 'possessive' => '/x(?R)?|a++b?/'];
        yield 'a group calling itself' => ['pattern' => '/(x(?1)?ca+b?)c/', 'possessive' => '/(x(?1)?ca++b?)c/'];
        yield 'a group calling itself at its end' => ['pattern' => '/(x(?1)?|a+b?)c/', 'possessive' => '/(x(?1)?|a++b?)c/'];
        yield 'a call from a called group' => ['pattern' => '/(?&o)c(?(DEFINE)(?<o>x(?&i))(?<i>a+b?))/', 'possessive' => '/(?&o)c(?(DEFINE)(?<o>x(?&i))(?<i>a++b?))/'];
        yield 'a call inside a lookahead' => ['pattern' => '/(?=(?1)c)(a+b?)c/', 'possessive' => '/(?=(?1)c)(a++b?)c/'];
        yield 'a call at the end of a lookahead' => ['pattern' => '/(?=x(?1))(a+b?)c/', 'possessive' => '/(?=x(?1))(a++b?)c/'];
        // The group defined first never runs where it stands, before the
        // other.
        yield 'a group defined before another' => ['pattern' => '/(?&n)c(?&m)(?(DEFINE)(?<n>a+b?)(?<m>a))/', 'possessive' => '/(?&n)c(?&m)(?(DEFINE)(?<n>a++b?)(?<m>a))/'];
        yield 'a group defined before another, nothing else in the pattern' => ['pattern' => '/(?(DEFINE)(?<n>a+b?)(?<m>a))/', 'possessive' => '/(?(DEFINE)(?<n>a++b?)(?<m>a))/'];
        yield 'a called group defined inside one never called' => ['pattern' => '/(?&n)c(?(DEFINE)(?<h>x(?<n>a+b?)a))/', 'possessive' => '/(?&n)c(?(DEFINE)(?<h>x(?<n>a++b?)a))/'];
        yield 'a group defined and never called' => ['pattern' => '/(?(DEFINE)(?<n>a+b?))a/', 'possessive' => '/(?(DEFINE)(?<n>a++b?))a/'];
        yield 'a DEFINE holding no group' => ['pattern' => '/(?(DEFINE)a+b?)a/', 'possessive' => '/(?(DEFINE)a++b?)a/'];
        yield 'a recursion test, which calls nothing' => ['pattern' => '/(?(R)x|y)a+b?/', 'possessive' => '/(?(R)x|y)a++b?/'];
        yield 'a recursion test of a group, which calls nothing' => ['pattern' => '/(?(R1)a|b)(a+b?)c/', 'possessive' => '/(?(R1)a|b)(a++b?)c/'];
    }

    /**
     * A repetition alone in a group, or ending it, is followed by what
     * follows the group at every place it runs.
     */
    #[Test]
    #[DataProvider('provideRepetitionsEndingCalledGroupsFollowedByWhatTheyNeverGiveBack')]
    public function test_a_repetition_ending_a_group_called_only_where_nothing_takes_back_what_it_matched_is_made_possessive(string $pattern, string $expected): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $this->assertSame($expected, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideRepetitionsEndingCalledGroupsFollowedByWhatTheyNeverGiveBack(): iterable
    {
        yield 'a group a DEFINE holds, called before another character' => ['pattern' => '/(?&n)c(?(DEFINE)(?<n>a+))/', 'expected' => '/(?&n)c(?(DEFINE)(?<n>a++))/'];
        yield 'a group a DEFINE holds, the repetition ending it' => ['pattern' => '/(?&n)c(?(DEFINE)(?<n>xa+))/', 'expected' => '/(?&n)c(?(DEFINE)(?<n>xa++))/'];
        yield 'a call by number, the group used in place' => ['pattern' => '/(a+)c(?1)d/', 'expected' => '/(a++)c(?1)d/'];
        yield 'a call at the end of the pattern' => ['pattern' => '/(a+)c(?1)/', 'expected' => '/(a++)c(?1)/'];
    }

    #[Test]
    #[DataProvider('provideRepetitionsEndingCalledGroupsFollowedByWhatTheyGiveBack')]
    public function test_a_repetition_ending_a_group_called_where_what_follows_takes_back_what_it_matched_stays_greedy(string $pattern, string $possessive, string $subject, string $expected): void
    {
        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $this->assertSame($expected, self::rewrite($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string, expected: string}>
     */
    public static function provideRepetitionsEndingCalledGroupsFollowedByWhatTheyGiveBack(): iterable
    {
        yield 'a group a DEFINE holds, called before what it gives back' => ['pattern' => '/(?&n)a(?(DEFINE)(?<n>a+))/', 'possessive' => '/(?&n)a(?(DEFINE)(?<n>a++))/', 'subject' => 'aa', 'expected' => '/(?&n)a(?(DEFINE)(?<n>a+))/'];
        yield 'a call by number before what the group gives back' => ['pattern' => '/(a+)c(?1)a/', 'possessive' => '/(a++)c(?1)a/', 'subject' => 'acaa', 'expected' => '/(a+)c(?1)a/'];
        // A call that may run again is followed by its next run, which may
        // take what the repetition gave back and match on past it.
        yield 'a call that may run again' => ['pattern' => '/(b+|ba)c(?1)+d/', 'possessive' => '/(b++|ba)c(?1)+d/', 'subject' => 'bcbbad', 'expected' => '/(b+|ba)c(?1)+d/'];
        yield 'a group a DEFINE holds, called by a call that may run again' => ['pattern' => '/(?&n)+c(?(DEFINE)(?<n>b+|ba))/', 'possessive' => '/(?&n)+c(?(DEFINE)(?<n>b++|ba))/', 'subject' => 'bbac', 'expected' => '/(?&n)+c(?(DEFINE)(?<n>b+|ba))/'];
    }

    /**
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideOptimizationsOfRepetitionsEndingCalledGroups')]
    public function test_the_optimizer_ships_the_possessive_of_a_repetition_ending_a_called_group(string $pattern, string $expected, array $changes): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($expected), \sprintf('Oracle: %s and %s differ.', $pattern, $expected));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($expected, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, changes: list<string>}>
     */
    public static function provideOptimizationsOfRepetitionsEndingCalledGroups(): iterable
    {
        yield 'a group a DEFINE holds, called before another character' => ['pattern' => '/(?&n)c(?(DEFINE)(?<n>a+))/', 'expected' => '/(?&n)c(?(DEFINE)(?<n>a++))/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a call by number, the group used in place' => ['pattern' => '/(a+)c(?1)d/', 'expected' => '/(a++)c(?1)d/', 'changes' => [Rewriter::POSSESSIVE]];
    }

    /**
     * The rewrite the optimizer makes first holds no possessive the check
     * of the result refuses: it ships as made, with no second rewrite
     * without the possessive step.
     *
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideFirstRewrites')]
    public function test_the_first_rewrite_ships_without_a_second_one(string $pattern, string $possessive, string $subject, string $optimized, array $changes): void
    {
        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($optimized), \sprintf('Oracle: %s and %s differ.', $pattern, $optimized));

        // The rewriter the optimizer runs first.
        $rewriter = new Rewriter(possessive: true);
        $this->assertSame($optimized, Regex::create(['cache' => null])->parse($pattern)->accept($rewriter)->accept(new PatternPrinter()));
        $this->assertSame($changes, $rewriter->changes());
        $this->assertNotContains(Rewriter::POSSESSIVE, $rewriter->changes(), 'No possessive step to make the rewrite again without.');

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string, optimized: string, changes: list<string>}>
     */
    public static function provideFirstRewrites(): iterable
    {
        yield 'the group defined, nothing else to rewrite' => ['pattern' => '/(?&n)a(?(DEFINE)(?<n>a+b?))/', 'possessive' => '/(?&n)a(?(DEFINE)(?<n>a++b?))/', 'subject' => 'aa', 'optimized' => '/(?&n)a(?(DEFINE)(?<n>a+b?))/', 'changes' => []];
        yield 'the group defined, a class written as a shorthand' => ['pattern' => '/(?&n)a[0-9](?(DEFINE)(?<n>a+b?))/', 'possessive' => '/(?&n)a[0-9](?(DEFINE)(?<n>a++b?))/', 'subject' => 'aa1', 'optimized' => '/(?&n)a\d(?(DEFINE)(?<n>a+b?))/', 'changes' => [Rewriter::DIGITS]];
    }

    /**
     * The check of the result runs the body of a group wherever a call runs
     * it, a group a DEFINE holds included, and proves the possessive the
     * rewrite offers there: the rewrite ships as first made, the possessive
     * with the other rewrites.
     *
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideGroupsTheCheckRunsWhereTheyAreCalled')]
    public function test_a_possessive_in_a_called_group_ships_with_the_first_rewrite(string $pattern, string $optimized, array $changes): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($optimized), \sprintf('Oracle: %s and %s differ.', $pattern, $optimized));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string, changes: list<string>}>
     */
    public static function provideGroupsTheCheckRunsWhereTheyAreCalled(): iterable
    {
        yield 'a call by number' => ['pattern' => '/(a+b?)c(?1)d/', 'optimized' => '/(a++b?)c(?1)d/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a relative call back' => ['pattern' => '/(a+b?)c(?-1)d/', 'optimized' => '/(a++b?)c(?-1)d/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a relative call forward' => ['pattern' => '/(?+1)c(a+b?)d/', 'optimized' => '/(?+1)c(a++b?)d/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a call by name' => ['pattern' => '/(?<n>a+b?)c(?&n)d/', 'optimized' => '/(?<n>a++b?)c(?&n)d/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a call of a group named R' => ['pattern' => '/(?<R>a+b?)c(?&R)d/', 'optimized' => '/(?<R>a++b?)c(?&R)d/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a call at the end of the pattern' => ['pattern' => '/(a+b?)c(?1)/', 'optimized' => '/(a++b?)c(?1)/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a call beside another rewrite' => ['pattern' => '/(a+b?)c(?1)(?:d)/', 'optimized' => '/(a++b?)c(?1)d/', 'changes' => [Rewriter::POSSESSIVE, Rewriter::GROUP_REMOVED]];
        // A group a DEFINE holds runs where a call runs it, and nowhere
        // else.
        yield 'a call of a group a DEFINE holds' => ['pattern' => '/(?&n)c(?(DEFINE)(?<n>a+b?))/', 'optimized' => '/(?&n)c(?(DEFINE)(?<n>a++b?))/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a call by number of a group a DEFINE holds' => ['pattern' => '/(?1)c(?(DEFINE)(a+b?))/', 'optimized' => '/(?1)c(?(DEFINE)(a++b?))/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a call of a group a DEFINE holds, beside another rewrite' => ['pattern' => '/(?&n)c(?:d)(?(DEFINE)(?<n>a+b?))/', 'optimized' => '/(?&n)cd(?(DEFINE)(?<n>a++b?))/', 'changes' => [Rewriter::GROUP_REMOVED, Rewriter::POSSESSIVE]];
        yield 'a call of a group a DEFINE holds, from another it holds' => ['pattern' => '/(?&o)c(?(DEFINE)(?<o>x(?&i))(?<i>a+b?))/', 'optimized' => '/(?&o)c(?(DEFINE)(?<o>x(?&i))(?<i>a++b?))/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a group a DEFINE holds, never called' => ['pattern' => '/(?(DEFINE)(?<n>a+b?))a/', 'optimized' => '/(?(DEFINE)(?<n>a++b?))a/', 'changes' => [Rewriter::POSSESSIVE]];
        // A group that runs itself again is read through every call: what
        // may follow the "a"s, inside the group or after any call of it.
        yield 'a group a DEFINE holds calling itself' => ['pattern' => '/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca+b?))/', 'optimized' => '/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca++b?))/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a group calling itself' => ['pattern' => '/(x(?1)?ca+b?)c/', 'optimized' => '/(x(?1)?ca++b?)c/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'the whole pattern recursed' => ['pattern' => '/x(?R)?ca+b?()/', 'optimized' => '/x(?R)?ca++b?()/', 'changes' => [Rewriter::POSSESSIVE]];
    }

    /**
     * A call the check does not run in place leaves its proof to the
     * rewrite without the possessive step, though the possessive gives
     * nothing back: a group that calls itself inside a lookahead, or that
     * a backreference reads back, and a possessive group inside a group
     * that calls itself, which keeps a frame of the JIT for each run of the
     * call: deep in the calls, its stack runs out where the greedy group
     * matches.
     *
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideGroupsTheCheckDoesNotRunWhereTheyAreCalled')]
    public function test_a_possessive_in_a_group_the_check_does_not_run_where_it_is_called_stays_greedy(string $pattern, string $possessive, string $optimized, array $changes, ?string $deep = null): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($possessive), \sprintf('Oracle: %s and %s differ.', $pattern, $possessive));
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($optimized), \sprintf('Oracle: %s and %s differ.', $pattern, $optimized));
        $this->assertSame($possessive, self::rewrite($pattern), 'The rewrite offers the possessive.');

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);

        if (null !== $deep) {
            $this->requiresJit($pattern);

            $this->assertSame(1, preg_match($pattern, $deep), \sprintf('Oracle: %s matches %d bytes of calls.', $pattern, \strlen($deep)));
            $this->assertFalse(preg_match($possessive, $deep), \sprintf('Oracle: %s runs out of the JIT stack on %d bytes of calls.', $possessive, \strlen($deep)));
            $this->assertSame(\PREG_JIT_STACKLIMIT_ERROR, preg_last_error());
        }
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, optimized: string, changes: list<string>, deep?: string}>
     */
    public static function provideGroupsTheCheckDoesNotRunWhereTheyAreCalled(): iterable
    {
        yield 'a group a DEFINE holds calling itself, called inside a lookahead' => ['pattern' => '/(?=(?&n)c)(?(DEFINE)(?<n>x(?&n)?ca+b?))/', 'possessive' => '/(?=(?&n)c)(?(DEFINE)(?<n>x(?&n)?ca++b?))/', 'optimized' => '/(?=(?&n)c)(?(DEFINE)(?<n>x(?&n)?ca+b?))/', 'changes' => []];
        yield 'a group calling itself, read back by a backreference' => ['pattern' => '/(x(?1)?ca+b?)c\1/', 'possessive' => '/(x(?1)?ca++b?)c\1/', 'optimized' => '/(x(?1)?ca+b?)c\1/', 'changes' => []];
        yield 'a group repeated in a pattern that calls itself whole' => ['pattern' => '/x(?R)?(?:ab)+c/', 'possessive' => '/x(?R)?(?:ab)++c/', 'optimized' => '/x(?R)?(?:ab)+c/', 'changes' => [], 'deep' => str_repeat('x', 3_100).str_repeat('abc', 3_100)];
        yield 'a group repeated in a group that calls itself' => ['pattern' => '/(x(?1)?c(?:ab)+d?)e/', 'possessive' => '/(x(?1)?c(?:ab)++d?)e/', 'optimized' => '/(x(?1)?c(?:ab)+d?)e/', 'changes' => [], 'deep' => str_repeat('x', 3_100).str_repeat('cab', 3_100).'e'];
    }

    /**
     * A tree visited without its pattern names no call: what follows a
     * group is read where it stands.
     */
    #[Test]
    #[DataProvider('provideTreesVisitedWithoutTheirPattern')]
    public function test_a_tree_visited_without_its_pattern_reads_where_the_group_stands(string $pattern, string $rewritten): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($rewritten), \sprintf('Oracle: %s and %s differ.', $pattern, $rewritten));

        $ast = Regex::create(['cache' => null])->parse($pattern);
        $tree = $ast->pattern->accept(new Rewriter(possessive: true))->accept(new PatternPrinter());

        $this->assertSame($rewritten, $ast->delimiter.$tree.$ast->delimiter.$ast->flags);
    }

    /**
     * @return iterable<string, array{pattern: string, rewritten: string}>
     */
    public static function provideTreesVisitedWithoutTheirPattern(): iterable
    {
        yield 'a character after the group it never gives back' => ['pattern' => '/(a+b?)c/', 'rewritten' => '/(a++b?)c/'];
        yield 'a character after the group it gives back' => ['pattern' => '/(a+b?)a/', 'rewritten' => '/(a+b?)a/'];
    }

    private static function rewrite(string $pattern): string
    {
        return Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(possessive: true))->accept(new PatternPrinter());
    }

    /**
     * What preg_match() answers on every string of the alphabet up to five
     * characters long, with the text and the offset of the match and of
     * each group.
     *
     * @return list<array{int|false, array<array-key, mixed>}>
     */
    private static function matchedOnEverySubject(string $pattern): array
    {
        $answers = [];
        $subjects = [''];
        for ($length = 0; $length <= 5; $length++) {
            $longer = [];
            foreach ($subjects as $subject) {
                $answers[] = self::matched($pattern, $subject);
                foreach (self::ALPHABET as $character) {
                    $longer[] = $subject.$character;
                }
            }

            $subjects = $longer;
        }

        return $answers;
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function matched(string $pattern, string $subject): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);

        return [$result, $matches];
    }
}

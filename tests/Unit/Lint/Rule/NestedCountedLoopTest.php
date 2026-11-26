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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\NestedQuantifierRule;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern counted with every repeat in it takes a bounded number of
 * characters, and the engine reads it in a bounded number of ways: however
 * long the subject, one attempt costs what the pattern alone decides. Where
 * those ways are few, the nested-loop rule keeps quiet about its loops:
 * 2,048 where every attempt starts at one place, 7 where a search may start
 * anywhere and pays the attempt at every offset.
 *
 * The steps of one attempt are the smallest pcre.backtrack_limit under
 * which preg_match() finishes, the interpreter running with its start
 * optimizations off; each subject is the costliest a search found on PCRE2
 * 10.49 (the runs of each character, then random edits from the costliest
 * of them). Where every attempt starts at one place, the loops the rule
 * keeps quiet about stay far under 10,000 steps, a hundredth of PHP's
 * default pcre.backtrack_limit: 73 for the costliest of mpdf's three
 * syllable patterns, 438 for "/^(a{0,4}){0,4}$/", 2,559 for
 * "/^(a?){0,10}$/", 4,094 for its lazy twin. Where a search may start
 * anywhere, they stay under 20 steps a start, which 100,000 "a" pay in
 * about 20 ms without the JIT: 14 for "/(a?){0,2}(?:b|c)/", where
 * "/(a?){0,3}(?:b|c)/" takes 30, about 50 ms, and "/(a?){0,10}(?:b|c)/"
 * 4,094, about 10 s.
 */
final class NestedCountedLoopTest extends TestCase
{
    /**
     * The steps under which one attempt of a pattern whose every attempt
     * starts at one place stays where the rule keeps quiet, and over which
     * it goes where the rule reports.
     */
    private const FEW_STEPS = 10_000;

    /**
     * The same, for one attempt of a search that may start anywhere.
     */
    private const FEW_STEPS_A_START = 20;

    #[Test]
    #[DataProvider('provideLoopsReadInAFewWays')]
    public function test_a_counted_loop_read_in_a_few_ways_is_not_reported(string $pattern, string $subject): void
    {
        $steps = PcreAttemptSteps::steps(self::counted($pattern), $subject);
        $this->assertLessThan(self::FEW_STEPS, $steps, \sprintf('Oracle: %s takes %d steps on "%s".', $pattern, $steps, $subject));

        $this->assertNotContains(NestedQuantifierRule::ID, self::lint($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideLoopsReadInAFewWays(): iterable
    {
        yield 'mpdf, a Devanagari syllable' => [
            'pattern' => '/^r?([CR]J?((Z?F)?[N]{0,2})?[ZJ]?G(JN?)?){0,4}[CR]J?((Z?F)?[N]{0,2})?A?((([ZJ]?G(JN?)?)|GZ)|(GJ)?([ZJ]{0,3}MN?(H|JHJR)?){0,4})?(G([CR]J?((Z?F)?[N]{0,2})?|V))?(SZ?)?[v]{0,2}/',
            'subject' => 'CJGRJGRJMMMZMGRNSFSMNSCvxCJSrRZrRArGVJCVVMNFMJSvCGZGv',
        ];
        yield 'mpdf, a vowel syllable' => [
            'pattern' => '/^(RH|r)?V((Z?F)?[N]{0,2})?(J|([ZJ]?G(JN?)?[CR]J?((Z?F)?[N]{0,2})?){0,4}((([ZJ]?G(JN?)?)|GZ)|(GJ)?([ZJ]{0,3}MN?(H|JHJR)?){0,4})?(G([CR]J?((Z?F)?[N]{0,2})?|V))?(SZ?)?[v]{0,2})/',
            'subject' => 'VMMMMNGRRAJNVZrVMGFSJMHrHNFZJHvvrxCACRZHNvRv',
        ];
        yield 'mpdf, a standalone cluster' => [
            'pattern' => '/^(RH|r)?((Z?F)?[N]{0,2})?(([ZJ]?G(JN?)?)[CR]J?((Z?F)?[N]{0,2})?){0,4}((([ZJ]?G(JN?)?)|GZ)|(GJ)?([ZJ]{0,3}MN?(H|JHJR)?){0,4})(G([CR]J?((Z?F)?[N]{0,2})?|V))?(SZ?)?[v]{0,2}/',
            'subject' => 'MMMJMGCRrMvRVvJGVvNxJZJNMxZHNCRrAM',
        ];
        yield 'four runs of up to four letters' => ['pattern' => '/^(a{0,4}){0,4}$/', 'subject' => 'aaaaaaaaaaaa!'];
        yield 'ten optional letters' => ['pattern' => '/^(a?){0,10}$/', 'subject' => 'aaaaaaaaa!'];
        yield 'ten lazy optional letters' => ['pattern' => '/^(a??){0,10}$/', 'subject' => 'aaaaaaaaaa'];
        yield 'an optional letter behind a word boundary, ten times' => ['pattern' => '/^(?:\ba?){0,10}$/', 'subject' => 'a!'];
        yield 'eleven runs a letter starts, each read two ways' => ['pattern' => '/^(?:xa?a){0,11}$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxax'];
        yield 'eleven runs whose branches start apart, each read two ways' => ['pattern' => '/^(?:(?:a|b)c?c){0,11}$/', 'subject' => 'bccaccaccaccbccbccaccbccbccbccacb!bcab'];
        // What follows the loop is read through the branches and the groups
        // around it, up to an item that reads a character: "b" there tells
        // the runs, which start with "x", apart.
        yield 'eleven runs in a branch' => ['pattern' => '/^(?:(?:xa?a){0,11}|y)$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxaxaa'];
        yield 'eleven runs in a capturing group' => ['pattern' => '/^((?:xa?a){0,11})$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxax'];
        yield 'ten runs in an optional group' => ['pattern' => '/^(?:(?:xa?a){0,10})?$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxax'];
        yield 'eleven runs before a letter, then the one they start with' => ['pattern' => '/^(?:xa?a){0,11}bx/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxaa'];
        yield 'eleven runs with a comment' => ['pattern' => '/^(?:x(?#c)a?a){0,11}$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxax'];
        yield 'ten runs with an empty branch' => ['pattern' => '/^(?:x(?:|y)a?a){0,10}$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxyax'];
        // An option setting reads no character: "b" follows "a?".
        yield 'eleven runs with an option setting' => ['pattern' => '/^(?:xc?c(?s)a?b){0,11}$/', 'subject' => 'xcbxcbxcbxcbxcbxcbxcb!!acxxc'];
        yield 'nine optional letters in an optional group' => ['pattern' => '/^(?:(a?){0,9}b?b)?$/', 'subject' => 'aaaaaab!bbbb!babb!!b!!b!!ab!bab!!bbbb!a'];
        // Every attempt starts at one place: the A modifier, "\A", "\G", a
        // "^" in a group, or in every branch.
        yield 'ten optional letters under the A modifier' => ['pattern' => '/(a?){0,10}(?:b|c)/A', 'subject' => 'aaaaaaaaa'];
        yield 'ten optional letters after \A' => ['pattern' => '/\A(a?){0,10}(?:b|c)/', 'subject' => 'aaaaaaaaa'];
        yield 'ten optional letters after \G' => ['pattern' => '/\G(a?){0,10}(?:b|c)/', 'subject' => 'aaaaaaaaa'];
        yield 'ten optional letters in a group the caret opens' => ['pattern' => '/(^(a?){0,10}$)/', 'subject' => 'aaaaaaaaa!'];
        yield 'ten optional letters in a branch the caret opens, beside another' => ['pattern' => '/^(a?){0,10}$|^b/', 'subject' => 'aaaaaaaaa!'];
        // What ends the pattern and cannot fail is tried once, the first way
        // through it that matches being the match: its ways add to those of
        // what comes before it.
        yield 'nine optional letters ending the pattern after a letter' => ['pattern' => '/^(a?){0,4}x(a?){0,9}/', 'subject' => 'aaa'];
        yield 'nine optional letters in a group ending the pattern' => ['pattern' => '/^(a?){0,4}x((a?){0,9})/', 'subject' => 'aaa'];
        yield 'four optional letters twice, ending the pattern' => ['pattern' => '/^(a?){0,4}x(?:(a?){0,4}){2}/', 'subject' => 'aaa'];
        yield 'nine optional letters in a branch beside one that may fail' => ['pattern' => '/^(a?){0,4}x(?:b|(a?){0,9})/', 'subject' => 'aaa'];
        yield 'nine optional letters before a comment ending the pattern' => ['pattern' => '/^(a?){0,4}x(a?){0,9}(?#c)/', 'subject' => 'aaa'];
        yield 'nine optional letters in a branch beside an empty one' => ['pattern' => '/^(a?){0,4}x(?:b(a?){0,9}|)/', 'subject' => 'aaa'];
        yield 'nine optional letters before an optional choice ending the pattern' => ['pattern' => '/^(a?){0,9}(?:b|bc|)(a?){0,4}/', 'subject' => ''];
        // The last item that may fail ends the pattern, and so do its own
        // last items.
        yield 'nine optional letters twice, ending a group that ends the pattern' => ['pattern' => '/^x(x(a?){0,9}(a?){0,9})/', 'subject' => 'xxaaaaaaaaaaaaaaaaax!xxxaxxxxx!xxa!x!!'];
        yield 'nine optional letters twice, ending a branch that ends the pattern' => ['pattern' => '/^(?:x(a?){0,9}(a?){0,9}|y)/', 'subject' => 'xaaaaaaaaaaaaaaaaay!!a!a!yayyxaxax!!axx'];
        yield 'nine optional letters twice, ending the first of two branches the caret opens' => ['pattern' => '/^x(a?){0,9}(a?){0,9}|^y/', 'subject' => 'xaaaaaaaaaaaaaaaaay!!a!a!yayyxaxax!!axx'];
    }

    #[Test]
    #[DataProvider('provideLoopsReadInManyWays')]
    public function test_a_counted_loop_read_in_many_ways_is_reported(string $pattern, string $subject): void
    {
        $outcome = PcreAttemptSteps::searchUnderLimit(self::counted($pattern), $subject, self::FEW_STEPS);
        $this->assertFalse($outcome, \sprintf('Oracle: %s takes %d steps or more on "%s".', $pattern, self::FEW_STEPS, $subject));

        $this->assertContains(NestedQuantifierRule::ID, self::lint($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideLoopsReadInManyWays(): iterable
    {
        yield 'six runs of up to six letters' => ['pattern' => '/^(a{0,6}){0,6}$/', 'subject' => str_repeat('a', 30).'!'];
        yield 'eight runs of up to eight letters, then a letter' => ['pattern' => '/^(a{0,8}){0,8}b/', 'subject' => str_repeat('a', 17)];
        yield 'sixteen runs of up to sixteen letters' => ['pattern' => '/^(a{0,16}){0,16}$/', 'subject' => 'aaaaaaa!'];
        yield 'four runs of six optional letters' => ['pattern' => '/^(a?a?a?a?a?a?){0,4}$/', 'subject' => 'aaaaaaaa!'];
        yield 'an unbounded run, four times' => ['pattern' => '/^(a+){0,4}$/', 'subject' => str_repeat('a', 40).'!'];
        // The whole pattern is counted: each of these loops alone is read in
        // 2,047 ways, but what comes before it enters it again, or what comes
        // after it tries it again.
        yield 'ten optional letters, twice in a row' => ['pattern' => '/^(a?){0,10}(a?){0,10}$/', 'subject' => 'aaaaaaa!'];
        yield 'ten optional letters after a run of word characters' => ['pattern' => '/^\w*(a?){0,10}$/', 'subject' => str_repeat('a', 40).'!'];
        yield 'a loop of few ways run again by a loop around it' => ['pattern' => '/^(?:(?>(?:c?.?){0,4}a)(?:\d|)){0,12}b/', 'subject' => 'c1ca1cbxxa1a1ccc1ca1ca11ccca1ca1ccccccc!'];
    }

    /**
     * A search that may start anywhere pays the attempt at every offset.
     */
    #[Test]
    #[DataProvider('provideLoopsASearchMayStartAnywhere')]
    public function test_a_counted_loop_a_search_may_start_anywhere_in_is_reported_past_a_few_steps_a_start(string $pattern, string $subject, bool $reported): void
    {
        $steps = PcreAttemptSteps::steps(self::counted($pattern).'A', $subject);
        $this->assertSame($reported, $steps > self::FEW_STEPS_A_START, \sprintf('Oracle: %s takes %d steps from the start of "%s".', $pattern, $steps, $subject));

        $this->assertSame($reported, \in_array(NestedQuantifierRule::ID, self::lint($pattern), true), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, reported: bool}>
     */
    public static function provideLoopsASearchMayStartAnywhere(): iterable
    {
        yield 'two optional letters' => ['pattern' => '/(a?){0,2}(?:b|c)/', 'subject' => 'a', 'reported' => false];
        yield 'two optional letters after a caret under m' => ['pattern' => '/^(a?){0,2}(?:b|c)/m', 'subject' => 'a', 'reported' => false];
        yield 'three optional letters' => ['pattern' => '/(a?){0,3}(?:b|c)/', 'subject' => 'aa', 'reported' => true];
        yield 'four optional letters after a caret under m' => ['pattern' => '/^(a?){0,4}(?:b|c)/m', 'subject' => 'aaa', 'reported' => true];
        yield 'ten optional letters' => ['pattern' => '/(a?){0,10}(?:b|c)/', 'subject' => 'aaaaaaaaa', 'reported' => true];
    }

    /**
     * The count of the ways decides, not the cost: a pattern read in more
     * ways than the bound is reported however little the engine spends on
     * it, and so is a pattern whose ways cannot be counted.
     *
     * @param list<string> $rules the rules of this kind that report the pattern
     */
    #[Test]
    #[DataProvider('provideCounts')]
    public function test_the_ways_of_a_counted_loop_are_counted_from_the_pattern(string $pattern, array $rules): void
    {
        $this->assertSame($rules, array_values(array_intersect([NestedQuantifierRule::ID], self::lint($pattern))), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, rules: list<string>}>
     */
    public static function provideCounts(): iterable
    {
        $nested = [NestedQuantifierRule::ID];

        // "a?" before "a" may stop or go on: two ways a run, 2,048 for eleven.
        yield 'eleven runs read two ways each' => ['pattern' => '/^(?:xa?a){0,11}$/', 'rules' => []];
        yield 'twelve runs read two ways each' => ['pattern' => '/^(?:xa?a){0,12}$/', 'rules' => $nested];
        // 2,048 ways and one more, the branches starting alike.
        yield 'eleven runs beside a branch starting alike' => ['pattern' => '/^(?:(?:xa?a){0,11}|x)$/', 'rules' => $nested];
        // Branches that start alike are each tried: four ways a run.
        yield 'eleven runs whose branches start alike' => ['pattern' => '/^(?:(?:a|ab)c?c){0,11}$/', 'rules' => $nested];
        yield 'ten optional letters' => ['pattern' => '/^(a?){0,10}$/', 'rules' => []];
        yield 'eleven optional letters' => ['pattern' => '/^(a?){0,11}$/', 'rules' => $nested];
        yield 'five runs of up to five letters' => ['pattern' => '/^(a{0,5}){0,5}$/', 'rules' => $nested];
        yield 'ten runs in an optional group' => ['pattern' => '/^(?:(?:xa?a){0,10})?$/', 'rules' => []];
        yield 'eleven runs in an optional group' => ['pattern' => '/^(?:(?:xa?a){0,11})?$/', 'rules' => $nested];
        // What follows the loop may start a run: each count may stop it.
        yield 'eleven runs before the letter they start with' => ['pattern' => '/^(?:xa?a){0,11}x/', 'rules' => $nested];
        yield 'eleven runs before an optional letter they start with' => ['pattern' => '/^(?:xa?a){0,11}x?b$/', 'rules' => $nested];
        // Branches told apart count as the widest: four ways a run.
        yield 'six runs whose branches start apart, the widest read four ways' => ['pattern' => '/^(?:x(?:a|bc?cc?c)){0,6}$/', 'rules' => $nested];
        // What follows a repeat that may match nothing is read past it:
        // "a?" may be followed by "a" where "c?" matches nothing.
        yield 'twelve runs with two optional letters' => ['pattern' => '/^(?:xa?c?a){0,12}$/', 'rules' => $nested];
        // What follows the body of a lookahead is not known.
        yield 'eleven runs with a lookahead' => ['pattern' => '/^(?:x(?=ba?)bc?c){0,11}$/', 'rules' => $nested];
        // What follows a lookahead the engine backtracks into is not known.
        yield 'eleven runs ending a non-atomic lookahead' => ['pattern' => '/^(*napla:(?:xa?a){0,11})b/', 'rules' => $nested];
        // A search that may start anywhere: 7 ways at most.
        yield 'two optional letters, read in 7 ways' => ['pattern' => '/(a?){0,2}(?:b|c)/', 'rules' => []];
        yield 'three runs read two ways each, 8 ways' => ['pattern' => '/(?:xa?a){0,3}(?:b|c)/', 'rules' => $nested];
        yield 'two runs of up to two letters, read in 13 ways' => ['pattern' => '/(a{0,2}){0,2}(?:b|c)/', 'rules' => $nested];
        yield 'ten optional letters in a branch the caret opens, beside one it does not' => ['pattern' => '/^(a?){0,10}b|c/', 'rules' => $nested];
        yield 'ten optional letters after a caret that does not open the pattern' => ['pattern' => '/x^(a?){0,10}b/', 'rules' => $nested];
        yield 'ten optional letters after a caret in a lookahead' => ['pattern' => '/(?=^)(a?){0,10}b/', 'rules' => $nested];
        yield 'ten optional letters after a dollar' => ['pattern' => '/$(a?){0,10}(?:b|c)/', 'rules' => $nested];
        // What ends the pattern is read once only where it cannot fail, and
        // only through the groups that wrap it: a lookahead that always
        // fails may.
        yield 'nine optional letters twice, ending an atomic group' => ['pattern' => '/^(?>x(a?){0,9}(a?){0,9})/', 'rules' => $nested];
        yield 'nine optional letters before a lookahead that always fails' => ['pattern' => '/^(a?){0,9}(?!b?b?)/', 'rules' => $nested];
        yield 'nine optional letters before a group that may fail' => ['pattern' => '/^(a?){0,9}(?:bc?c(a?){0,4})/', 'rules' => $nested];
        // Items that end the pattern and cannot fail still add their ways.
        yield 'ten optional letters, twice, ending the pattern' => ['pattern' => '/^(a?){0,10}(a?){0,10}/', 'rules' => $nested];
        // Not counted: a reference, an unbounded repeat, a subroutine call.
        yield 'a reference in the loop' => ['pattern' => '/^((a?)\2){0,4}$/', 'rules' => $nested];
        yield 'an unbounded repeat in the loop' => ['pattern' => '/^(a+){0,4}$/', 'rules' => $nested];
        yield 'a loop a subroutine call runs again' => ['pattern' => '/^((a?){0,4})(?1)$/', 'rules' => $nested];
    }

    /**
     * The pattern counted by the interpreter with its start optimizations
     * off, as the measures above were taken.
     */
    private static function counted(string $pattern): string
    {
        return $pattern[0].'(*NO_JIT)(*NO_START_OPT)'.substr($pattern, 1);
    }

    /**
     * @return list<string> the identifiers of the issues the linter reports
     */
    private static function lint(string $pattern): array
    {
        $regex = Regex::create(['cache' => null]);
        $linter = new PatternLinter($regex->target());
        $regex->parse($pattern)->accept($linter);

        return array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
    }
}

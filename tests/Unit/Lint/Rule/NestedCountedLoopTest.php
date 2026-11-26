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
 * A loop counted with every repeat inside it takes a bounded number of
 * characters, and the engine reads it in a bounded number of ways: however
 * long the subject, one attempt costs what the pattern alone decides. Where
 * those ways are few, 2,048 at most, the nested-loop rule keeps quiet.
 *
 * The steps of one attempt are the smallest pcre.backtrack_limit under
 * which preg_match() finishes, the interpreter running with its start
 * optimizations off; each subject is the costliest a search found on PCRE2
 * 10.49 (the runs of each character, then random edits from the costliest
 * of them). The loops the rule keeps quiet about stay far under 10,000
 * steps, a hundredth of PHP's default pcre.backtrack_limit: 73 for the
 * costliest of mpdf's three syllable patterns, 438 for "/^(a{0,4}){0,4}$/",
 * 2,559 for "/^(a?){0,10}$/", 4,094 for its lazy twin. The ones it reports
 * go past it, or past the limit itself.
 */
final class NestedCountedLoopTest extends TestCase
{
    /**
     * The steps under which one attempt of a loop the rule keeps quiet
     * about stays, and over which one it reports goes.
     */
    private const FEW_STEPS = 10_000;

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
        // What follows the loop is read through the branches, the groups
        // and the optional groups around it, up to an item that reads a
        // character: "b" there tells the runs, which start with "x", apart.
        yield 'eleven runs in a branch' => ['pattern' => '/^(?:(?:xa?a){0,11}|y)$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxaxaa'];
        yield 'eleven runs in a capturing group' => ['pattern' => '/^((?:xa?a){0,11})$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxax'];
        yield 'eleven runs in an optional group' => ['pattern' => '/^(?:(?:xa?a){0,11})?$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxax'];
        yield 'eleven runs before a letter, then the one they start with' => ['pattern' => '/^(?:xa?a){0,11}bx/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxaa'];
        yield 'eleven runs with a comment' => ['pattern' => '/^(?:x(?#c)a?a){0,11}$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxaaxax'];
        yield 'ten runs with an empty branch' => ['pattern' => '/^(?:x(?:|y)a?a){0,10}$/', 'subject' => 'xaaxaaxaaxaaxaaxaaxaaxaaxaaxyax'];
        // An option setting reads no character: "b" follows "a?".
        yield 'eleven runs with an option setting' => ['pattern' => '/^(?:xc?c(?s)a?b){0,11}$/', 'subject' => 'xcbxcbxcbxcbxcbxcbxcb!!acxxc'];
        // An optional group runs the loop once: its own ways count.
        yield 'ten optional letters in an optional group' => ['pattern' => '/^(?:(a?){0,10}b?b)?$/', 'subject' => 'aaaaaab!bbbb!babb!!b!!b!!ab!bab!!bbbb!a'];
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
        // The inner loop is read in 341 ways each time it runs, and the
        // loop around it runs it again: the ways of the outer one count.
        yield 'a loop of few ways run again by a loop around it' => ['pattern' => '/^(?:(?>(?:c?.?){0,4}a)(?:\d|)){0,12}b/', 'subject' => 'c1ca1cbxxa1a1ccc1ca1ca11ccca1ca1ccccccc!'];
    }

    /**
     * The count of the ways decides, not the cost: a loop read in more
     * than 2,048 ways is reported however little the engine spends on it,
     * and so is a loop whose ways cannot be counted.
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
        // Branches that start alike are each tried: four ways a run.
        yield 'eleven runs whose branches start alike' => ['pattern' => '/^(?:(?:a|ab)c?c){0,11}$/', 'rules' => $nested];
        yield 'ten optional letters' => ['pattern' => '/^(a?){0,10}$/', 'rules' => []];
        yield 'eleven optional letters' => ['pattern' => '/^(a?){0,11}$/', 'rules' => $nested];
        yield 'five runs of up to five letters' => ['pattern' => '/^(a{0,5}){0,5}$/', 'rules' => $nested];
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
        // Not counted: a reference, an unbounded repeat, a loop a
        // subroutine call runs again.
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

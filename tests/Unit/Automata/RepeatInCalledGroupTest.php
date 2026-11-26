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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a repeat possessive by what it compiles around it: whether
 * the repeat is written as a group, the brackets after it or around it,
 * whether a class is compiled as a class or as "\d". Inside a group a call
 * runs, its JIT then misses matches its interpreter finds, and in a pattern
 * that calls itself whole the interpreter too, from PCRE2 10.44. The match
 * solver reads neither: it proves no rewrite of a pattern that repeats
 * something but a call some number of times inside a group a call runs.
 */
final class RepeatInCalledGroupTest extends TestCase
{
    use PcreGates;

    /**
     * @param string $subject one the two tell apart with the JIT
     */
    #[Test]
    #[DataProvider('provideRewritesOfARepeatWhereACallRuns')]
    public function test_a_rewrite_of_a_repeat_where_a_call_runs_is_never_proven(string $pattern, string $rewrite, string $subject): void
    {
        $this->requiresJit($rewrite);

        // Oracle, PCRE2 10.39, 10.40, 10.42, 10.44 and 10.49: one finds a
        // match the other misses, or another one, with the JIT.
        $this->assertNotSame(JitInAFreshProcess::matches($pattern, $subject), JitInAFreshProcess::matches($rewrite, $subject), \sprintf('Oracle, JIT: %s and %s write the same on "%s".', $pattern, $rewrite, $subject));

        $solver = new LanguageSolver();
        $this->assertNull($solver->runsOfEveryCall($pattern, $rewrite), 'No run proves the pair.');

        // A pattern that calls itself whole is refused for the call first.
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageMatches('/^(?:A rewrite of what a pattern repeats some number of times where a call runs is not proven|A call that may run its own group again)/');
        $solver->matchEquivalent($pattern, $rewrite);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, subject: string}>
     */
    public static function provideRewritesOfARepeatWhereACallRuns(): iterable
    {
        yield 'a repeat written as a group' => ['pattern' => '/((?:a)+b)c(?1)d/', 'rewrite' => '/(a+b)c(?1)d/', 'subject' => 'abcabcabd'];
        yield 'a group after a repeat' => ['pattern' => '/(a+(?:b))c(?1)d/', 'rewrite' => '/(a+b)c(?1)d/', 'subject' => 'abcabcabd'];
        yield 'a group around a repeat' => ['pattern' => '/((?:a+)b)c(?1)d/', 'rewrite' => '/(a+b)c(?1)d/', 'subject' => 'abcabcabd'];
        yield 'an empty group after a repeat' => ['pattern' => '/(a+(?:)c)x(?1)d/', 'rewrite' => '/(a+c)x(?1)d/', 'subject' => 'acxacxacd'];
        yield 'an alternation after a repeat written as a class' => ['pattern' => '/(a+(?:b|c))x(?1)d/', 'rewrite' => '/(a+[bc])x(?1)d/', 'subject' => 'acxacxacd'];
        yield 'a class of digits written as \d' => ['pattern' => '/([0-9]+b)c(?1)d/', 'rewrite' => '/(\d+b)c(?1)d/', 'subject' => '1bc1bc1bd'];
        yield 'a repeat called by name' => ['pattern' => '/(?<n>(?:a)+b)c(?&n)d/', 'rewrite' => '/(?<n>a+b)c(?&n)d/', 'subject' => 'abcabcabd'];
        yield 'a repeat written as a group in a pattern that calls itself whole' => ['pattern' => '/x(?R)a|(?:a)*/', 'rewrite' => '/x(?R)a|a*/', 'subject' => 'xaa'];
        yield 'a group written out where the rewrite calls it' => ['pattern' => '/(a+b)c(a+b)d/', 'rewrite' => '/(a+b)c(?1)d/', 'subject' => 'abcabcabd'];
        yield 'a call written out' => ['pattern' => '/(a+b)c(?1)d/', 'rewrite' => '/(a+b)c(a+b)d/', 'subject' => 'abcabcabd'];
    }

    /**
     * In a pattern that calls itself whole, the interpreter, which the
     * match solver reads, makes "a*" possessive at the end of the pattern
     * from PCRE2 10.44, as if no call could run on after it.
     */
    #[Test]
    public function test_the_interpreter_reads_apart_a_repeat_written_as_a_group_where_the_pattern_calls_itself(): void
    {
        $this->requiresPcre2('10.44', 'the interpreter making "a*" possessive at the end of a pattern that calls itself');

        // Oracle, PCRE2 10.44 and 10.49: "(?:a)*" finds "xaa", "a*" the
        // empty string; 10.39 to 10.42 find the empty string with both.
        $this->assertSame([1, 1], [preg_match(PcreInterpreter::pattern('/x(?R)a|(?:a)*/'), 'xaa', $group), preg_match(PcreInterpreter::pattern('/x(?R)a|a*/'), 'xaa', $bare)]);
        $this->assertSame(['xaa', ''], [$group[0], $bare[0]]);

        $this->assertNull((new LanguageSolver())->runsOfEveryCall('/x(?R)a|(?:a)*/', '/x(?R)a|a*/'));
    }

    /**
     * A repeat outside every group a call runs, one counted to a single
     * number, and a repeat of the call itself are read as before, and the
     * same pattern on both sides runs alike.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideRewritesProvenBesideACall')]
    public function test_a_rewrite_beside_a_call_is_proven_where_no_repeat_runs_some_number_of_times_inside_it(string $pattern, string $rewrite, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $this->assertSame(self::matchesOf($pattern, $subject), self::matchesOf($rewrite, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $rewrite, $subject));
            $this->assertSame(self::matchesOf(PcreInterpreter::pattern($pattern), $subject), self::matchesOf(PcreInterpreter::pattern($rewrite), $subject), \sprintf('Oracle, interpreter: %s and %s differ on "%s".', $pattern, $rewrite, $subject));
        }
        $this->requiresJit($rewrite);
        $this->assertSame(JitInAFreshProcess::matches($pattern, $subjects[0]), JitInAFreshProcess::matches($rewrite, $subjects[0]), \sprintf('Oracle, JIT: %s and %s differ on "%s".', $pattern, $rewrite, $subjects[0]));

        $solver = new LanguageSolver();
        $this->assertNotNull($solver->runsOfEveryCall($pattern, $rewrite));
        $this->assertTrue($solver->matchEquivalent($pattern, $rewrite)->isEquivalent);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, subjects: non-empty-list<string>}>
     */
    public static function provideRewritesProvenBesideACall(): iterable
    {
        yield 'a repeat written as a group, outside the group a call runs' => ['pattern' => '/(?:a)+b(?1)(c)/', 'rewrite' => '/a+b(?1)(c)/', 'subjects' => ['abcc', 'aabcc', 'bcc', 'abc', 'xaabccd', '']];
        yield 'a group counted to one number, in the group a call runs' => ['pattern' => '/((?:a){2}b)c(?1)d/', 'rewrite' => '/(a{2}b)c(?1)d/', 'subjects' => ['aabcaabcaabd', 'aabcaabd', 'abcabd', 'aabcabd', '']];
        yield 'a call repeated in the group it runs' => ['pattern' => '/(x(?1)?y)(?:a)+b/', 'rewrite' => '/(x(?1)?y)a+b/', 'subjects' => ['xyaab', 'xxyyab', 'xyb', 'xxyab', '']];
        yield 'the same pattern on both sides' => ['pattern' => '/(a+b)c(?1)d/', 'rewrite' => '/(a+b)c(?1)d/', 'subjects' => ['abcabcabd', 'abcabd', '']];
    }

    /**
     * @return array<array-key, mixed>|false
     */
    private static function matchesOf(string $pattern, string $subject): array|false
    {
        $matches = [];

        return false === preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL) ? false : $matches;
    }
}

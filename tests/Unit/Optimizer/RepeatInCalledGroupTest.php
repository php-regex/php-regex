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
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a repeat possessive by how the pattern writes what stands
 * around it, and inside a group a call runs its JIT then misses matches its
 * interpreter finds: "/(a+b)c(?1)d/" finds nothing in "abcabcabd" with the
 * JIT, where "/((?:a)+b)c(?1)d/" finds "abcabd". A group a call runs that
 * repeats something some number of times, or the whole pattern where it
 * calls itself whole, is rewritten nowhere; the rest of the pattern is.
 */
final class RepeatInCalledGroupTest extends TestCase
{
    use PcreGates;

    /**
     * @param string $subject one the pattern and the rewrite once offered tell apart with the JIT
     */
    #[Test]
    #[DataProvider('provideRepeatsWhereACallRuns')]
    public function test_a_group_a_call_runs_that_repeats_is_not_rewritten(string $pattern, string $former, string $subject): void
    {
        $this->requiresJit($former);

        // Oracle, PCRE2 10.39, 10.40, 10.42, 10.44 and 10.49.
        $this->assertSame(1, JitInAFreshProcess::matched($pattern, $subject), \sprintf('Oracle, JIT: %s finds nothing in "%s".', $pattern, $subject));
        $this->assertNotSame(JitInAFreshProcess::matches($pattern, $subject), JitInAFreshProcess::matches($former, $subject), \sprintf('Oracle, JIT: %s and %s write the same on "%s".', $pattern, $former, $subject));

        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));
        foreach ([new OptimizerOptions(), new OptimizerOptions(verifyWithAutomata: false)] as $options) {
            $result = $optimizer->optimize($pattern, $options);

            $this->assertSame($pattern, $result->optimized);
            $this->assertSame([], $result->changes);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, former: string, subject: string}>
     */
    public static function provideRepeatsWhereACallRuns(): iterable
    {
        yield 'a repeat written as a group' => ['pattern' => '/((?:a)+b)c(?1)d/', 'former' => '/(a+b)c(?1)d/', 'subject' => 'abcabcabd'];
        yield 'a group after a repeat' => ['pattern' => '/(a+(?:b))c(?1)d/', 'former' => '/(a+b)c(?1)d/', 'subject' => 'abcabcabd'];
        yield 'an alternation of letters after a repeat' => ['pattern' => '/(a+(?:b|c))x(?1)d/', 'former' => '/(a+[bc])x(?1)d/', 'subject' => 'acxacxacd'];
        yield 'a class of digits' => ['pattern' => '/([0-9]+b)c(?1)d/', 'former' => '/(\d+b)c(?1)d/', 'subject' => '1bc1bc1bd'];
        yield 'a repeat written as a group in a pattern that calls itself whole' => ['pattern' => '/x(?R)a|(?:a)*/', 'former' => '/x(?R)a|a*/', 'subject' => 'xaa'];
    }

    /**
     * What stands outside every group a call runs is rewritten as before,
     * whatever those groups repeat.
     *
     * @param non-empty-list<string> $subjects
     * @param list<string>           $changes
     */
    #[Test]
    #[DataProvider('provideRewritesOutsideTheGroupsACallRuns')]
    public function test_what_stands_outside_the_groups_a_call_runs_is_rewritten(string $pattern, string $optimized, array $subjects, array $changes): void
    {
        // Oracle, PCRE2 10.49.
        foreach ($subjects as $subject) {
            $this->assertSame(self::matchesOf($pattern, $subject), self::matchesOf($optimized, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $optimized, $subject));
            $this->assertSame(self::matchesOf(PcreInterpreter::pattern($pattern), $subject), self::matchesOf(PcreInterpreter::pattern($optimized), $subject), \sprintf('Oracle, interpreter: %s and %s differ on "%s".', $pattern, $optimized, $subject));
        }
        $this->requiresJit($optimized);
        $this->assertSame(JitInAFreshProcess::matches($pattern, $subjects[0]), JitInAFreshProcess::matches($optimized, $subjects[0]), \sprintf('Oracle, JIT: %s and %s differ on "%s".', $pattern, $optimized, $subjects[0]));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern);

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string, subjects: non-empty-list<string>, changes: list<string>}>
     */
    public static function provideRewritesOutsideTheGroupsACallRuns(): iterable
    {
        yield 'a repeat written as a group before a call' => ['pattern' => '/(?:a)+b(?1)(c)/', 'optimized' => '/a+b(?1)(c)/', 'subjects' => ['abcc', 'aabcc', 'bcc', 'abc', ''], 'changes' => ['Removed a group that changes nothing.']];
        yield 'the same before a group a call runs that repeats' => ['pattern' => '/(?:a)+b(?1)(c+d)/', 'optimized' => '/a+b(?1)(c+d)/', 'subjects' => ['abcdcd', 'aabccdcd', 'bcdccd', 'abcd', ''], 'changes' => ['Removed a group that changes nothing.']];
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

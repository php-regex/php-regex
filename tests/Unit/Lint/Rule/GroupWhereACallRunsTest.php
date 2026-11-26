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

use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a repeat possessive by the brackets the pattern writes around
 * it, and inside a group a call runs its JIT then misses matches its
 * interpreter finds: "/(a+b)c(?1)d/" finds nothing in "abcabcabd" with the
 * JIT, where "/((?:a)+b)c(?1)d/" finds "abcabd". A group inside a group a
 * call runs that repeats something some number of times, or anywhere in a
 * pattern that calls itself whole, is neither redundant nor empty.
 */
final class GroupWhereACallRunsTest extends TestCase
{
    use PcreGates;

    private const REDUNDANT = 'regex.lint.group.redundant';

    private const EMPTY = 'regex.lint.group.empty';

    /**
     * @param string $subject one the two tell apart with the JIT
     */
    #[Test]
    #[DataProvider('provideGroupsWhereACallRunsARepeat')]
    public function test_a_group_where_a_call_runs_a_repeat_is_not_reported(string $pattern, string $group, string $without, string $id, string $subject): void
    {
        $this->requiresJit($without);

        // Oracle, PCRE2 10.39, 10.40, 10.42, 10.44 and 10.49.
        $this->assertSame(1, JitInAFreshProcess::matched($pattern, $subject), \sprintf('Oracle, JIT: %s finds nothing in "%s".', $pattern, $subject));
        $this->assertNotSame(JitInAFreshProcess::matches($pattern, $subject), JitInAFreshProcess::matches($without, $subject), \sprintf('Oracle, JIT: %s and %s write the same on "%s".', $pattern, $without, $subject));

        $this->assertNotContains($group, self::reported($pattern, $id));
    }

    /**
     * @return iterable<string, array{pattern: string, group: string, without: string, id: string, subject: string}>
     */
    public static function provideGroupsWhereACallRunsARepeat(): iterable
    {
        yield 'a repeat written as a group' => ['pattern' => '/((?:a)+b)c(?1)d/', 'group' => '(?:a)', 'without' => '/(a+b)c(?1)d/', 'id' => self::REDUNDANT, 'subject' => 'abcabcabd'];
        yield 'a group after a repeat' => ['pattern' => '/(a+(?:b))c(?1)d/', 'group' => '(?:b)', 'without' => '/(a+b)c(?1)d/', 'id' => self::REDUNDANT, 'subject' => 'abcabcabd'];
        yield 'a group around a repeat' => ['pattern' => '/((?:a+)b)c(?1)d/', 'group' => '(?:a+)', 'without' => '/(a+b)c(?1)d/', 'id' => self::REDUNDANT, 'subject' => 'abcabcabd'];
        yield 'a repeat called by name' => ['pattern' => '/(?<n>(?:a)+b)c(?&n)d/', 'group' => '(?:a)', 'without' => '/(?<n>a+b)c(?&n)d/', 'id' => self::REDUNDANT, 'subject' => 'abcabcabd'];
        yield 'a repeat written as a group in a pattern that calls itself whole' => ['pattern' => '/x(?R)a|(?:a)*/', 'group' => '(?:a)', 'without' => '/x(?R)a|a*/', 'id' => self::REDUNDANT, 'subject' => 'xaa'];
        yield 'an empty group after a repeat' => ['pattern' => '/(a+(?:)c)x(?1)d/', 'group' => '(?:)', 'without' => '/(a+c)x(?1)d/', 'id' => self::EMPTY, 'subject' => 'acxacxacd'];
    }

    /**
     * A group outside every group a call runs, or in one that repeats
     * nothing some number of times, is reported as before.
     *
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideGroupsBesideACall')]
    public function test_a_group_beside_a_call_is_reported_where_no_repeat_runs_some_number_of_times_inside_it(string $pattern, string $group, string $without, string $id, array $subjects): void
    {
        // Oracle, PCRE2 10.49.
        foreach ($subjects as $subject) {
            $this->assertSame(self::matchesOf($pattern, $subject), self::matchesOf($without, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $without, $subject));
            $this->assertSame(self::matchesOf(PcreInterpreter::pattern($pattern), $subject), self::matchesOf(PcreInterpreter::pattern($without), $subject), \sprintf('Oracle, interpreter: %s and %s differ on "%s".', $pattern, $without, $subject));
        }
        $this->requiresJit($without);
        $this->assertSame(JitInAFreshProcess::matches($pattern, $subjects[0]), JitInAFreshProcess::matches($without, $subjects[0]), \sprintf('Oracle, JIT: %s and %s differ on "%s".', $pattern, $without, $subjects[0]));

        $this->assertContains($group, self::reported($pattern, $id));
    }

    /**
     * @return iterable<string, array{pattern: string, group: string, without: string, id: string, subjects: non-empty-list<string>}>
     */
    public static function provideGroupsBesideACall(): iterable
    {
        yield 'a repeat written as a group before a call' => ['pattern' => '/(?:a)+b(?1)(c)/', 'group' => '(?:a)', 'without' => '/a+b(?1)(c)/', 'id' => self::REDUNDANT, 'subjects' => ['abcc', 'aabcc', 'bcc', 'abc', '']];
        yield 'the same before a group a call runs that repeats' => ['pattern' => '/(?:a)+b(?1)(c+d)/', 'group' => '(?:a)', 'without' => '/a+b(?1)(c+d)/', 'id' => self::REDUNDANT, 'subjects' => ['abcdcd', 'aabccdcd', 'bcdccd', 'abcd', '']];
        yield 'a group counted to one number in the group a call runs' => ['pattern' => '/((?:a){2}b)c(?1)d/', 'group' => '(?:a)', 'without' => '/(a{2}b)c(?1)d/', 'id' => self::REDUNDANT, 'subjects' => ['aabcaabcaabd', 'aabcaabd', 'abcabd', '']];
        yield 'an empty group before a call' => ['pattern' => '/a+(?:)c(?1)(x)/', 'group' => '(?:)', 'without' => '/a+c(?1)(x)/', 'id' => self::EMPTY, 'subjects' => ['acxx', 'aacxx', 'cxx', 'acx', '']];
    }

    /**
     * The groups the rule reports in the pattern, as each message quotes
     * them.
     *
     * @return list<string>
     */
    private static function reported(string $pattern, string $id): array
    {
        $groups = [];
        foreach (Regex::create(['cache' => null])->lint($pattern) as $violation) {
            if ($id === $violation->id && 1 === preg_match('/^(?:Redundant non-capturing group|Empty group) "(.+)"/', $violation->message, $quoted)) {
                $groups[] = $quoted[1];
            }
        }

        return $groups;
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

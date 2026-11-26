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
 * PCRE2 makes a character repeated before an atomic group, or a possessive
 * repeat of a group, that may match nothing possessive, as if the group
 * could not be skipped, and the brackets decide which. It judges the repeat
 * against what stands after it, no further than the first item that must
 * take a character: the brackets it reads on the way stay, a group it never
 * reaches goes as anywhere else.
 */
final class RedundantGroupBesideARepeatTest extends TestCase
{
    use PcreGates;

    private const ID = 'regex.lint.group.redundant';

    private const LETTERS = ['', 'a', 'aa', 'aaa', 'ab', 'aba', 'aab', 'aaba', 'ax', 'axa', 'aaxa', 'axba', 'aaxba', 'aca', 'aaca', 'acba', 'ac', 'aac', 'b', 'ba', 'baa', 'baba', 'abab', 'c', 'x', 'xaa', 'aaaa', 'ababa', 'baab'];

    /**
     * The pattern PHPStan reads the symbols of a file with, an "enum" among
     * its types.
     */
    private const SYMBOLS = <<<'REGEX'
        {
                    (?:
                        \b(?<![\$:>])(?:
                            (?: (?P<type>class|interface|trait|enum) \s++ (?P<name>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\-]*+) )
                            | (?: (?P<function>function) \s++ (?:&\s*)? (?P<fname>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\-]*+) \s*+ [&\(] )
                            | (?: (?P<constant>const) \s++ (?P<cname>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\-]*+) \s*+ [^;] )
                            | (?: (?:\\)? (?P<define>define) \s*+ \( \s*+ ['"] (?P<dname>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+(?:[\\]{1,2}[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)*+) )
                            | (?: (?P<ns>namespace) (?P<nsname>\s++[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+(?:\s*+\\\s*+[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)*+)? \s*+ [\{;] )
                        )
                    )
                }ix
        REGEX;

    /**
     * The pattern WordPress-Coding-Standards reads an unsupported
     * placeholder of a prepared SQL statement with.
     */
    private const PLACEHOLDER = <<<'REGEX'
        `(?:
                (?<!%)                     # Don't match a literal % (%%).
                (
                    %                          # Start of placeholder.
                    (?!                        # Negative look ahead.
                        %[^%]                       # Not a correct literal % (%%).
                        |
                        %%[dfFsi]                   # Nor a correct literal % (%%), followed by a simple placeholder.
                    )
                    (?:[0-9]+\\??\$)?+       # Optional ordering of the placeholders.
                    [+-]?+                     # Optional sign specifier.
                    (?:
                        (?:0|'.)?+                 # Optional padding specifier - excluding the space.
                        -?+                         # Optional alignment specifier.
                        [0-9]*+                     # Optional width specifier.
                        (?:\.(?:[ 0]|'.)?[0-9]+)?+ # Optional precision specifier with optional padding character.
                        |                      # Only recognize the space as padding in combination with a width specifier.
                        (?:[ ])?+                   # Optional space padding specifier.
                        -?+                         # Optional alignment specifier.
                        [0-9]++                     # Width specifier.
                        (?:\.(?:[ 0]|'.)?[0-9]+)?+ # Optional precision specifier with optional padding character.
                    )
                    (?![dfFsi])                # Negative look ahead: not one of the supported placeholders.
                    (?:[^ '"]*|$)             # but something else instead.
                )
            )`x
        REGEX;

    /**
     * The engine never reads the group on its way from a repeat to a group
     * it may skip: it writes the same matches with and without it, with and
     * without the JIT, and the group is reported.
     *
     * @param non-empty-list<string> $subjects the first is one the pattern finds
     */
    #[Test]
    #[DataProvider('provideGroupsTheEngineDoesNotReadBesideARepeat')]
    public function test_a_group_the_engine_does_not_read_beside_a_repeat_is_reported(string $pattern, string $group, string $without, array $subjects): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.39, 10.40, 10.42 and 10.44.
        $this->assertNotSame($pattern, $without);
        foreach ($subjects as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($without, $subject), \sprintf('Oracle: %s and %s differ on %s.', $pattern, $without, var_export($subject, true)));
            $this->assertSame(self::matched(PcreInterpreter::pattern($pattern), $subject), self::matched(PcreInterpreter::pattern($without), $subject), \sprintf('Oracle, interpreter: %s and %s differ on %s.', $pattern, $without, var_export($subject, true)));
        }
        // A PHP of its own runs the JIT whatever the suite compiled before.
        $this->assertSame(1, JitInAFreshProcess::matched($pattern, $subjects[0]), 'Oracle, JIT: the first subject is one the pattern finds.');
        $this->assertSame(JitInAFreshProcess::matches($pattern, $subjects[0]), JitInAFreshProcess::matches($without, $subjects[0]), \sprintf('Oracle, JIT: %s and %s differ on %s.', $pattern, $without, var_export($subjects[0], true)));

        $this->assertContains($group, self::reportedGroups($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, group: string, without: string, subjects: non-empty-list<string>}>
     */
    public static function provideGroupsTheEngineDoesNotReadBesideARepeat(): iterable
    {
        yield 'a group far before a possessive repeat of a group' => [
            'pattern' => '/(?:\\\\)?define [a-z]++ (?:,x)*+ /',
            'group' => '(?:\\\\)',
            'without' => '/\\\\?define [a-z]++ (?:,x)*+ /',
            'subjects' => ['\\define ab ,x ', 'define a ,x,x ', '\\\\define a  ', 'xdefine z ', 'define  ', 'define a,x ', '\\define', ''],
        ];
        yield 'the same in an alternative of a long pattern' => [
            'pattern' => self::SYMBOLS,
            'group' => '(?:\\\\)',
            'without' => str_replace('(?:\\\\)?', '\\\\?', self::SYMBOLS),
            'subjects' => ['\\define("A\\\\B", 1); define(\'c\', 2);', 'x = \\\\define ( "a_b\\c"', 'function &foo( ) { } namespace A \\ B;', 'enum Suit: string { const X = 1; }', '$a->define("x")', ''],
        ];
        yield 'a group repeated possessively behind a letter the repeat stops at' => ['pattern' => '/a+x(?:b)?+a/', 'group' => '(?:b)', 'without' => '/a+xb?+a/', 'subjects' => ['aaxba', ...self::LETTERS]];
        yield 'a repeat written as a group, a letter between it and the group' => ['pattern' => '/(?:a)+x(?:b)?+a/', 'group' => '(?:a)', 'without' => '/a+x(?:b)?+a/', 'subjects' => ['aaxa', ...self::LETTERS]];
        yield 'a group read after a repeat, a letter before the possessive group' => ['pattern' => '/a+(?:b)?x(?:,x)*+a/', 'group' => '(?:b)', 'without' => '/a+b?x(?:,x)*+a/', 'subjects' => ['aabx,x,xa', ...self::LETTERS, 'ax,xa', 'aax,a']];
        yield 'a group in an atomic group behind a letter' => ['pattern' => '/a+(?:c(?>(?:b)?))a/', 'group' => '(?:b)', 'without' => '/a+(?:c(?>b?))a/', 'subjects' => ['aacba', ...self::LETTERS]];
        yield 'an atomic group before the repeat, in a group read once' => ['pattern' => '/(?:(?>(?:b)?)a+)?a/', 'group' => '(?:b)', 'without' => '/(?:(?>b?)a+)?a/', 'subjects' => ['baaa', ...self::LETTERS]];
        yield 'the atomic group of the next round behind a letter' => ['pattern' => '/(?:c(?:(?>(?:b)?)a+)){2}/', 'group' => '(?:b)', 'without' => '/(?:c(?:(?>b?)a+)){2}/', 'subjects' => ['cbaacbaa', ...self::LETTERS, 'caca', 'cacaa', 'caacaa', 'cbacba']];
        yield 'a group behind a letter in the atomic group the repeat is read against' => ['pattern' => '/a+(?>c(?:b)?|)a/', 'group' => '(?:b)', 'without' => '/a+(?>cb?|)a/', 'subjects' => ['aacba', ...self::LETTERS]];
        yield 'an optional group a letter parts from the repeat the engine reads from' => ['pattern' => '/(?:c)?xa+(?>(?:b)?)a/', 'group' => '(?:c)', 'without' => '/c?xa+(?>(?:b)?)a/', 'subjects' => ['cxaba', ...self::LETTERS, 'xaba', 'cxaa', 'xaaba']];
        // A counted letter runs one way: PCRE2 has nothing to make possessive.
        yield 'a group repeated possessively after a letter counted' => ['pattern' => '/a{2}(?:b)?+a/', 'group' => '(?:b)', 'without' => '/a{2}b?+a/', 'subjects' => ['aaba', ...self::LETTERS]];
    }

    /**
     * The engine reads the brackets on its way from a repeat to a group it
     * may skip: with and without them it tells the subject apart, with and
     * without the JIT, and the group is not reported.
     */
    #[Test]
    #[DataProvider('provideGroupsTheEngineReadsBesideARepeat')]
    public function test_a_group_the_engine_reads_beside_a_repeat_is_not_reported(string $pattern, string $group, string $without, string $subject = 'aa'): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.39, 10.40, 10.42 and 10.44.
        $this->assertNotSame(preg_match($pattern, $subject), preg_match($without, $subject));
        $this->assertNotSame(preg_match(PcreInterpreter::pattern($pattern), $subject), preg_match(PcreInterpreter::pattern($without), $subject));
        $this->assertNotSame(JitInAFreshProcess::matched($pattern, $subject), JitInAFreshProcess::matched($without, $subject));

        $this->assertNotContains($group, self::reportedGroups($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, group: string, without: string, subject?: string}>
     */
    public static function provideGroupsTheEngineReadsBesideARepeat(): iterable
    {
        yield 'an optional letter between the repeat and the group' => ['pattern' => '/a+c?(?:b)?+a/', 'group' => '(?:b)', 'without' => '/a+c?b?+a/'];
        yield 'a repeat written as a group, an optional letter after it' => ['pattern' => '/(?:a)+c?(?:b)?+a/', 'group' => '(?:a)', 'without' => '/a+c?(?:b)?+a/'];
        yield 'an atomic group that opens a group, after an optional letter' => ['pattern' => '/a+(?:c?(?>(?:b)?))a/', 'group' => '(?:b)', 'without' => '/a+(?:c?(?>b?))a/'];
        yield 'a comment in a group of an atomic group' => ['pattern' => '/a+(?>(?:(?#c))|b)a/', 'group' => '(?:(?#c))', 'without' => '/a+(?>(?#c)|b)a/'];
        yield 'a letter before the repeat' => ['pattern' => '/xa+c?(?:b)?+a/', 'group' => '(?:b)', 'without' => '/xa+c?b?+a/', 'subject' => 'xaa'];
        yield 'a letter after the atomic group, in the group it opens' => ['pattern' => '/a+(?:(?>(?:b)?)a)c/', 'group' => '(?:b)', 'without' => '/a+(?:(?>b?)a)c/', 'subject' => 'aac'];
        yield 'a repeat that ends a counted group' => ['pattern' => '/(?:a+){2}(?:b)?+a/', 'group' => '(?:b)', 'without' => '/(?:a+){2}b?+a/', 'subject' => 'aaa'];
        // A counted group is compiled once per round: the repeat of one
        // round stands before the atomic group of the next.
        yield 'the atomic group of the next round of a counted group' => ['pattern' => '/(?:(?>(?:b)?)a+){2}/', 'group' => '(?:b)', 'without' => '/(?:(?>b?)a+){2}/'];
        yield 'the same, counted with no upper bound' => ['pattern' => '/(?:(?>(?:b)?)a+){2,}/', 'group' => '(?:b)', 'without' => '/(?:(?>b?)a+){2,}/'];
        yield 'a repeat written as a group before the atomic group of the next round' => ['pattern' => '/(?:(?>(?:b)?)(?:a)+){2}/', 'group' => '(?:a)', 'without' => '/(?:(?>(?:b)?)a+){2}/'];
        yield 'the atomic group of the next round, in a group of the counted one' => ['pattern' => '/(?:c?(?:(?>(?:b)?)a+)){2}/', 'group' => '(?:b)', 'without' => '/(?:c?(?:(?>b?)a+)){2}/'];
    }

    /**
     * Brackets that stand where the engine reads, on its way from a repeat
     * to a group it may skip, are kept though it reads the two patterns
     * alike today: which of them it reads to decide is not told from the
     * outside.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideGroupsKeptWhereTheEngineReads')]
    public function test_a_group_where_the_engine_reads_is_kept(string $pattern, string $group, string $without, array $subjects): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the two match alike on these.
        $this->assertNotSame($pattern, $without);
        foreach ($subjects as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($without, $subject), \sprintf('Oracle: %s and %s differ on %s.', $pattern, $without, var_export($subject, true)));
        }

        $this->assertNotContains($group, self::reportedGroups($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, group: string, without: string, subjects: list<string>}>
     */
    public static function provideGroupsKeptWhereTheEngineReads(): iterable
    {
        yield 'a group between the repeat and the atomic group' => ['pattern' => '/a+(?:b)?(?>(?:c)?)a/', 'group' => '(?:b)', 'without' => '/a+b?(?>(?:c)?)a/', 'subjects' => self::LETTERS];
        yield 'a group repeated possessively, right after a possessive repeat' => [
            'pattern' => self::PLACEHOLDER,
            'group' => '(?:[ ])',
            'without' => str_replace('(?:[ ])?+', '[ ]?+', self::PLACEHOLDER),
            'subjects' => ['%1$ 5x', '% 5x', '%+ -12.\'x4q', '%%d', '% d', '%5.2z %-08k', 'a%1\\$ 3y', ''],
        ];
        yield 'a group of a round that may run again' => ['pattern' => '/(?:(?>(?:b)?)a+)+/', 'group' => '(?:b)', 'without' => '/(?:(?>b?)a+)+/', 'subjects' => self::LETTERS];
    }

    /**
     * A pattern that holds a call is read whole, as before: PCRE2's JIT
     * misses matches of a repeat made possessive where a call runs, and
     * taking a group out of such a pattern may make one.
     */
    #[Test]
    public function test_a_pattern_that_holds_a_call_is_read_whole(): void
    {
        $pattern = '/((?:a)+b)c(?1)dx*(?>y?)/';
        $without = '/(a+b)c(?1)dx*(?>y?)/';

        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.39 to 10.44: the
        // interpreter finds "abcabd" in both.
        $this->assertSame([1, 1], [preg_match(PcreInterpreter::pattern($pattern), 'abcabcabd'), preg_match(PcreInterpreter::pattern($without), 'abcabcabd')]);

        // The same pattern without the call has no brackets the engine
        // reads beside a repeat: the group goes.
        $this->assertSame([1, 1], [preg_match('/((?:a)+b)cdx*(?>y?)/', 'abcd'), preg_match('/(a+b)cdx*(?>y?)/', 'abcd')]);
        $this->assertContains('(?:a)', self::reportedGroups('/((?:a)+b)cdx*(?>y?)/'));

        $this->assertNotContains('(?:a)', self::reportedGroups($pattern));

        // Read whole, a group that is no repeat's and stands in no group
        // the engine may skip decides nothing, a call or not.
        $this->assertSame([1, 1], [preg_match('/(?:a)b+(?>c?)(x)(?1)/', 'abbcxx'), preg_match('/ab+(?>c?)(x)(?1)/', 'abbcxx')]);
        $this->assertContains('(?:a)', self::reportedGroups('/(?:a)b+(?>c?)(x)(?1)/'));

        $this->requiresJit($without);

        // Oracle, PCRE2 10.39 to 10.49: with the JIT, "a+" made possessive
        // inside the group the call runs finds nothing.
        $this->assertSame([1, 0], [JitInAFreshProcess::matched($pattern, 'abcabcabd'), JitInAFreshProcess::matched($without, 'abcabcabd')]);
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function matched(string $pattern, string $subject): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);

        return [$result, $matches];
    }

    /**
     * The groups the rule reports in the pattern, as each message quotes
     * them.
     *
     * @return list<string>
     */
    private static function reportedGroups(string $pattern): array
    {
        $groups = [];
        foreach (Regex::create(['cache' => null])->lint($pattern) as $violation) {
            if (self::ID === $violation->id && 1 === preg_match('/^Redundant non-capturing group "(.+)" at offset \d+; it can be removed without changing behavior\.$/', $violation->message, $quoted)) {
                $groups[] = $quoted[1];
            }
        }

        return $groups;
    }
}

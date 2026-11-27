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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Transpiler\TranspileOptions;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a character repeated before an atomic group, or a possessive
 * repeat of a group, that may match nothing possessive, as if the group
 * could not be skipped: "/a+(?:b)?+a/" finds nothing in "aa". JavaScript
 * and Python run the repeat as written, and find "aa" in what the
 * transpiler writes for them: the pattern is written, with a warning that
 * names the repeat and the group.
 */
final class RepeatMadePossessiveWarningTest extends TestCase
{
    private const SUBJECTS = ['', 'a', 'aa', 'aaa', 'ab', 'aba', 'aab', 'aaba', 'axa', 'aaxa', 'axba', 'aca', 'aaca', 'b', 'ba', 'abab', 'x', 'c', 'aac', 'aabc', '11', '1x1', 'éé'];

    #[Test]
    #[DataProvider('provideRepeatsPcreMakesPossessive')]
    public function test_a_repeat_pcre_makes_possessive_is_warned_of(string $pattern, string $subject, string $dialect, ?string $pythonVersion, string $literal, string $warning): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.39 to 10.44: under
        // "(*NO_AUTO_POSSESS)" PCRE2 runs the repeat as written, as the
        // target does, and finds the subject it finds nothing in.
        $asWritten = $pattern[0].'(*NO_AUTO_POSSESS)'.substr($pattern, 1);
        $this->assertSame([0, 1], [preg_match($pattern, $subject), preg_match($asWritten, $subject)]);
        $this->assertSame([0, 1], [preg_match(PcreInterpreter::pattern($pattern), $subject), preg_match(PcreInterpreter::pattern($asWritten), $subject)]);

        $result = (new Transpiler(RegexParser::create(['cache' => null])))->transpile($pattern, $dialect, new TranspileOptions(pythonVersion: $pythonVersion));

        $this->assertSame($literal, $result->literal);
        $this->assertSame([$warning], $result->warnings);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, dialect: string, pythonVersion: string|null, literal: string, warning: string}>
     */
    public static function provideRepeatsPcreMakesPossessive(): iterable
    {
        $before = static fn (string $repeat, int $at, string $group, int $groupAt): string => \sprintf('PCRE2 makes "%s" at offset %d possessive before "%s" at offset %d, as if that group could not be skipped, and this target does not: the two differ on a subject where the repeat must give back to what follows the group.', $repeat, $at, $group, $groupAt);

        yield 'a possessive repeat of a group, for JavaScript' => ['pattern' => '/a+(?:b)?+a/', 'subject' => 'aa', 'dialect' => 'javascript', 'pythonVersion' => null, 'literal' => '/a+(?:b)?a/', 'warning' => $before('a+', 0, '(?:b)?+', 2)];
        yield 'an atomic group, for JavaScript' => ['pattern' => '/a+(?>(?:b)?)a/', 'subject' => 'aa', 'dialect' => 'javascript', 'pythonVersion' => null, 'literal' => '/a+(?:(?:b)?)a/', 'warning' => $before('a+', 0, '(?>(?:b)?)', 2)];
        yield 'a possessive repeat of a group, for the HTML pattern attribute' => ['pattern' => '/a+(?:b)?+a/', 'subject' => 'aa', 'dialect' => 'html-pattern', 'pythonVersion' => null, 'literal' => '[\s\S]*(?:a+(?:b)?a)[\s\S]*', 'warning' => $before('a+', 0, '(?:b)?+', 2)];
        yield 'a possessive repeat of a group, for Python 3.11' => ['pattern' => '/a+(?:b)?+a/', 'subject' => 'aa', 'dialect' => 'python', 'pythonVersion' => '3.11', 'literal' => "r'(?a)a+(?>(?:b)?)a'", 'warning' => $before('a+', 0, '(?:b)?+', 2)];
        yield 'a possessive repeat of a group, for Python 3.11.5' => ['pattern' => '/a+(?:b)?+a/', 'subject' => 'aa', 'dialect' => 'python', 'pythonVersion' => '3.11.5', 'literal' => "r'(?a)a+(?:b)?+a'", 'warning' => $before('a+', 0, '(?:b)?+', 2)];
        yield 'an atomic group, for Python 3.11' => ['pattern' => '/a+(?>(?:b)?)a/', 'subject' => 'aa', 'dialect' => 'python', 'pythonVersion' => '3.11', 'literal' => "r'(?a)a+(?>(?:b)?)a'", 'warning' => $before('a+', 0, '(?>(?:b)?)', 2)];
        yield 'an optional letter between the repeat and the group' => ['pattern' => '/xa+c?(?:b)?+a/', 'subject' => 'xaa', 'dialect' => 'javascript', 'pythonVersion' => null, 'literal' => '/xa+c?(?:b)?a/', 'warning' => $before('a+', 1, '(?:b)?+', 5)];
        yield 'the first of two repeats the engine makes possessive' => ['pattern' => '/a+c*(?:b)?+a/', 'subject' => 'aa', 'dialect' => 'javascript', 'pythonVersion' => null, 'literal' => '/a+c*(?:b)?a/', 'warning' => $before('a+', 0, '(?:b)?+', 4)];
        yield 'the first of two groups the repeat is read against' => ['pattern' => '/a+(?:b)?+(?>(?:c)?)a/', 'subject' => 'aa', 'dialect' => 'javascript', 'pythonVersion' => null, 'literal' => '/a+(?:b)?(?:(?:c)?)a/', 'warning' => $before('a+', 0, '(?:b)?+', 2)];
        // Under U a repeat written lazy is the greedy one.
        yield 'a repeat written lazy under U' => ['pattern' => '/a+?(?:b)?+a/U', 'subject' => 'aa', 'dialect' => 'javascript', 'pythonVersion' => null, 'literal' => '/a+(?:b)?a/', 'warning' => $before('a+?', 0, '(?:b)?+', 3)];
        yield 'a repeat written lazy under an inline U' => ['pattern' => '/(?U)a+?(?:b)?+a/', 'subject' => 'aa', 'dialect' => 'javascript', 'pythonVersion' => null, 'literal' => '/a+(?:b)?a/', 'warning' => $before('a+?', 4, '(?:b)?+', 7)];
        yield 'a greedy repeat before an inline U' => ['pattern' => '/a+(?:b)?+a(?U)/', 'subject' => 'aa', 'dialect' => 'javascript', 'pythonVersion' => null, 'literal' => '/a+(?:b)?a/', 'warning' => $before('a+', 0, '(?:b)?+', 2)];
    }

    /**
     * No warning where PCRE2 runs the pattern as written: a letter that
     * must be there parts the repeat from the group, the repeat is written
     * as a group or possessive, or it is lazy, which PCRE2 leaves alone.
     */
    #[Test]
    #[DataProvider('provideRepeatsPcreRunsAsWritten')]
    public function test_a_repeat_pcre_runs_as_written_is_not_warned_of(string $pattern, string $literal): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.39 to 10.44: with and
        // without "(*NO_AUTO_POSSESS)" the engine writes the same matches.
        $asWritten = $pattern[0].'(*NO_AUTO_POSSESS)'.substr($pattern, 1);
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $made), preg_match($asWritten, $subject, $written), 'Oracle: '.$subject);
            $this->assertSame($made, $written, 'Oracle: '.$subject);
        }

        $result = (new Transpiler(RegexParser::create(['cache' => null])))->transpile($pattern, 'javascript');

        $this->assertSame($literal, $result->literal);
        $this->assertSame([], $result->warnings);
    }

    /**
     * @return iterable<string, array{pattern: string, literal: string}>
     */
    public static function provideRepeatsPcreRunsAsWritten(): iterable
    {
        yield 'a letter between the repeat and the group' => ['pattern' => '/a+x(?:b)?+a/', 'literal' => '/a+x(?:b)?a/'];
        yield 'a letter repeated possessively, no group' => ['pattern' => '/a+b?+c/', 'literal' => '/a+b?c/'];
        yield 'a repeat written as a group' => ['pattern' => '/(?:a)+(?:b)?+a/', 'literal' => '/(?:a)+(?:b)?a/'];
        yield 'a repeat written possessive' => ['pattern' => '/a++(?:b)?+c/', 'literal' => '/a+(?:b)?c/'];
        yield 'a lazy repeat' => ['pattern' => '/a+?(?:b)?+a/', 'literal' => '/a+?(?:b)?a/'];
        yield 'a repeat written greedy under U, which runs lazy' => ['pattern' => '/a+(?:b)?+a/U', 'literal' => '/a+?(?:b)?a/'];
        yield 'a repeat written possessive under U' => ['pattern' => '/a++(?:b)?+c/U', 'literal' => '/a+(?:b)?c/'];
        yield 'a letter counted, which runs one way' => ['pattern' => '/a{2}(?:b)?+a/', 'literal' => '/a{2}(?:b)?a/'];
        yield 'no repeat before the group' => ['pattern' => '/x(?:b)?+a+/', 'literal' => '/x(?:b)?a+/'];
    }
}

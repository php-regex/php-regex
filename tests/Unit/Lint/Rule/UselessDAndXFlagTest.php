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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "D" reads only a "$" anchor read without "m"; "x" only drops whitespace
 * and "#" comments. A flag with nothing to read is reported. PHP 8.4.26 /
 * PCRE2 10.49: each reported pattern matches every subject below exactly
 * as it does without the flag.
 */
final class UselessDAndXFlagTest extends TestCase
{
    private const SUBJECTS = ['', 'a', "a\n", 'a$', 'ab', "ab\n", 'a b', 'abc', ' ', '#', "a\u{2028}b"];

    #[Test]
    #[DataProvider('provideUselessFlags')]
    public function test_a_flag_with_nothing_to_read_is_reported(string $pattern, string $flag, string $message): void
    {
        $without = $this->withoutFlag($pattern, $flag);
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($without, $subject, $a), preg_match($pattern, $subject, $b), $pattern.' on '.json_encode($subject));
            $this->assertSame($a, $b, $pattern.' on '.json_encode($subject));
        }

        $this->assertSame([$message], $this->messages($pattern, 'regex.lint.flag.useless.'.$flag));
    }

    /**
     * @return iterable<string, array{pattern: string, flag: string, message: string}>
     */
    public static function provideUselessFlags(): iterable
    {
        $noDollar = "Flag 'D' is useless: the pattern contains no $ anchor.";
        $underM = "Flag 'D' is useless: every $ anchor is read under m, which overrides D.";
        $noSpace = "Flag 'x' is useless: the pattern contains no whitespace and no # comment.";

        yield 'D without any anchor' => ['pattern' => '/a/D', 'flag' => 'D', 'message' => $noDollar];
        yield 'D with \Z only' => ['pattern' => '/a\Z/D', 'flag' => 'D', 'message' => $noDollar];
        yield 'D with a literal dollar in a class' => ['pattern' => '/a[$]/D', 'flag' => 'D', 'message' => $noDollar];
        yield 'D with an escaped dollar' => ['pattern' => '/a\$/D', 'flag' => 'D', 'message' => $noDollar];
        yield 'D under m' => ['pattern' => '/a$/mD', 'flag' => 'D', 'message' => $underM];
        yield 'D under an inline m' => ['pattern' => '/(?m)a$/D', 'flag' => 'D', 'message' => $underM];
        yield 'D under a scoped m' => ['pattern' => '/(?m:a$)|b/D', 'flag' => 'D', 'message' => $underM];
        yield 'x without whitespace' => ['pattern' => '/abc/x', 'flag' => 'x', 'message' => $noSpace];
        yield 'x beside other flags' => ['pattern' => '/a(?i)b/xs', 'flag' => 'x', 'message' => $noSpace];
    }

    #[Test]
    #[DataProvider('provideUsefulFlags')]
    public function test_a_flag_that_changes_the_match_is_not_reported(string $pattern, string $flag, string $subject): void
    {
        // Without the flag a pattern may not compile at all: false.
        $this->assertNotSame(
            @preg_match($this->withoutFlag($pattern, $flag), $subject),
            preg_match($pattern, $subject),
            $pattern.' changes nothing on '.json_encode($subject),
        );

        $this->assertSame([], $this->messages($pattern, 'regex.lint.flag.useless.'.$flag));
    }

    /**
     * @return iterable<string, array{pattern: string, flag: string, subject: string}>
     */
    public static function provideUsefulFlags(): iterable
    {
        yield 'D with an end anchor' => ['pattern' => '/a$/D', 'flag' => 'D', 'subject' => "a\n"];
        yield 'D where an inline option turns m off' => ['pattern' => '/(?-m)a$/mD', 'flag' => 'D', 'subject' => "a\n"];
        yield 'D after a scoped m' => ['pattern' => '/(?m:b)?a$/D', 'flag' => 'D', 'subject' => "a\n"];
        yield 'D in a lookahead' => ['pattern' => '/a(?=$)/D', 'flag' => 'D', 'subject' => "a\n"];
        yield 'D in a conditional' => ['pattern' => '/(?(?=x)x|a$)/D', 'flag' => 'D', 'subject' => "a\n"];
        yield 'x with a space' => ['pattern' => '/a b/x', 'flag' => 'x', 'subject' => 'ab'];
        yield 'x with a tab' => ['pattern' => "/a\tb/x", 'flag' => 'x', 'subject' => 'ab'];
        yield 'x with a newline' => ['pattern' => "/a\nb/x", 'flag' => 'x', 'subject' => 'ab'];
        yield 'x with the byte 0x85' => ['pattern' => "/a\x85b/x", 'flag' => 'x', 'subject' => 'ab'];
        yield 'x with a line separator under u' => ['pattern' => "/a\u{2028}b/xu", 'flag' => 'x', 'subject' => 'ab'];
        yield 'x with a left-to-right mark under u' => ['pattern' => "/a\u{200E}b/xu", 'flag' => 'x', 'subject' => 'ab'];
        // Under (*NUL) a NUL byte ends a "#" comment: the comment alone
        // makes x matter, wherever it stands.
        yield 'x with a comment ending the pattern' => ['pattern' => '/a#c/x', 'flag' => 'x', 'subject' => 'a'];
        yield 'x with a comment in DEFINE' => ['pattern' => "/(*NUL)(?(DEFINE)(?<n>a#c\0))(?&n)b/x", 'flag' => 'x', 'subject' => 'ab'];
        yield 'x with a comment in a lookbehind' => ['pattern' => "/(*NUL)(?<=a#c\0)b/x", 'flag' => 'x', 'subject' => 'ab'];
        yield 'x with a comment in a conditional' => ['pattern' => "/(*NUL)(a)?(?(1)b#c\0|c)/x", 'flag' => 'x', 'subject' => 'ab'];
        yield 'x with a comment in a repeated group' => ['pattern' => "/(*NUL)(?:a#c\0)+b/x", 'flag' => 'x', 'subject' => 'ab'];
        // The parser keeps no node for a comment before a quantifier or a
        // condition.
        yield 'x with a comment before a quantifier' => ['pattern' => "/(*NUL)a#c\0+/x", 'flag' => 'x', 'subject' => 'aaa'];
        yield 'x with a comment before a condition' => ['pattern' => "/(*NUL)(?(?#c)#c\0(?=a)a|b)/x", 'flag' => 'x', 'subject' => 'a'];
        yield 'x with a comment in a top-level sequence' => ['pattern' => "/(*NUL)a#c\0b/x", 'flag' => 'x', 'subject' => 'ab'];
    }

    /**
     * A space in a class or after a backslash is read as written under x:
     * the rule stays silent rather than tell those apart.
     */
    #[Test]
    public function test_whitespace_that_x_keeps_still_silences_the_rule(): void
    {
        $this->assertSame(1, preg_match('/a[ ]b/x', 'a b'));
        $this->assertSame(1, preg_match('/a\ b/x', 'a b'));

        $this->assertSame([], $this->messages('/a[ ]b/x', 'regex.lint.flag.useless.x'));
        $this->assertSame([], $this->messages('/a\ b/x', 'regex.lint.flag.useless.x'));
    }

    /**
     * A "#" that opens no comment, in a class, escaped or in a "(?#...)"
     * group, reads the same without x.
     */
    #[Test]
    public function test_a_hash_that_opens_no_comment_does_not_use_x(): void
    {
        foreach (['/a[#]/x', '/a\#/x', '/a(?#c)b/x'] as $pattern) {
            foreach (['a#', 'ab', 'a'] as $subject) {
                $this->assertSame(preg_match($this->withoutFlag($pattern, 'x'), $subject), preg_match($pattern, $subject), $pattern);
            }
            $this->assertSame(["Flag 'x' is useless: the pattern contains no whitespace and no # comment."], $this->messages($pattern, 'regex.lint.flag.useless.x'), $pattern);
        }
    }

    /**
     * Under a UTF-8 LC_CTYPE, PHP hands PCRE the locale's tables, and x
     * drops the no-break space as well (byte 0xA0, U+00A0 under u): the
     * rule counts it as whitespace whatever the locale.
     */
    #[Test]
    public function test_a_no_break_space_silences_the_rule(): void
    {
        $this->assertSame([], $this->messages("/a\u{A0}b/xu", 'regex.lint.flag.useless.x'));
        $this->assertSame([], $this->messages("/a\xA0b/x", 'regex.lint.flag.useless.x'));
    }

    #[Test]
    public function test_a_pattern_without_the_flag_is_not_reported(): void
    {
        $this->assertSame([], $this->messages('/a/', 'regex.lint.flag.useless.D'));
        $this->assertSame([], $this->messages('/abc/', 'regex.lint.flag.useless.x'));
    }

    private function withoutFlag(string $pattern, string $flag): string
    {
        $end = (int) strrpos($pattern, '/');

        return substr($pattern, 0, $end).str_replace($flag, '', substr($pattern, $end));
    }

    /**
     * @return list<string>
     */
    private function messages(string $pattern, string $ruleId): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $messages = [];
        foreach ($linter->getIssues() as $issue) {
            if ($ruleId === $issue->id) {
                $messages[] = $issue->message;
            }
        }

        return $messages;
    }
}

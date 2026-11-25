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
 * Under /m, "^" holds at the start of the subject, or after a character
 * that ends a line when more follows: under "(*ANY)" a line feed, a
 * vertical tab, a form feed or a carriage return, and none of the
 * characters around them. It never holds at the end of the subject once a
 * character was read, whatever reads nothing between it and "\z": an
 * anchor, an assertion, a keep, a verb, a callout, a comment or an option
 * setting. The finding gives the first reason found, the character before
 * the "^" ahead of the end of the subject.
 */
final class LineStartUnderMultilineTest extends TestCase
{
    private const LETTERS = ['a', "\n", "\r", "\t", "\x0E", "\x7F"];

    private const NO_LINE_END = "Start anchor '^' comes after a character that ends no line, making it impossible to match.";

    private const AT_THE_END = "Start anchor '^' stands at the end of the subject after consuming characters, making it impossible to match.";

    /**
     * The engine finds no match, with the JIT or without, in any string up
     * to four characters over a letter, the line feed, the carriage return,
     * the tab, 0x0E and 0x7F.
     */
    #[Test]
    #[DataProvider('provideAnchorsNothingReaches')]
    public function test_a_line_start_no_subject_reaches_is_reported_for_its_reason(string $pattern, string $message): void
    {
        foreach (self::subjects() as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), \sprintf('%s on %s.', $pattern, json_encode($subject)));
            $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject), \sprintf('%s on %s, without the JIT.', $pattern, json_encode($subject)));
        }

        $this->assertContains($message, $this->messages($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, message: string}>
     */
    public static function provideAnchorsNothingReaches(): iterable
    {
        // Under (*ANY), the characters just outside the line ends end none.
        yield 'a tab under (*ANY)' => ['pattern' => '/(*ANY)\t^a/m', 'message' => self::NO_LINE_END];
        yield 'the byte after the carriage return under (*ANY)' => ['pattern' => '/(*ANY)\x0E^a/m', 'message' => self::NO_LINE_END];
        yield 'the last ASCII byte under (*ANY)' => ['pattern' => '/(*ANY)\x7F^a/m', 'message' => self::NO_LINE_END];
        yield 'a letter under (*ANY)' => ['pattern' => '/(*ANY)a^/m', 'message' => self::NO_LINE_END];
        // A letter before the "^" is the first reason, before "\z" after it.
        yield 'a letter, then the end' => ['pattern' => '/a^\z/m', 'message' => self::NO_LINE_END];
        // "\z" before the "^", or after it past what reads nothing.
        yield 'a line feed and the end before' => ['pattern' => '/\n\z^/m', 'message' => self::AT_THE_END];
        yield 'a dollar between' => ['pattern' => '/\n^$\z/m', 'message' => self::AT_THE_END];
        yield 'a keep between' => ['pattern' => '/\n^\K\z/m', 'message' => self::AT_THE_END];
        yield 'a non-boundary between' => ['pattern' => '/\n^\B\z/m', 'message' => self::AT_THE_END];
        yield 'a mark between' => ['pattern' => '/\n^(*MARK:x)\z/m', 'message' => self::AT_THE_END];
        yield 'a callout between' => ['pattern' => '/\n^(?C1)\z/m', 'message' => self::AT_THE_END];
        yield 'a comment between' => ['pattern' => '/\n^(?#c)\z/m', 'message' => self::AT_THE_END];
        yield 'an option setting between' => ['pattern' => '/\n^(?i)\z/m', 'message' => self::AT_THE_END];
        // In a lookahead, an "(*ACCEPT)" after the "^" ends the lookahead,
        // not the match: the lookahead itself reads nothing after.
        yield 'an accept after the line start in a lookahead' => ['pattern' => '/a(?=\n^(*ACCEPT))\n\z/m', 'message' => self::AT_THE_END];
    }

    /**
     * The engine finds a match, with the JIT and without: the rule says
     * nothing of the anchors.
     */
    #[Test]
    #[DataProvider('provideNearMisses')]
    public function test_a_line_start_a_subject_reaches_is_not_reported(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
        $this->assertSame(1, preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject), $pattern.', without the JIT');

        $this->assertNotContains(self::NO_LINE_END, $this->messages($pattern), $pattern);
        $this->assertNotContains(self::AT_THE_END, $this->messages($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideNearMisses(): iterable
    {
        yield 'a line feed under (*ANY)' => ['pattern' => '/(*ANY)\n^a/m', 'subject' => "\na"];
        yield 'a carriage return under (*ANY)' => ['pattern' => '/(*ANY)\r^a/m', 'subject' => "\ra"];
        yield 'an optional letter, then the end' => ['pattern' => '/a?^\z/m', 'subject' => ''];
        yield 'a letter after the line start, then the end' => ['pattern' => '/\n^a\z/m', 'subject' => "\na"];
        yield 'a group after the line start, then the end' => ['pattern' => '/\n^(a)\z/m', 'subject' => "\na"];
        // A lookahead that starts with the "^" stands where the items before
        // it leave it: at the start of the subject, "\z" may follow.
        yield 'a lookahead starting with the line start, then the end' => ['pattern' => '/(?=^$)\z/m', 'subject' => ''];
        // A lookahead outside a sequence: the whole pattern, a branch, a
        // repeat or a group.
        yield 'a lookahead as the whole pattern' => ['pattern' => '/(?=\n^)/m', 'subject' => "\na"];
        yield 'a lookahead as a branch' => ['pattern' => '/b|(?=\n^)/m', 'subject' => "\na"];
        yield 'a repeated lookahead' => ['pattern' => '/(?=\n^)?\n\z/m', 'subject' => "\n"];
        yield 'a lookahead in a group' => ['pattern' => '/((?=\n^))\na/m', 'subject' => "\na"];
    }

    /**
     * After "\Z", a "\G" inside a group that reads the newline stands after
     * the letter read before the anchor, where it never holds.
     */
    #[Test]
    public function test_a_match_start_in_a_group_after_the_final_newline_anchor_is_reported(): void
    {
        $pattern = '/(*CRLF)a\Z(?:\G\r\n)/';
        foreach (['', 'a', "\r\n", "a\r\n", "a\r\na\r\n"] as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), addcslashes($subject, "\r\n"));
            $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject), addcslashes($subject, "\r\n").', without the JIT');
        }

        $this->assertContains("End anchor '\\Z' appears before consuming characters, making it impossible to match.", $this->messages($pattern));
    }

    /**
     * @return list<string>
     */
    private function messages(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_map(static fn ($issue): string => $issue->message, $linter->getIssues());
    }

    /**
     * @return list<string>
     */
    private static function subjects(): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= 4; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (self::LETTERS as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            array_push($all, ...$next);
            $layer = $next;
        }

        return $all;
    }
}

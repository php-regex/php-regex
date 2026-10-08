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
 * Under a newline convention other than LF, "$" matches before that
 * newline: a "$" followed by it is no impossible anchor.
 */
final class EndAnchorNewlineConventionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEndAnchorsBeforeTheirNewline')]
    public function test_an_end_anchor_before_the_convention_newline_is_not_reported(string $pattern, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern matches.
        $this->assertSame(1, preg_match($pattern, $subject));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertNotContains('regex.lint.anchor.impossible.end', $ids, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideEndAnchorsBeforeTheirNewline(): iterable
    {
        yield 'carriage return' => ['pattern' => "/(*CR)a\$\r/", 'subject' => "a\r"];
        yield 'carriage return and line feed' => ['pattern' => "/(*CRLF)a\$\r\n/", 'subject' => "a\r\n"];
        yield 'any of the three' => ['pattern' => "/(*ANYCRLF)a\$\r/", 'subject' => "a\r"];
        yield 'carriage return under m' => ['pattern' => "/(*CR)a\$\r/m", 'subject' => "a\r"];
        yield 'carriage return after capital Z' => ['pattern' => "/(*CR)a\\Z\r/", 'subject' => "a\r"];
        yield 'NUL' => ['pattern' => "/(*NUL)a\$\x00/", 'subject' => "a\x00"];
        // The carriage return as each construct can spell it.
        yield 'carriage return as a hex escape' => ['pattern' => '/(*CR)a$\x0D/', 'subject' => "a\r"];
        yield 'carriage return in a class' => ['pattern' => '/(*CR)a$[\r]/', 'subject' => "a\r"];
        yield 'optional carriage return' => ['pattern' => '/(*CR)a$\r?/', 'subject' => "a\r"];
        yield 'carriage return repeated once' => ['pattern' => '/(*CR)a$\r{1}/', 'subject' => "a\r"];
        yield 'line feed by default' => ['pattern' => "/a\$\n/", 'subject' => "a\n"];
        yield 'carriage return in a group' => ['pattern' => '/(*CR)a$(?:\r)/', 'subject' => "a\r"];
        yield 'carriage return in an alternation' => ['pattern' => '/(*CR)a$(?:xy|\r)/', 'subject' => "a\r"];
        yield 'carriage return in a conditional' => ['pattern' => '/(*CR)(a)$(?(1)\r|x)/', 'subject' => "a\r"];
    }

    /**
     * Under "(*CR)" or "(*NUL)" a line feed is no newline: a "$" or "\\Z"
     * before one never holds.
     */
    #[Test]
    #[DataProvider('provideEndAnchorsBeforeAnotherCharacter')]
    public function test_an_end_anchor_before_a_line_feed_under_another_newline_is_reported(string $pattern): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern never matches.
        foreach (["a\n", "a\r", "a\r\n", "a\n\n", "a\x00\n", "a\r\n\r"] as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), $pattern.' '.json_encode($subject));
        }

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertContains('regex.lint.anchor.impossible.end', $ids, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideEndAnchorsBeforeAnotherCharacter(): iterable
    {
        yield 'carriage return' => ['pattern' => "/(*CR)a\$\n/"];
        yield 'carriage return under m' => ['pattern' => "/(*CR)a\$\n/m"];
        yield 'carriage return before capital Z' => ['pattern' => "/(*CR)a\\Z\n/"];
        yield 'NUL' => ['pattern' => "/(*NUL)a\$\n/"];
    }
}

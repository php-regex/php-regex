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
 * A group the items before it always starve captures "" wherever it takes
 * part: "/a+(a*)/" never fills $1. PHP 8.4.26 / PCRE2 10.49: over the
 * subjects below, each reported group captures "" or nothing, each silent
 * one captures text at least once.
 */
final class AlwaysEmptyCaptureTest extends TestCase
{
    private const RULE = 'regex.lint.group.alwaysEmptyCapture';

    private const SUBJECTS = ['', 'a', 'aa', 'aaa', 'aab', 'ab', 'b', 'bb', '1x', '12x', '123', 'a1', 'abc1', 'x', 'xxy', 'xy', 'aaab', '11'];

    #[Test]
    #[DataProvider('provideStarvedGroups')]
    public function test_a_group_that_always_captures_the_empty_string_is_reported(string $pattern, string $group): void
    {
        $matched = false;
        foreach (self::SUBJECTS as $subject) {
            if (1 === preg_match($pattern, $subject, $matches, \PREG_UNMATCHED_AS_NULL)) {
                $matched = true;
                $this->assertContains($matches[1], ['', null], $pattern.' on '.$subject);
            }
        }

        $this->assertTrue($matched, $pattern);
        $this->assertSame(
            [\sprintf('Capturing group "%s" never captures any text: it is empty or unset wherever the pattern matches.', $group)],
            $this->messages($pattern),
        );
    }

    /**
     * @return iterable<string, array{pattern: string, group: string}>
     */
    public static function provideStarvedGroups(): iterable
    {
        yield 'a run before the same run' => ['pattern' => '/a+(a*)/', 'group' => '(a*)'];
        yield 'word characters before digits' => ['pattern' => '/^\w+(\d*)$/', 'group' => '(\d*)'];
        yield 'an optional digit after digits' => ['pattern' => '/\d+(\d?)x/', 'group' => '(\d?)'];
        yield 'one alternative' => ['pattern' => '/a+(a*)|b/', 'group' => '(a*)'];
        yield 'a named group' => ['pattern' => '/x+(?<n>x*)y/', 'group' => '(?<n>x*)'];
    }

    #[Test]
    #[DataProvider('provideFilledGroups')]
    public function test_a_group_that_captures_text_is_not_reported(string $pattern): void
    {
        $filled = false;
        foreach (self::SUBJECTS as $subject) {
            $filled = $filled || (1 === preg_match($pattern, $subject, $matches) && '' !== ($matches[1] ?? ''));
        }

        $this->assertTrue($filled, $pattern.' never fills $1');
        $this->assertSame([], $this->messages($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideFilledGroups(): iterable
    {
        yield 'another set after a run' => ['pattern' => '/a+(b*)/'];
        yield 'a group before a run that needs one' => ['pattern' => '/(a*)a+/'];
        yield 'a lazy run before the group' => ['pattern' => '/a+?(a*)/'];
        yield 'a named group before digits' => ['pattern' => '/(?<n>\d*)\d+/'];
    }

    /**
     * An empty body is a marker, not a capture gone wrong; a pattern that
     * may match the empty string is tried again by preg_match_all() for a
     * non-empty match, where another path may fill the group; a group a
     * reference reads is beyond the automata.
     */
    #[Test]
    public function test_a_group_the_rule_cannot_judge_is_not_reported(): void
    {
        $this->assertSame([], $this->messages('/a+()/'));
        $this->assertSame([], $this->messages('/a*(a*)/'));
        $this->assertSame([], $this->messages('/a+(a*)\1/'));
        // In a branch reset the proof reads the slot, which another
        // alternative may fill: "x12y" puts "12" in $1 through "(\d*)".
        $this->assertSame(1, preg_match('/x(?|(\d*)|(\d+))y/', 'x12y', $matches));
        $this->assertSame('12', $matches[1]);
        $this->assertSame([], $this->messages('/x(?|(\d*)|(\d+))y/'));
        // A start anchor holds at offset 0 only: preg_match_all() goes on
        // past it, where "(c)" captures "c" in "acc".
        $this->assertSame(2, preg_match_all('/^.|.(c)/s', 'acc', $all, \PREG_SET_ORDER));
        $this->assertSame('c', $all[1][1] ?? null);
        $this->assertSame([], $this->messages('/^.|.(c)/s'));
        $this->assertSame([], $this->messages('/\A.|.(c)/s'));
        // A lookahead or a condition around the group: beyond the automata.
        $this->assertSame([], $this->messages('/x(?=(a*?))/'));
        $this->assertSame([], $this->messages('/(?(?=x)x(a*?)|y)/'));
    }

    /**
     * Where a lazy quantifier ends the pattern, quantifier.lazyEnd says why
     * the group stays empty; under "{0}", quantifier.zero says it.
     */
    #[Test]
    public function test_a_group_another_rule_explains_is_not_reported(): void
    {
        $this->assertSame(1, preg_match('/^L_(.*?)/', 'L_abc', $matches));
        $this->assertSame('', $matches[1]);

        $this->assertSame([], $this->messages('/^L_(.*?)/'));
        $this->assertSame([], $this->messages('/x([ ]*)?/U'));
        $this->assertSame([], $this->messages('/a(b){0}c/'));
    }

    /**
     * @return list<string>
     */
    private function messages(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $messages = [];
        foreach ($linter->getIssues() as $issue) {
            if (self::RULE === $issue->id) {
                $messages[] = $issue->message;
            }
        }

        return $messages;
    }
}

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
 * A lazy quantifier that leaves $matches as the greedy one does only makes
 * the reader wonder why it is lazy. PHP 8.4.26 / PCRE2 10.49: each reported
 * pattern writes the same $matches as its greedy form on every subject
 * below, and each silent one differs on at least one.
 */
final class UselessLazyTest extends TestCase
{
    private const RULE = 'regex.lint.quantifier.uselessLazy';

    private const SUBJECTS = ['', 'a', 'aa', 'aaa', 'ab', 'aab', 'aaab', 'abab', 'AAb', 'b', '12x', '1x2y', 'abcd', 'ababc', 'ababa', 'xaab', 'aaaab'];

    #[Test]
    #[DataProvider('provideUselessLazy')]
    public function test_a_lazy_quantifier_that_matches_as_the_greedy_one_is_reported(string $pattern, string $greedy, string $message): void
    {
        foreach (self::SUBJECTS as $subject) {
            preg_match($pattern, $subject, $lazyMatches);
            preg_match($greedy, $subject, $greedyMatches);
            $this->assertSame($greedyMatches, $lazyMatches, $pattern.' on '.$subject);
        }

        $this->assertSame([$message], $this->messages($pattern, true));
    }

    /**
     * @return iterable<string, array{pattern: string, greedy: string, message: string}>
     */
    public static function provideUselessLazy(): iterable
    {
        yield 'a run before a character it cannot take' => ['pattern' => '/(a+?)b/', 'greedy' => '/(a+)b/', 'message' => 'Lazy quantifier "a+?" matches what "a+" matches: the lazy marker changes nothing.'];
        yield 'a fixed count' => ['pattern' => '/a{3}?b/', 'greedy' => '/a{3}b/', 'message' => 'Lazy quantifier "a{3}?" matches what "a{3}" matches: the lazy marker changes nothing.'];
        yield 'a fixed count at the end' => ['pattern' => '/a{2}?/', 'greedy' => '/a{2}/', 'message' => 'Lazy quantifier "a{2}?" matches what "a{2}" matches: the lazy marker changes nothing.'];
        yield 'an optional item' => ['pattern' => '/a??b/', 'greedy' => '/a?b/', 'message' => 'Lazy quantifier "a??" matches what "a?" matches: the lazy marker changes nothing.'];
        yield 'digits before a non-digit' => ['pattern' => '/(\d+?)\D/', 'greedy' => '/(\d+)\D/', 'message' => 'Lazy quantifier "\d+?" matches what "\d+" matches: the lazy marker changes nothing.'];
        yield 'a class before a character outside it' => ['pattern' => '/[a-c]*?d/', 'greedy' => '/[a-c]*d/', 'message' => 'Lazy quantifier "[a-c]*?" matches what "[a-c]*" matches: the lazy marker changes nothing.'];
        yield 'a bounded run' => ['pattern' => '/a{2,3}?b/', 'greedy' => '/a{2,3}b/', 'message' => 'Lazy quantifier "a{2,3}?" matches what "a{2,3}" matches: the lazy marker changes nothing.'];
    }

    #[Test]
    #[DataProvider('provideUsefulLazy')]
    public function test_a_lazy_quantifier_that_changes_the_match_is_not_reported(string $pattern, string $greedy): void
    {
        $differs = false;
        foreach (self::SUBJECTS as $subject) {
            preg_match($pattern, $subject, $lazyMatches);
            preg_match($greedy, $subject, $greedyMatches);
            $differs = $differs || $lazyMatches !== $greedyMatches;
        }

        $this->assertTrue($differs, $pattern.' matches as '.$greedy.' on every subject');
        $this->assertSame([], $this->messages($pattern, true));
    }

    /**
     * @return iterable<string, array{pattern: string, greedy: string}>
     */
    public static function provideUsefulLazy(): iterable
    {
        yield 'a run before a character it takes' => ['pattern' => '/(a+?)a/', 'greedy' => '/(a+)a/'];
        yield 'a run before the same letter in another case' => ['pattern' => '/(a+?)A/i', 'greedy' => '/(a+)A/i'];
        yield 'a dot before a character' => ['pattern' => '/(.+?)b/', 'greedy' => '/(.+)b/'];
        yield 'nothing after the quantifier' => ['pattern' => '/x?(a*?)/', 'greedy' => '/x?(a*)/'];
        yield 'a group repeated before a character it may start with' => ['pattern' => '/((?:ab)+?)a/', 'greedy' => '/((?:ab)+)a/'];
    }

    /**
     * Under U, "+?" is the greedy one: the rule stays silent rather than
     * say it the other way round.
     */
    #[Test]
    public function test_the_u_flag_silences_the_rule(): void
    {
        $this->assertSame([], $this->messages('/(a+?)b/U', true));
        $this->assertSame([], $this->messages('/(?U)(a+?)b/', true));
    }

    /**
     * After an empty match, preg_match_all(), preg_replace() and
     * preg_split() try again at the same offset for a non-empty one, where
     * the lazy and the greedy forms part: preg_match() alone agrees.
     */
    #[Test]
    #[DataProvider('provideEmptyMatchingPatterns')]
    public function test_a_pattern_that_may_match_the_empty_string_is_not_reported(string $pattern, string $greedy, string $subject): void
    {
        preg_match_all($pattern, $subject, $lazyMatches);
        preg_match_all($greedy, $subject, $greedyMatches);
        $this->assertNotSame($greedyMatches, $lazyMatches, $pattern);

        $this->assertSame([], $this->messages($pattern, true));
    }

    /**
     * @return iterable<string, array{pattern: string, greedy: string, subject: string}>
     */
    public static function provideEmptyMatchingPatterns(): iterable
    {
        yield 'an optional group' => ['pattern' => '/(\d+?,?)??/', 'greedy' => '/(\d+,?)??/', 'subject' => '12'];
        yield 'an optional group of an optional tail' => ['pattern' => '/(?:a+?b?)??/', 'greedy' => '/(?:a+b?)??/', 'subject' => 'aa'];
        yield 'an empty alternative' => ['pattern' => '/|b{1,2}?./', 'greedy' => '/|b{1,2}./', 'subject' => 'bba'];
    }

    /**
     * A match limit the pattern sets is reached by the form that backtracks
     * more first: "(a+?)b" gives up where "(a+)b" matches.
     */
    #[Test]
    public function test_a_pattern_that_sets_a_limit_is_not_reported(): void
    {
        $subject = str_repeat('a', 30).'b';
        $this->assertFalse(@preg_match('/(*LIMIT_MATCH=20)(a+?)b/', $subject));
        $this->assertSame(1, preg_match('/(*LIMIT_MATCH=20)(a+)b/', $subject));

        $this->assertSame([], $this->messages('/(*LIMIT_MATCH=20)(a+?)b/', true));
        $this->assertSame([], $this->messages('/(*LIMIT_DEPTH=20)(a+?)b/', true));
        $this->assertSame([], $this->messages('/(*LIMIT_HEAP=20)(a+?)b/', true));
    }

    /**
     * A start anchor holds at offset 0 only: the pattern is proven with it
     * and again with it failing, as preg_match_all() goes on past it. Here
     * both forms write the same $matches either way.
     */
    #[Test]
    public function test_a_pattern_that_reads_the_start_is_proven_from_any_offset(): void
    {
        foreach (['xaab', 'aab', 'xab'] as $subject) {
            foreach ([0, 1] as $offset) {
                preg_match('/^x|(a+?)b/', $subject, $lazy, 0, $offset);
                preg_match('/^x|(a+)b/', $subject, $greedy, 0, $offset);
                $this->assertSame($greedy, $lazy, $subject.' at '.$offset);
            }
        }

        $this->assertSame(['Lazy quantifier "a+?" matches what "a+" matches: the lazy marker changes nothing.'], $this->messages('/^x|(a+?)b/', true));
        $this->assertSame(['Lazy quantifier "a+?" matches what "a+" matches: the lazy marker changes nothing.'], $this->messages('/\Ax|(a+?)b/', true));
    }

    #[Test]
    public function test_the_rule_is_off_by_default(): void
    {
        $this->assertSame([], $this->messages('/(a+?)b/', false));
    }

    /**
     * @return list<string>
     */
    private function messages(string $pattern, bool $enabled): array
    {
        $linter = new PatternLinter($enabled ? ['quantifier.uselessLazy' => true] : []);
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

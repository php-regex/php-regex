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

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A positive lookahead that ends a lookahead asserts what its body written
 * in its place asserts, "(?=a(?=b))" what "(?=ab)" does; so does a positive
 * lookbehind that starts a lookbehind. PHP 8.4.26 / PCRE2 10.49: each
 * reported pattern gives the same preg_match_all() result, offsets and
 * captures included, as the rewrite its tip names, on every subject below;
 * each silent one that has a rewrite differs on one at least.
 */
final class RedundantLookaroundTest extends TestCase
{
    private const RULE = 'regex.lint.lookaround.redundant';

    private const SUBJECTS = ['', 'a', 'b', 'ab', 'abc', 'abd', 'ac', 'ba', 'aab', 'abb', 'xab', 'cab', 'abab', 'Ab', 'aB', 'AB', 'cd', 'bcd', 'acd', 'x', 'xy', 'xb'];

    #[Test]
    #[DataProvider('provideInlinableLookarounds')]
    public function test_a_lookaround_its_parent_can_hold_is_reported(string $pattern, string $rewrite, string $message, string $hint): void
    {
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(
                [preg_match_all($rewrite, $subject, $expected, \PREG_OFFSET_CAPTURE), $expected],
                [preg_match_all($pattern, $subject, $actual, \PREG_OFFSET_CAPTURE), $actual],
                $pattern.' on '.$subject,
            );
        }

        $issues = $this->issues($pattern, true);
        $this->assertSame([[$message, $hint]], array_map(static fn (RuleViolation $issue): array => [$issue->message, (string) $issue->hint], $issues));
        $this->assertSame(LintSeverity::Style, $issues[0]->severity);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, message: string, hint: string}>
     */
    public static function provideInlinableLookarounds(): iterable
    {
        yield 'a lookahead ending a lookahead' => ['pattern' => '/(?=a(?=b))/', 'rewrite' => '/(?=ab)/', 'message' => '"(?=b)" ends a lookahead, which can hold its body: "(?=ab)" asserts the same.', 'hint' => 'Write "(?=ab)".'];
        yield 'a lookahead ending a negative lookahead' => ['pattern' => '/(?!a(?=b))/', 'rewrite' => '/(?!ab)/', 'message' => '"(?=b)" ends a lookahead, which can hold its body: "(?!ab)" asserts the same.', 'hint' => 'Write "(?!ab)".'];
        yield 'a lookbehind starting a lookbehind' => ['pattern' => '/(?<=(?<=a)b)/', 'rewrite' => '/(?<=ab)/', 'message' => '"(?<=a)" starts a lookbehind, which can hold its body: "(?<=ab)" asserts the same.', 'hint' => 'Write "(?<=ab)".'];
        yield 'a lookbehind starting a negative lookbehind' => ['pattern' => '/(?<!(?<=a)b)/', 'rewrite' => '/(?<!ab)/', 'message' => '"(?<=a)" starts a lookbehind, which can hold its body: "(?<!ab)" asserts the same.', 'hint' => 'Write "(?<!ab)".'];
        yield 'a body of alternatives, kept as a group' => ['pattern' => '/(?=a(?=b|c))/', 'rewrite' => '/(?=a(?:b|c))/', 'message' => '"(?=b|c)" ends a lookahead, which can hold its body: "(?=a(?:b|c))" asserts the same.', 'hint' => 'Write "(?=a(?:b|c))".'];
        yield 'the whole body of a lookahead' => ['pattern' => '/x(?=(?=b))/', 'rewrite' => '/x(?=b)/', 'message' => '"(?=b)" ends a lookahead, which can hold its body: "(?=b)" asserts the same.', 'hint' => 'Write "(?=b)".'];
        yield 'the end of one alternative' => ['pattern' => '/(?=x|a(?=b))./', 'rewrite' => '/(?=x|ab)./', 'message' => '"(?=b)" ends a lookahead, which can hold its body: "(?=x|ab)" asserts the same.', 'hint' => 'Write "(?=x|ab)".'];
        yield 'the start of one alternative' => ['pattern' => '/(?<=(?<=a)b|cd)/', 'rewrite' => '/(?<=ab|cd)/', 'message' => '"(?<=a)" starts a lookbehind, which can hold its body: "(?<=ab|cd)" asserts the same.', 'hint' => 'Write "(?<=ab|cd)".'];
        // An option set in the body would reach what follows it: the group
        // keeps it in.
        yield 'an option setting kept as a group' => ['pattern' => '/(?<=(?<=(?i)a)b)/', 'rewrite' => '/(?<=(?:(?i)a)b)/', 'message' => '"(?<=(?i)a)" starts a lookbehind, which can hold its body: "(?<=(?:(?i)a)b)" asserts the same.', 'hint' => 'Write "(?<=(?:(?i)a)b)".'];
        yield 'a capture in the body' => ['pattern' => '/(?=a(?=(b)))/', 'rewrite' => '/(?=a(b))/', 'message' => '"(?=(b))" ends a lookahead, which can hold its body: "(?=a(b))" asserts the same.', 'hint' => 'Write "(?=a(b))".'];
        yield 'a comment after the lookahead' => ['pattern' => '/(?=a(?=b)(?#c))/', 'rewrite' => '/(?=ab(?#c))/', 'message' => '"(?=b)" ends a lookahead, which can hold its body: "(?=ab(?#c))" asserts the same.', 'hint' => 'Write "(?=ab(?#c))".'];
    }

    #[Test]
    #[DataProvider('provideLookaroundsThatMatter')]
    public function test_a_lookaround_that_changes_what_is_asserted_is_not_reported(string $pattern, ?string $rewrite): void
    {
        if (null !== $rewrite) {
            $differs = false;
            foreach (self::SUBJECTS as $subject) {
                $differs = $differs || [preg_match_all($pattern, $subject, $actual, \PREG_OFFSET_CAPTURE), $actual] !== [preg_match_all($rewrite, $subject, $expected, \PREG_OFFSET_CAPTURE), $expected];
            }

            $this->assertTrue($differs, $pattern.' behaves as '.$rewrite);
        }

        $this->assertSame([], $this->issues($pattern, true));
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string|null}>
     */
    public static function provideLookaroundsThatMatter(): iterable
    {
        yield 'a negative lookahead inside' => ['pattern' => '/(?=a(?!b))/', 'rewrite' => '/(?=a[^b])/'];
        yield 'a lookahead that does not end the body' => ['pattern' => '/(?=a(?=b)c)/', 'rewrite' => null];
        yield 'a lookahead starting a lookahead' => ['pattern' => '/(?=(?=b)a)/', 'rewrite' => '/(?=ba)/'];
        yield 'a lookbehind ending a lookbehind' => ['pattern' => '/(?<=a(?<=b))/', 'rewrite' => '/(?<=ab)/'];
        yield 'a lookahead starting a lookbehind' => ['pattern' => '/(?<=(?=a)b)/', 'rewrite' => '/(?<=ab)/'];
        yield 'a lookbehind ending a lookahead' => ['pattern' => '/(?=a(?<=b))/', 'rewrite' => '/(?=ab)/'];
        yield 'a quantified lookahead' => ['pattern' => '/(?=a(?=b)?)/', 'rewrite' => null];
        yield 'a non-atomic outer lookahead' => ['pattern' => '/(*napla:a(?=b))/', 'rewrite' => null];
        yield 'a non-atomic inner lookahead' => ['pattern' => '/(?=a(*napla:b))/', 'rewrite' => null];
        // "(?<=(?:a|bc)d)" holds a nested alternation of two lengths, which
        // engines before PCRE2 10.43 refuse in a lookbehind.
        yield 'a lookbehind of several lengths' => ['pattern' => '/(?<=(?<=a|bc)d)/', 'rewrite' => null];
        yield 'a verb in the body' => ['pattern' => '/(?=a(?=b(*ACCEPT)))/', 'rewrite' => null];
        yield 'two lookaheads side by side' => ['pattern' => '/(?=a)(?=b)/', 'rewrite' => null];
        yield 'a body of comments only' => ['pattern' => '/a(?=(?#c)(?#d))/', 'rewrite' => null];
    }

    #[Test]
    public function test_the_rule_is_off_by_default(): void
    {
        $this->assertSame([], $this->issues('/(?=a(?=b))/', false));
    }

    /**
     * @return list<RuleViolation>
     */
    private function issues(string $pattern, bool $enabled): array
    {
        $linter = new PatternLinter($enabled ? ['lookaround.redundant' => true] : []);
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_filter($linter->getIssues(), static fn (RuleViolation $issue): bool => self::RULE === $issue->id));
    }
}

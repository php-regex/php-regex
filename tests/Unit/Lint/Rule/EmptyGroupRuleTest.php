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
 * An empty non-capturing or atomic group, "(?:)" or "(?>)", matches the
 * empty string and can go. Never "()", a placeholder idiom that numbers a
 * group; never a lookaround; never a "(?:)" that keeps an escape apart from
 * the digit after it ("(a)\1(?:)0" is not "(a)\10"), the form the library's
 * own printer writes.
 */
final class EmptyGroupRuleTest extends TestCase
{
    private const ID = 'regex.lint.group.empty';

    /**
     * One defect, one issue: the empty group is reported as empty, not
     * also as a redundant group.
     *
     * @param list<string> $issues
     */
    #[Test]
    #[DataProvider('provideEmptyGroups')]
    public function test_an_empty_group_is_reported(string $pattern, array $issues): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the group changes nothing.
        $this->assertSame(1, preg_match($pattern, 'ab', $matches));
        $this->assertSame(['ab'], $matches);
        $this->assertSame(1, preg_match('/ab/', 'ab'));

        $this->assertSame($issues, $this->issueIds($pattern));

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(LintSeverity::Warning, $violation->severity);
        // In both rows the empty group opens at offset 1.
        $this->assertSame(1, $violation->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, issues: list<string>}>
     */
    public static function provideEmptyGroups(): iterable
    {
        yield 'non-capturing' => ['pattern' => '/a(?:)b/', 'issues' => [self::ID]];
        yield 'atomic' => ['pattern' => '/a(?>)b/', 'issues' => [self::ID]];
    }

    #[Test]
    #[DataProvider('provideEmptyGroupsThatMeanSomething')]
    public function test_an_empty_group_that_means_something_is_not_reported(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideEmptyGroupsThatMeanSomething(): iterable
    {
        yield 'empty capturing group, a placeholder' => ['pattern' => '/a()b/'];
        yield 'empty lookahead, its own rules' => ['pattern' => '/(?=)a/'];
        yield 'empty negative lookahead, its own rules' => ['pattern' => '/(?!)a/'];
    }

    /**
     * Taking the group out joins the escape and the digit into another
     * escape: the engine reads another pattern, or refuses it.
     */
    #[Test]
    #[DataProvider('provideSeparatingGroups')]
    public function test_an_empty_group_that_keeps_an_escape_from_a_digit_is_not_reported(string $pattern, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
        $this->assertNotSame(1, @preg_match(str_replace('(?:)', '', $pattern), $subject), 'Without the group the subject no longer matches.');

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideSeparatingGroups(): iterable
    {
        yield 'reference then digit' => ['pattern' => '/^(a)\1(?:)0$/', 'subject' => 'aa0'];
        yield '\g reference then digit' => ['pattern' => '/^(a)\g1(?:)0$/', 'subject' => 'aa0'];
        yield 'octal \0 then digit' => ['pattern' => '/^\0(?:)1$/', 'subject' => "\x001"];
        yield 'two-digit octal then digit' => ['pattern' => '/^\01(?:)7$/', 'subject' => "\x017"];
        yield 'one-digit hex then hex digit' => ['pattern' => '/^\xa(?:)b$/', 'subject' => "\nb"];
    }

    /**
     * Taking the group out writes a quantifier: "a{(?:)2}" reads "{2}"
     * literally, "a{2}" repeats the "a". "{,2}" is a quantifier since
     * PCRE2 10.43, and the braces allow spaces.
     */
    #[Test]
    #[DataProvider('provideGroupsInsideBraces')]
    public function test_an_empty_group_that_keeps_braces_from_a_quantifier_is_not_reported(string $pattern, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
        $this->assertSame(0, preg_match(str_replace('(?:)', '', $pattern), $subject), 'Without the group the braces are a quantifier.');

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideGroupsInsideBraces(): iterable
    {
        yield 'opening brace then count' => ['pattern' => '/^a{(?:)2}$/', 'subject' => 'a{2}'];
        yield 'lower bound then upper bound' => ['pattern' => '/^a{1,(?:)2}$/', 'subject' => 'a{1,2}'];
        yield 'opening brace then comma' => ['pattern' => '/^a{(?:),2}$/', 'subject' => 'a{,2}'];
        yield 'count then closing brace' => ['pattern' => '/^a{2(?:)}$/', 'subject' => 'a{2}'];
        yield 'spaces in the braces' => ['pattern' => '/^a{ (?:) 2 }$/', 'subject' => 'a{  2 }'];
    }

    /**
     * The group is the operand of a quantifier: taking it out hands the
     * quantifier to the item before it. A bounded repeat is left alone,
     * an unbounded one is quantifier.emptyRepeat's.
     */
    #[Test]
    #[DataProvider('provideQuantifiedEmptyGroups')]
    public function test_an_empty_group_under_a_quantifier_is_not_reported(string $pattern, string $without, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the quantifier moves to the "a".
        $this->assertNotSame(preg_match($pattern, $subject), preg_match($without, $subject), $pattern);

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, subject: string}>
     */
    public static function provideQuantifiedEmptyGroups(): iterable
    {
        yield 'optional' => ['pattern' => '/^a(?:)?$/', 'without' => '/^a?$/', 'subject' => ''];
        yield 'count' => ['pattern' => '/^a(?:){2}$/', 'without' => '/^a{2}$/', 'subject' => 'a'];
        yield 'atomic plus' => ['pattern' => '/^a(?>)+$/', 'without' => '/^a+$/', 'subject' => 'aa'];
    }

    /**
     * @return list<string> the issue ids, sorted
     */
    private function issueIds(string $pattern): array
    {
        $ids = array_map(static fn (RuleViolation $issue): string => $issue->id, $this->lint($pattern));
        sort($ids);

        return $ids;
    }

    private function violation(string $pattern): ?RuleViolation
    {
        foreach ($this->lint($pattern) as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * @return array<RuleViolation>
     */
    private function lint(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        return $linter->getIssues();
    }
}

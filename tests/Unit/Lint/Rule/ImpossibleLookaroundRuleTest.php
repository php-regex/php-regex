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
 * A lookahead that contradicts what follows it: "(?=a)b" asks for an "a"
 * where the pattern reads a "b", "(?!a)a" forbids the "a" it reads. Decided
 * by the automata on the regular subset, the continuation running through
 * the enclosing groups and quantifiers to the end of the pattern; silent
 * outside the subset (backreferences, lookbehinds, \K in the continuation).
 */
final class ImpossibleLookaroundRuleTest extends TestCase
{
    private const ID = 'regex.lint.lookaround.impossible';

    private const SUBJECTS = ['', 'a', 'b', 'c', 'ab', 'ba', 'aa', 'bb', 'ac', 'abc', 'xa', 'xb', 'ya', 'yb', 'xab', 'xxb', 'A', '1', '5', '12', "\n", "a\n", 'bab'];

    #[Test]
    #[DataProvider('provideContradictoryLookaheads')]
    public function test_a_lookahead_the_continuation_contradicts_is_reported(string $pattern): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: no subject matches.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), \sprintf('%s matches %s', $pattern, json_encode($subject)));
        }

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Warning, $violation->severity);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideContradictoryLookaheads(): iterable
    {
        yield 'positive, other literal' => ['pattern' => '/(?=a)b/'];
        yield 'negative, same literal' => ['pattern' => '/(?!a)a/'];
        yield 'positive, alternation of other literals' => ['pattern' => '/(?=a)(?:b|c)/'];
        yield 'negative, same class' => ['pattern' => '/(?!\d)\d/'];
        yield 'negative, continuation starts with the whole body' => ['pattern' => '/(?!ab)ab/'];
        yield 'positive, two characters of body' => ['pattern' => '/(?=ab)a[^b]/'];
        yield 'inside a repeated group' => ['pattern' => '/(?:(?=a)b)+/'];
        // The continuation of "x(?=a)" runs out of the group: another "x"
        // or the "b", never an "a".
        yield 'continuation through the enclosing group and its quantifier' => ['pattern' => '/(?:x(?=a))+b/'];
        yield 'case-sensitive' => ['pattern' => '/(?=a)A/'];
        yield 'dot without s cannot be a newline' => ['pattern' => '/(?=.)\n/'];
        // Past its 24-byte budget the continuation is cut short: the "b"
        // kept already contradicts the lookahead.
        yield 'continuation cut short after the contradicting item' => ['pattern' => '/(?=a)b(?:cccccccccccccccccccccccccccccccc)/'];
        // The repeated group is longer than the budget: the "b" before it
        // in the sequence is read, the repeat is not.
        yield 'repeated group past the budget' => ['pattern' => '/(?:xxxxxxxxxxxxxxxxxxxxxxxxx(?=a)b)+/'];
        // The continuation of the inner lookahead ends with the lookahead
        // holding it.
        yield 'inside another lookahead' => ['pattern' => '/x(?=(?=a)b)/'];
    }

    /**
     * The contradiction sits in one branch only: the branch is reported,
     * the pattern still matches through the other.
     */
    #[Test]
    public function test_a_contradiction_in_one_branch_is_reported(): void
    {
        $this->assertSame(1, preg_match('/(?:x(?=a)|y)b/', 'yb'));
        $this->assertSame(0, preg_match('/(?:x(?=a)|y)b/', 'xab'));

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(?:x(?=a)|y)b/'));
    }

    #[Test]
    #[DataProvider('provideConsistentLookaheads')]
    public function test_a_lookahead_the_continuation_can_satisfy_is_not_reported(string $pattern, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the subject matches.
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideConsistentLookaheads(): iterable
    {
        yield 'positive, class holding the body' => ['pattern' => '/(?=a)[ab]/', 'subject' => 'a'];
        yield 'positive, same class' => ['pattern' => '/(?=\d)\d/', 'subject' => '5'];
        yield 'positive, alternation holding the body' => ['pattern' => '/(?=a)(?:a|b)/', 'subject' => 'a'];
        yield 'negative, class wider than the body' => ['pattern' => '/(?!a)[ab]/', 'subject' => 'b'];
        yield 'negative, longer body' => ['pattern' => '/(?!ab)a/', 'subject' => 'a'];
        // The continuation may match the empty string: the lookahead then
        // looks past the end of the match.
        yield 'optional continuation' => ['pattern' => '/(?=a)b?/', 'subject' => 'a'];
        yield 'continuation through the enclosing group' => ['pattern' => '/(?:x(?=a)|y)a/', 'subject' => 'xa'];
        yield 'nothing follows' => ['pattern' => '/x(?=a)/', 'subject' => 'xa'];
        yield 'the lookahead is the whole pattern' => ['pattern' => '/(?=a)/', 'subject' => 'a'];
        yield 'the lookahead is all a group holds' => ['pattern' => '/(?:(?=a))/', 'subject' => 'a'];
        yield 'caseless' => ['pattern' => '/(?=a)A/i', 'subject' => 'A'];
        yield 'dot under s reads a newline' => ['pattern' => '/(?=.)\n/s', 'subject' => "\n"];
        yield 'ASCII digit option under u' => ['pattern' => '/(?aD)(?!\d)٣/u', 'subject' => '٣'];
        yield 'caseless restrict keeps the Kelvin sign apart' => ['pattern' => '/(?r)(?!k)\x{212A}/iu', 'subject' => "\u{212A}"];
        // "(?^i)" turns "r" off with the other options.
        yield 'caseless restrict reset by a caret' => ['pattern' => '/(?r)(?^i)(?=k)\x{212A}/u', 'subject' => "\u{212A}"];
        yield 'caseless restrict in the continuation only' => ['pattern' => '/(?=k)(?r:\x{212A})/iu', 'subject' => "\u{212A}"];
        yield 'double extended mode drops the space from the class' => ['pattern' => '/(?xx)(?![a b])\ /', 'subject' => ' '];
    }

    /**
     * "(?-r)" inside a pattern under /r folds the Kelvin sign with "k"
     * again: the lookahead forbids it.
     */
    #[Test]
    public function test_caseless_restrict_turned_off_inside_the_pattern_is_read(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        foreach (["\u{212A}", 'k', 'K', ''] as $subject) {
            $this->assertSame(0, preg_match('/(?-r)(?!k)\x{212A}/iur', $subject), var_export($subject, true));
        }
        $this->assertSame(1, preg_match('/(?!k)\x{212A}/iur', "\u{212A}"));

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(?-r)(?!k)\x{212A}/iur'));
        $this->assertNull($this->violation('/(?!k)\x{212A}/iur'));
    }

    /**
     * "(?^)" turns "r" off with the other options, set inline or by the
     * modifier: under "(?^i)" the lookahead forbids the Kelvin sign again.
     */
    #[Test]
    public function test_caseless_restrict_reset_by_a_caret_is_read(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        foreach (['/(?r)(?^i)(?!k)\x{212A}/u', '/(?^i)(?!k)\x{212A}/ur'] as $pattern) {
            foreach (["\u{212A}", 'k', 'K', ''] as $subject) {
                $this->assertSame(0, preg_match($pattern, $subject), $pattern.' '.var_export($subject, true));
            }

            $this->assertInstanceOf(RuleViolation::class, $this->violation($pattern), $pattern);
        }

        $this->assertSame(1, preg_match('/(?r)(?i)(?!k)\x{212A}/u', "\u{212A}"));
        $this->assertNull($this->violation('/(?r)(?i)(?!k)\x{212A}/u'));
    }

    /**
     * Deliberate loss of recall: each pattern below never matches (the
     * engine agrees), but its continuation leaves the regular subset the
     * automata decide, so the rule stays silent rather than guess.
     */
    #[Test]
    #[DataProvider('provideContinuationsOutsideTheRegularSubset')]
    public function test_a_continuation_outside_the_regular_subset_is_not_reported(string $pattern): void
    {
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), \sprintf('%s matches %s', $pattern, json_encode($subject)));
        }

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideContinuationsOutsideTheRegularSubset(): iterable
    {
        yield 'backreference' => ['pattern' => '/(b)(?=a)\1/'];
        yield 'lookbehind' => ['pattern' => '/(?=a)(?<!x)b/'];
        yield 'match reset' => ['pattern' => '/(?=a)\Kb/'];
        yield 'backreference inside a group' => ['pattern' => '/(a)(?=b)(?:\1)/'];
        yield 'lookbehind inside a group' => ['pattern' => '/(?=a)(?:(?<!x)b)/'];
        // A lookahead inside a lookbehind: the rule does not read through
        // the lookbehind.
        yield 'inside a lookbehind' => ['pattern' => '/(?<=(?=a)b)c/'];
    }

    /**
     * The lookahead as the condition of a conditional: in "(?(?=a)b|c)"
     * the yes branch can never be taken (no subject matches through it),
     * but a condition is not followed by a continuation the rule reads, so
     * it stays silent. Deliberate loss of recall, like the rows above; and
     * the pattern after the conditional is no continuation of the
     * condition either.
     */
    #[Test]
    public function test_a_lookahead_used_as_a_condition_is_not_reported(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: only the no branch ever matches.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match('/c/', $subject, $expected), preg_match('/(?(?=a)b|c)/', $subject, $matches), var_export($subject, true));
            $this->assertSame($expected, $matches);
        }

        $this->assertNull($this->violation('/(?(?=a)b|c)/'));

        // What follows the conditional is not what follows its condition:
        // here the yes branch reads the "a" the condition asks for.
        $this->assertSame(1, preg_match('/(?(?=a)a|c)x/', 'ax', $matches));
        $this->assertSame(['ax'], $matches);
        $this->assertNull($this->violation('/(?(?=a)a|c)x/'));
    }

    /**
     * A lookahead longer than 32 bytes as written is not read: the
     * automata's work grows with it. The same contradiction, shorter, is
     * reported.
     */
    #[Test]
    public function test_a_lookahead_past_the_length_cap_is_not_reported(): void
    {
        $long = '/(?='.str_repeat('a', 30).')b/';
        // Oracle, PHP 8.4.26 / PCRE2 10.49: no subject matches.
        foreach ([...self::SUBJECTS, str_repeat('a', 30).'b'] as $subject) {
            $this->assertSame(0, preg_match($long, $subject));
        }

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(?='.str_repeat('a', 27).')b/'));
        $this->assertNull($this->violation($long));
    }

    /**
     * In UTF mode "\w", "\b" and the properties span the whole of Unicode,
     * whose automata take too long for a lint run: the rule stays silent
     * there, though the same pattern without u is reported.
     */
    #[Test]
    public function test_a_word_class_under_utf_mode_is_not_reported(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: no subject matches, with or without u.
        foreach ([...self::SUBJECTS, '_', 'é', ' é'] as $subject) {
            $this->assertSame(0, preg_match('/(?=\w)\W/u', $subject), var_export($subject, true));
            $this->assertSame(0, preg_match('/(?=\w)\W/', $subject), var_export($subject, true));
        }

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(?=\w)\W/'));
        $this->assertNull($this->violation('/(?=\w)\W/u'));
    }

    /**
     * The automata's work is capped for a lint run: "b{300}" needs more
     * states than a lint question may build, so there is no answer and the
     * rule stays silent, though the lookahead contradicts the "b" as much
     * as in "(?=a)b".
     */
    #[Test]
    public function test_a_continuation_past_the_automata_cap_is_not_reported(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: no subject matches.
        foreach ([...self::SUBJECTS, str_repeat('b', 300), 'a'.str_repeat('b', 300)] as $subject) {
            $this->assertSame(0, preg_match('/(?=a)b{300}/', $subject));
        }

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(?=a)b{3}/'));
        $this->assertNull($this->violation('/(?=a)b{300}/'));
    }

    private function violation(string $pattern): ?RuleViolation
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}

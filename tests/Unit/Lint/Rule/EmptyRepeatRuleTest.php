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
 * An unbounded quantifier whose body can match the empty string: the repeat
 * adds nothing the body does not match alone, and on a capturing group the
 * last, empty iteration overwrites the capture. One defect, one issue: where
 * another rule already reports the same repeat (an empty alternative, a
 * quantified lookaround, a nested quantifier), this one stays silent.
 */
final class EmptyRepeatRuleTest extends TestCase
{
    private const ID = 'regex.lint.quantifier.emptyRepeat';

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49.
     */
    #[Test]
    public function test_the_engine_ends_a_nullable_repeat_on_an_empty_iteration(): void
    {
        // The repeat takes "aaa", then one more empty iteration: group 1 is "".
        preg_match('/(a*)*/', 'aaa', $matches);
        $this->assertSame(['aaa', ''], $matches);
        // Without the repeat the group keeps what it read.
        preg_match('/(a*)/', 'aaa', $matches);
        $this->assertSame(['aaa', 'aaa'], $matches);

        preg_match('/(a?)+/', 'aa', $matches);
        $this->assertSame(['aa', ''], $matches);

        // Non-capturing: the repeat is redundant, the match is the body's.
        preg_match('/(?:a*)+/', 'aaa', $matches);
        $this->assertSame(['aaa'], $matches);
    }

    /**
     * @param list<string> $issues
     */
    #[Test]
    #[DataProvider('provideNullableRepeats')]
    public function test_an_unbounded_repeat_of_a_nullable_body_is_reported(string $pattern, array $issues): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
        $this->assertSame($issues, $this->issueIds($pattern));

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame(LintSeverity::Warning, $violation->severity);
    }

    /**
     * @return iterable<string, array{pattern: string, issues: list<string>}>
     */
    public static function provideNullableRepeats(): iterable
    {
        // The quantified capture is a second defect (only the last iteration
        // is kept): both are reported.
        yield 'star on a capturing star' => ['pattern' => '/(a*)*/', 'issues' => ['regex.lint.group.quantifiedCapture', self::ID]];
        yield 'plus on a capturing optional' => ['pattern' => '/(a?)+/', 'issues' => ['regex.lint.group.quantifiedCapture', self::ID]];
        yield 'plus on a non-capturing star' => ['pattern' => '/(?:a*)+/', 'issues' => [self::ID]];
        yield 'star on a sequence of optionals' => ['pattern' => '/(?:a?b?)*/', 'issues' => [self::ID]];
        yield 'plus on an alternation with an optional branch' => ['pattern' => '/(?:a|b?)+/', 'issues' => [self::ID]];
        yield 'plus on a zero-lower-bound range' => ['pattern' => '/(?:a{0,2})+/', 'issues' => [self::ID]];
        // A body made of a group holding only a lookahead: no other rule
        // reports it, the quantifier on the lookaround being one level down.
        yield 'plus on a group holding only a lookahead' => ['pattern' => '/(?:(?=a))+b/', 'issues' => [self::ID]];
        // The capture sits one level inside the repeated group: the
        // capture still changes, and the group is not itself quantified.
        yield 'star on a non-capturing group around a capture' => ['pattern' => '/(?:(a?))*/', 'issues' => [self::ID]];
        // A conditional matches empty when either branch does:
        // preg_match('/^(?:(?(?=a)a|b?))*$/', '') is 1.
        yield 'star on a conditional with an optional branch' => ['pattern' => '/(?:(?(?=a)a|b?))*/', 'issues' => [self::ID]];
    }

    /**
     * @param list<string> $issues
     */
    #[Test]
    #[DataProvider('provideRepeatsReportedElsewhereOrSound')]
    public function test_a_repeat_another_rule_reports_or_a_sound_repeat_is_not_reported(string $pattern, array $issues): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
        $this->assertSame($issues, $this->issueIds($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, issues: list<string>}>
     */
    public static function provideRepeatsReportedElsewhereOrSound(): iterable
    {
        // One defect, one issue.
        yield 'empty alternative owns it' => ['pattern' => '/(|a)+/', 'issues' => ['regex.lint.alternation.empty', 'regex.lint.group.quantifiedCapture']];
        // The empty alternative two groups down is still alternation.empty's.
        yield 'empty alternative deeper in a sequence owns it' => ['pattern' => '/(?:(?:|b)c?)*/', 'issues' => ['regex.lint.alternation.empty']];
        yield 'quantified lookahead owns it' => ['pattern' => '/(?=a)*b/', 'issues' => ['regex.lint.quantifier.assertion']];
        yield 'quantified negative lookahead owns it' => ['pattern' => '/(?!a)+b/', 'issues' => ['regex.lint.quantifier.assertion']];
        yield 'nested quantifier owns it' => ['pattern' => '/(?:a*)*b/', 'issues' => ['regex.lint.quantifier.nested']];
        yield 'nested quantifier owns it before an anchor' => ['pattern' => '/(a*)*$/', 'issues' => ['regex.lint.group.quantifiedCapture', 'regex.lint.quantifier.nested']];
        yield 'nested quantifier owns an unbounded range' => ['pattern' => '/(a*){2,}/', 'issues' => ['regex.lint.group.quantifiedCapture', 'regex.lint.quantifier.nested']];
        // Bounded repeats are not this rule's.
        yield 'bounded count' => ['pattern' => '/(a*){2}/', 'issues' => ['regex.lint.group.quantifiedCapture']];
        yield 'optional' => ['pattern' => '/(?:a*)?/', 'issues' => []];
        // The body cannot match the empty string.
        yield 'body that must read a character' => ['pattern' => '/(?:a*b)*/', 'issues' => []];
    }

    #[Test]
    public function test_a_capturing_group_is_said_to_change_the_capture(): void
    {
        $violation = $this->violation('/(a*)*/');

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('redundant, and changes the capture', $violation->message);
    }

    /**
     * A capture inside the repeated group changes as well: the message says
     * so wherever the capture sits under the quantifier.
     */
    #[Test]
    public function test_a_capture_nested_in_the_repeated_group_is_said_to_change_the_capture(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the last, empty iteration sets group 1 to "".
        preg_match('/(?:(a?))*/', 'aa', $matches);
        $this->assertSame(['aa', ''], $matches);
        preg_match('/(?:x?(a?))*/', 'xaxa', $matches);
        $this->assertSame(['xaxa', ''], $matches);

        foreach (['/(?:(a?))*/', '/(?:x?(a?))*/'] as $pattern) {
            $violation = $this->violation($pattern);
            $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
            $this->assertSame('Quantifier "*" repeats a group that can match the empty string: the last, empty iteration is redundant, and changes the capture to "".', $violation->message);
            $this->assertNull($violation->hint, 'No rewrite tip on a repeat holding a capture.');
        }
    }

    /**
     * A capture the empty path goes around, or one inside a lookaround,
     * keeps what it read: the message says nothing about it.
     *
     * @param array<int|string, string> $groups
     */
    #[Test]
    #[DataProvider('provideCapturesTheEmptyPathSkips')]
    public function test_a_capture_the_empty_path_goes_around_is_not_said_to_change(string $pattern, string $subject, array $groups): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the last, empty iteration leaves the capture as it was.
        preg_match($pattern, $subject, $matches);
        $this->assertSame($groups, $matches);

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame('Quantifier "*" repeats an item that can match the empty string: an empty iteration matches nothing more.', $violation->message);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, groups: array<int|string, string>}>
     */
    public static function provideCapturesTheEmptyPathSkips(): iterable
    {
        yield 'optional alternative beside the capture' => ['pattern' => '/(?:(a)|b?)*/', 'subject' => 'aa', 'groups' => ['aa', 'a']];
        yield 'star alternative beside the capture' => ['pattern' => '/(?:(a)|x*)*/', 'subject' => 'aa', 'groups' => ['aa', 'a']];
        // DEFINE matches the empty string without running the group it holds.
        yield 'capture inside DEFINE' => ['pattern' => '/(?:(?(DEFINE)(?<n>a))|b)*/', 'subject' => 'bb', 'groups' => ['']];
        // A capture inside a lookaround keeps what the lookaround read:
        // the empty iteration sets it to "a", not "".
        yield 'capture inside a lookahead' => ['pattern' => '/(?:x|(?=(a)))*/', 'subject' => 'xa', 'groups' => ['x', 'a']];
        yield 'named capture inside a lookahead' => ['pattern' => '/(?:x|(?=(?<n>a)))*/', 'subject' => 'xa', 'groups' => ['x', 'n' => 'a', 1 => 'a']];
        yield 'capture inside a lookbehind' => ['pattern' => '/x(?:y|(?<=(x)))*/', 'subject' => 'x', 'groups' => ['x', 'x']];
    }

    /**
     * An item whose only empty way is an always-failing verb or lookahead
     * cannot match the empty string: the repeat is sound.
     */
    #[Test]
    #[DataProvider('provideItemsFailingRatherThanMatchingEmpty')]
    public function test_an_item_failing_rather_than_matching_empty_is_not_reported(string $pattern, string $item): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the item never matches the empty string.
        $this->assertSame(0, preg_match($item, ''), $item);

        $this->assertNull($this->violation($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, item: string}>
     */
    public static function provideItemsFailingRatherThanMatchingEmpty(): iterable
    {
        yield 'fail verb' => ['pattern' => '/(?:a|(*F))*/', 'item' => '/^(?:a|(*F))$/'];
        yield 'long fail verb' => ['pattern' => '/(?:a|(*FAIL))*/', 'item' => '/^(?:a|(*FAIL))$/'];
        yield 'named fail verb' => ['pattern' => '/(?:a|(*FAIL:x))*/', 'item' => '/^(?:a|(*FAIL:x))$/'];
        yield 'empty negative lookahead' => ['pattern' => '/(?:a|(?!))*/', 'item' => '/^(?:a|(?!))$/'];
        yield 'empty negative lookbehind' => ['pattern' => '/(?:a|(?<!))*/', 'item' => '/^(?:a|(?<!))$/'];
        yield 'fail verb first' => ['pattern' => '/x(?:(*F)|a)*y/', 'item' => '/^(?:(*F)|a)$/'];
        yield 'skip then fail' => ['pattern' => '/(?:\d+|(*SKIP)(*F))*/', 'item' => '/^(?:\d+|(*SKIP)(*F))$/'];
    }

    /**
     * The tip rewrites the repeat only where the rewrite matches the same
     * text: a group around one optional greedy item under a greedy repeat
     * is that item starred.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideRepeatsWithARewrite')]
    public function test_the_tip_rewrites_a_repeat_of_one_optional_item(string $pattern, string $rewrite, string $tip, array $subjects): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the rewrite matches the same text.
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $original), preg_match($rewrite, $subject, $rewritten), var_export($subject, true));
            $this->assertSame($original, $rewritten, var_export($subject, true));
        }

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(\sprintf('Write "%s" instead.', $tip), $violation->hint);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, tip: string, subjects: list<string>}>
     */
    public static function provideRepeatsWithARewrite(): iterable
    {
        $subjects = ['', 'a', 'aa', 'aaa', 'b', 'ab', 'ba', 'aab'];

        yield 'plus on a star' => ['pattern' => '/(?:a*)+/', 'rewrite' => '/a*/', 'tip' => 'a*', 'subjects' => $subjects];
        yield 'star on an optional' => ['pattern' => '/x(?:a?)*/', 'rewrite' => '/xa*/', 'tip' => 'a*', 'subjects' => ['x', 'xa', 'xaa', 'xb', 'axaab']];
        yield 'plus on a zero-lower-bound range' => ['pattern' => '/(?:a{0,2})+/', 'rewrite' => '/a*/', 'tip' => 'a*', 'subjects' => $subjects];
        yield 'star on a shorthand range' => ['pattern' => '/(?:\\d{0,3})*/', 'rewrite' => '/\\d*/', 'tip' => '\\d*', 'subjects' => ['', '1', '12345', 'a1', '1a2']];
        // A quoted span closed before the group leaves the operand bare.
        yield 'after a closed quote' => ['pattern' => '/\\Qx*\\E(?:a*)+/', 'rewrite' => '/\\Qx*\\Ea*/', 'tip' => 'a*', 'subjects' => ['x*', 'x*aa', 'xa', 'ax*a']];
    }

    /**
     * No rewrite fits these repeats in a few characters: the issue comes
     * without a tip rather than with one that changes the pattern.
     */
    #[Test]
    #[DataProvider('provideRepeatsWithoutARewrite')]
    public function test_no_tip_is_given_where_no_rewrite_applies(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertNull($violation->hint, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRepeatsWithoutARewrite(): iterable
    {
        // "a+" for "a*" does not fit: the item is an alternation, or a
        // sequence of optionals.
        yield 'alternation with an optional branch' => ['pattern' => '/(?:a|b?)+/'];
        yield 'sequence of optionals' => ['pattern' => '/(?:a?b?)*/'];
        yield 'group holding only a lookahead' => ['pattern' => '/(?:(?=a))+b/'];
        // Lazy repeats try the empty iteration first: no shorter form is
        // as plain.
        yield 'lazy inner repeat' => ['pattern' => '/(?:a*?)+/'];
        yield 'lazy outer repeat' => ['pattern' => '/(?:a*)+?/'];
        // "(a)\1(?:0?)+" is not "(a)\10*".
        yield 'rewrite joining a reference and a digit' => ['pattern' => '/(a)\1(?:0?)+/'];
        // A capture in the inner repeat, direct or one group down.
        yield 'capture repeated from zero' => ['pattern' => '/(?:(a)*)+/'];
        yield 'capture one group down repeated from zero' => ['pattern' => '/(?:(?:(a))*)+/'];
        // The quoted star would come out bare: "**" does not compile.
        yield 'quoted metacharacter' => ['pattern' => '/(?:\Q*\E*)+/'];
        // An inner repeat at most zero times matches only the empty string:
        // "a*" would read the a's the pattern leaves alone.
        yield 'plus on an exact zero count' => ['pattern' => '/(?:a{0})+/'];
        yield 'star on an exact zero count' => ['pattern' => '/(?:a{0})*/'];
        yield 'plus on a zero-to-zero range' => ['pattern' => '/(?:a{0,0})+/'];
        yield 'plus on a range with no lower bound up to zero' => ['pattern' => '/(?:a{,0})+/'];
        yield 'star on a class repeated zero times' => ['pattern' => '/(?:[a]{0})*/'];
        yield 'unbounded range on an exact zero count' => ['pattern' => '/(?:a{0}){3,}/'];
    }

    #[Test]
    public function test_the_engine_reads_nothing_under_a_repeat_at_most_zero_times(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the zero-count item reads nothing, "a*" reads the a's.
        preg_match('/(?:a{0}){2,}/', 'aaa', $matches);
        $this->assertSame([''], $matches);
        preg_match('/a*/', 'aaa', $matches);
        $this->assertSame(['aaa'], $matches);
        // A maximum of one is still the star: "(?:a{0,1})+" reads "aaa".
        preg_match('/(?:a{0,1})+/', 'aaa', $matches);
        $this->assertSame(['aaa'], $matches);
        $this->assertSame('Write "a*" instead.', $this->violation('/(?:a{0,1})+/')?->hint);
    }

    /**
     * An empty group under a repeat is this rule's alone: group.empty
     * stays silent on the operand of a quantifier.
     */
    #[Test]
    public function test_a_repeated_empty_group_is_reported_once(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame(1, preg_match('/^a(?>)+$/', 'a'));

        $this->assertSame([self::ID], $this->issueIds('/^a(?>)+$/'));
    }

    /**
     * With quantifier.nested turned off, "(?:a*)*b" is still reported once,
     * by this rule.
     */
    #[Test]
    public function test_the_rule_reports_a_repeat_whose_owner_is_off_once(): void
    {
        $linter = new PatternLinter(['quantifier.nested' => false]);
        Regex::create()->parse('/(?:a*)*b/')->accept($linter);

        $this->assertSame([self::ID], array_map(static fn (RuleViolation $issue): string => $issue->id, $linter->getIssues()));
    }

    #[Test]
    public function test_a_non_capturing_group_is_not_said_to_change_a_capture(): void
    {
        $violation = $this->violation('/(?:a*)+/');

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringNotContainsString('capture', $violation->message);
    }

    #[Test]
    public function test_the_issue_points_at_the_repeated_item(): void
    {
        $violation = $this->violation('/x(?:a*)+/');

        $this->assertInstanceOf(RuleViolation::class, $violation);
        // Like quantifier.lazyEnd: the offset of the quantified item, the
        // group at 1 in "x(?:a*)+".
        $this->assertSame(1, $violation->offset);
    }

    /**
     * The rule defers to the rule that owns the repeat only while that rule
     * is on: turned off, the defect is still reported once.
     *
     * @param array<string, bool> $rules
     */
    #[Test]
    #[DataProvider('provideOwnersTurnedOff')]
    public function test_a_repeat_whose_owner_is_turned_off_is_reported(string $pattern, array $rules): void
    {
        $linter = new PatternLinter($rules);
        Regex::create()->parse($pattern)->accept($linter);
        $ids = array_map(static fn (RuleViolation $issue): string => $issue->id, $linter->getIssues());

        $this->assertContains(self::ID, $ids, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, rules: array<string, bool>}>
     */
    public static function provideOwnersTurnedOff(): iterable
    {
        yield 'nested quantifier off' => ['pattern' => '/(?:a*)*b/', 'rules' => ['quantifier.nested' => false]];
        yield 'quantified assertion off' => ['pattern' => '/(?=a)*b/', 'rules' => ['quantifier.assertion' => false]];
        yield 'empty alternative off' => ['pattern' => '/(?:|a)+/', 'rules' => ['alternation.empty' => false]];
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

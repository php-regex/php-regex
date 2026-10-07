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
 * An anchor binds tighter than "|": "/^a|b|c$/" is "^a" or "b" or "c$".
 * The rule speaks of intent, never claims the pattern is wrong: it fires
 * when the first or the last top-level branch is anchored and at least one
 * branch has no anchor on either side, and stays silent when every branch
 * is anchored on one side (the trim idiom "/^\s+|\s+$/").
 */
final class AlternationPrecedenceRuleTest extends TestCase
{
    private const ID = 'regex.lint.anchor.alternationPrecedence';

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49: the middle branch is unanchored.
     */
    #[Test]
    public function test_the_engine_binds_the_anchors_to_the_outer_branches_only(): void
    {
        preg_match('/^a|b|c$/', 'xbx', $matches);
        $this->assertSame(['b'], $matches);
        $this->assertSame(0, preg_match('/^(?:a|b|c)$/', 'xbx'));

        preg_match('/^a|b/', 'xb', $matches);
        $this->assertSame(['b'], $matches);
    }

    #[Test]
    #[DataProvider('provideAnchorsThatSkipABranch')]
    public function test_an_anchor_that_leaves_a_branch_unanchored_is_reported(string $pattern, string $grouped): void
    {
        $violation = $this->violation($pattern);

        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Warning, $violation->severity);
        $this->assertNotNull($violation->hint);
        $this->assertStringContainsString($grouped, (string) $violation->hint, 'The tip shows the grouped form.');
    }

    /**
     * @return iterable<string, array{pattern: string, grouped: string}>
     */
    public static function provideAnchorsThatSkipABranch(): iterable
    {
        yield 'both ends, middle branch bare' => ['pattern' => '/^a|b|c$/', 'grouped' => '^(?:a|b|c)$'];
        yield 'start anchor only' => ['pattern' => '/^a|b/', 'grouped' => '^(?:a|b)'];
        yield 'end anchor only' => ['pattern' => '/a|b$/', 'grouped' => '(?:a|b)$'];
        yield 'subject start' => ['pattern' => '/\Aa|b/', 'grouped' => '\A(?:a|b)'];
        yield 'subject end' => ['pattern' => '/a|b\z/', 'grouped' => '(?:a|b)\z'];
        yield 'previous match end' => ['pattern' => '/\Ga|b/', 'grouped' => '\G(?:a|b)'];
        // An option setting carries into the branches after its own: the
        // tip keeps it.
        yield 'options before the start anchor' => ['pattern' => '/(?i)^a|b/', 'grouped' => '(?i)^(?:a|b)'];
        yield 'end anchor inside a group' => ['pattern' => '/^a|b|(?:c$)/', 'grouped' => '^(?:a|b|(?:c))$'];
        yield 'lookahead asserting the end' => ['pattern' => '/^a|b|c(?=$)/', 'grouped' => '^(?:a|b|c)$'];
        yield 'start anchor inside a capturing group' => ['pattern' => '/(^a)|b/', 'grouped' => '^(?:(a)|b)'];
        // The comment-only branch matches the empty string anywhere, as in
        // the pattern: the tip keeps it, empty.
        yield 'comment-only branch kept empty' => ['pattern' => '/^a|(?#c)|b/', 'grouped' => '^(?:a||b)'];
        // SonarPHP reports this one too: the middle branch has no anchor.
        yield 'outer branches anchored, middle one bare' => ['pattern' => '/^[_ ]|[\\r\\n\\t]|[_ ]$/', 'grouped' => '^(?:[_ ]|[\\r\\n\\t]|[_ ])$'];
    }

    /**
     * The tip is a pattern: read under the flags of the one linted, it
     * compiles, and every branch it groups is anchored.
     */
    #[Test]
    #[DataProvider('provideTipsUnderTheirFlags')]
    public function test_the_tip_compiles_under_the_pattern_flags(string $pattern, string $flags, string $grouped, string $subject): void
    {
        $violation = $this->violation('/'.$pattern.'/'.$flags);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(\sprintf('If the anchors are meant for every alternative, group them: "%s".', $grouped), $violation->hint);

        // Oracle, PHP 8.4.26 / PCRE2 10.49: the linted pattern matches the
        // bare branch inside the subject, the tip does not.
        $this->assertSame(1, preg_match('/'.$pattern.'/'.$flags, $subject));
        $this->assertSame(0, preg_match('/'.$grouped.'/'.$flags, $subject), preg_last_error_msg());
    }

    /**
     * @return iterable<string, array{pattern: string, flags: string, grouped: string, subject: string}>
     */
    public static function provideTipsUnderTheirFlags(): iterable
    {
        // The comment would swallow the closing parenthesis.
        yield 'extended-mode comment ending the last branch' => ['pattern' => '^a|b # c', 'flags' => 'x', 'grouped' => '^(?:a|b)', 'subject' => 'xb'];
        yield 'extended-mode comment ending a middle branch' => ['pattern' => "^a|b # c\n|c # d", 'flags' => 'x', 'grouped' => '^(?:a|b|c)', 'subject' => 'xb'];
        yield 'extended-mode comment before the end anchor' => ['pattern' => "a|b # c\n\$", 'flags' => 'x', 'grouped' => '(?:a|b)$', 'subject' => 'ax'];
        yield 'options before the start anchor' => ['pattern' => '(?i)^a|b', 'flags' => '', 'grouped' => '(?i)^(?:a|b)', 'subject' => 'xB'];
        yield 'comment opening a middle branch' => ['pattern' => '^a|(?#c)b|c', 'flags' => '', 'grouped' => '^(?:a|b|c)', 'subject' => 'xb'];
        yield 'end anchor inside a non-capturing group' => ['pattern' => '^a|b|(?:c$)', 'flags' => '', 'grouped' => '^(?:a|b|(?:c))$', 'subject' => 'xbx'];
    }

    /**
     * Text quoted with \Q...\E is quoted in the message and the tip as
     * well: without the quotes, "b)" does not compile, "b$" ends with an
     * anchor, and under /x "b#" opens a comment and " b" loses its space.
     *
     * @param list<string> $others
     */
    #[Test]
    #[DataProvider('provideQuotedAlternatives')]
    public function test_quoted_text_stays_quoted_in_the_message_and_the_tip(string $pattern, string $bare, string $grouped, string $anchored, string $inside, array $others): void
    {
        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(\sprintf('An anchor holds for its own alternative only: "%s" matches anywhere in the subject.', $bare), $violation->message);
        $this->assertSame(\sprintf('If the anchors are meant for every alternative, group them: "%s".', $grouped), $violation->hint);

        // Oracle, PHP 8.4.26 / PCRE2 10.49: the tip compiles under the
        // flags of the pattern, matches the quoted text where the anchor
        // holds, not the bare branch inside the subject, and matches no
        // subject the pattern does not.
        $tip = '/'.$grouped.'/'.substr($pattern, (int) strrpos($pattern, '/') + 1);
        $this->assertNotFalse(@preg_match($tip, ''), $tip);
        $this->assertSame(1, preg_match($pattern, $anchored));
        $this->assertSame(1, preg_match($tip, $anchored), $anchored);
        $this->assertSame(1, preg_match($pattern, $inside));
        $this->assertSame(0, preg_match($tip, $inside), $inside);
        foreach ($others as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), $subject);
            $this->assertSame(0, preg_match($tip, $subject), $subject);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, bare: string, grouped: string, anchored: string, inside: string, others: list<string>}>
     */
    public static function provideQuotedAlternatives(): iterable
    {
        yield 'quoted parenthesis in the bare branch' => ['pattern' => '/^a|\Qb)\E/', 'bare' => '\Qb)\E', 'grouped' => '^(?:a|\Qb)\E)', 'anchored' => 'b)', 'inside' => 'xb)', 'others' => ['b', 'xb']];
        yield 'quoted parenthesis after the start anchor' => ['pattern' => '/^\Qa)\E|b/', 'bare' => 'b', 'grouped' => '^(?:\Qa)\E|b)', 'anchored' => 'a)', 'inside' => 'xb', 'others' => ['a', 'xa)']];
        yield 'quoted opening parenthesis after the start anchor' => ['pattern' => '/^\Qa(\E|b/', 'bare' => 'b', 'grouped' => '^(?:\Qa(\E|b)', 'anchored' => 'a(', 'inside' => 'xb', 'others' => ['a', 'xa(']];
        yield 'quoted dollar ending the bare branch' => ['pattern' => '/^a|b\Q$\E/', 'bare' => 'b\Q$\E', 'grouped' => '^(?:a|b\Q$\E)', 'anchored' => 'b$', 'inside' => 'xb$', 'others' => ['b', 'xb']];
        yield 'quoted dollar in the bare branch' => ['pattern' => '/^a|\Qb$\E/', 'bare' => '\Qb$\E', 'grouped' => '^(?:a|\Qb$\E)', 'anchored' => 'b$', 'inside' => 'xb$', 'others' => ['b', 'xb']];
        yield 'quoted hash under extended mode' => ['pattern' => '/^a|\Qb#\E/x', 'bare' => '\Qb#\E', 'grouped' => '^(?:a|\Qb#\E)', 'anchored' => 'b#', 'inside' => 'xb#', 'others' => ['b', 'xb']];
        yield 'quoted space under extended mode' => ['pattern' => '/^a|\Q b\E/x', 'bare' => '\Q b\E', 'grouped' => '^(?:a|\Q b\E)', 'anchored' => ' b', 'inside' => 'x b', 'others' => ['b', 'xb']];
        yield 'quoted bar after the start anchor' => ['pattern' => '/^\Qa|\E|b/', 'bare' => 'b', 'grouped' => '^(?:\Qa|\E|b)', 'anchored' => 'a|', 'inside' => 'xb', 'others' => ['a', 'xa|']];
        yield 'quoted bar before the end anchor' => ['pattern' => '/a|\Qb|\E|c$/', 'bare' => 'a', 'grouped' => '(?:a|\Qb|\E|c)$', 'anchored' => 'b|', 'inside' => 'b|x', 'others' => ['b', 'c|x']];
        // A quote left open runs to the end of the pattern: the tip closes
        // it before the group does.
        yield 'quote left open to the end' => ['pattern' => '/^a|\Qb)/', 'bare' => '\Qb)\E', 'grouped' => '^(?:a|\Qb)\E)', 'anchored' => 'b)', 'inside' => 'xb)', 'others' => ['b', 'xb']];
        // "\Q" in an extended-mode comment opens no quote.
        yield 'quote opener inside an extended-mode comment' => ['pattern' => "/^a|b # \\Q\n|c/x", 'bare' => 'b', 'grouped' => '^(?:a|b|c)', 'anchored' => 'c', 'inside' => 'xb', 'others' => ['x', 'Q']];
    }

    /**
     * A verb that ends the match attempt when backtracked into, or that
     * accepts at once, before the start anchor stops the engine before it
     * tries the bare branch: "b" does not match anywhere, and the grouped
     * form would be another pattern.
     */
    #[Test]
    #[DataProvider('provideVerbsBeforeTheStartAnchor')]
    public function test_a_verb_cutting_the_match_before_the_start_anchor_silences_the_rule(string $pattern): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: no match is the bare branch.
        foreach (['b', 'xb', 'ba'] as $subject) {
            $this->assertNotFalse(preg_match($pattern, $subject, $matches), $pattern);
            $this->assertNotSame('b', $matches[0] ?? null, $pattern.' on '.$subject);
        }

        $this->assertNull($this->violation($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideVerbsBeforeTheStartAnchor(): iterable
    {
        yield 'commit' => ['pattern' => '/(*COMMIT)^a|b/'];
        yield 'prune' => ['pattern' => '/(*PRUNE)^a|b/'];
        yield 'skip' => ['pattern' => '/(*SKIP)^a|b/'];
        yield 'accept' => ['pattern' => '/(*ACCEPT)^a|b/'];
        yield 'named commit' => ['pattern' => '/(*COMMIT:n)^a|b/'];
        yield 'named prune' => ['pattern' => '/(*PRUNE:n)^a|b/'];
        yield 'named accept' => ['pattern' => '/(*ACCEPT:n)^a|b/'];
        yield 'commit after options' => ['pattern' => '/(?i)(*COMMIT)^a|b/'];
        yield 'commit after a mark' => ['pattern' => '/(*MARK:m)(*COMMIT)^a|b/'];
        yield 'commit inside the group holding the anchor' => ['pattern' => '/(?:(*COMMIT)^a)|b/'];
        yield 'prune inside an atomic group holding the anchor' => ['pattern' => '/(?>(*PRUNE)^a)|b/'];
    }

    /**
     * A verb that cuts the match or fails, anywhere in the alternation,
     * leaves the rule silent: past the anchor it can still stop the bare
     * branch ("^(*COMMIT)a|b" never matches "b"), and a failing verb before
     * the anchor would carry into the grouped form, which then matches
     * nothing ("(*F)^a|b" matches "b" anywhere, "(*F)^(?:a|b)" nothing).
     */
    #[Test]
    #[DataProvider('provideCuttingVerbsAnywhere')]
    public function test_a_verb_cutting_or_failing_anywhere_silences_the_rule(string $pattern, string $subject, int $matches): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame($matches, preg_match($pattern, $subject), $pattern);
        $this->assertNull($this->violation($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, matches: int}>
     */
    public static function provideCuttingVerbsAnywhere(): iterable
    {
        yield 'commit after the start anchor' => ['pattern' => '/^(*COMMIT)a|b/', 'subject' => 'xb', 'matches' => 0];
        yield 'commit later in the anchored branch' => ['pattern' => '/^x(*COMMIT)a|b/', 'subject' => 'xb', 'matches' => 0];
        yield 'commit in the bare branch' => ['pattern' => '/^a|(*COMMIT)b/', 'subject' => 'xb', 'matches' => 0];
        yield 'commit before an end anchor' => ['pattern' => '/a|(*COMMIT)b$/', 'subject' => 'xa', 'matches' => 0];
        yield 'fail before the start anchor' => ['pattern' => '/(*F)^a|b/', 'subject' => 'xb', 'matches' => 1];
        yield 'long fail before the start anchor' => ['pattern' => '/(*FAIL)^a|b/', 'subject' => 'xb', 'matches' => 1];
    }

    /**
     * A mark, or (*THEN), which only moves on to the next alternative,
     * leaves the bare branch matching anywhere.
     */
    #[Test]
    #[DataProvider('provideVerbsLeavingTheBareBranchFree')]
    public function test_a_verb_that_does_not_cut_the_match_leaves_the_rule_on(string $pattern, string $grouped): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the bare branch matches inside the subject.
        $this->assertSame(1, preg_match($pattern, 'xb', $matches));
        $this->assertSame('b', $matches[0]);

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(\sprintf('If the anchors are meant for every alternative, group them: "%s".', $grouped), $violation->hint);
    }

    /**
     * @return iterable<string, array{pattern: string, grouped: string}>
     */
    public static function provideVerbsLeavingTheBareBranchFree(): iterable
    {
        yield 'mark' => ['pattern' => '/(*MARK:m)^a|b/', 'grouped' => '(*MARK:m)^(?:a|b)'];
        yield 'short mark' => ['pattern' => '/(*:m)^a|b/', 'grouped' => '(*:m)^(?:a|b)'];
        yield 'then' => ['pattern' => '/(*THEN)^a|b/', 'grouped' => '(*THEN)^(?:a|b)'];
    }

    /**
     * A branch made only of verbs or comments is no bare alternative:
     * "(*FAIL)" never matches, and an empty branch is
     * alternation.empty's: one defect, one issue.
     */
    #[Test]
    #[DataProvider('provideBranchesThatAreNotBare')]
    public function test_a_branch_of_verbs_or_comments_only_is_not_bare(string $pattern, ?string $otherRule): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
        $this->assertNull($this->violation($pattern));

        if (null !== $otherRule) {
            $this->assertContains($otherRule, array_map(static fn (RuleViolation $violation): string => $violation->id, $this->lint($pattern)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, otherRule: ?string}>
     */
    public static function provideBranchesThatAreNotBare(): iterable
    {
        yield 'failing verb' => ['pattern' => '/^a|(*FAIL)/', 'otherRule' => null];
        yield 'empty branch' => ['pattern' => '/^a|/', 'otherRule' => 'regex.lint.alternation.empty'];
        yield 'comment-only branch' => ['pattern' => '/^a|(?#c)/', 'otherRule' => 'regex.lint.alternation.empty'];
    }

    /**
     * A branch that never matches is passed over: the bare branch named is
     * the one after it.
     */
    #[Test]
    public function test_the_bare_branch_named_is_past_a_failing_one(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame(1, preg_match('/^a|(*F)|b/', 'xb', $matches));
        $this->assertSame(['b'], $matches);

        $violation = $this->violation('/^a|(*F)|b/');
        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('An anchor holds for its own alternative only: "b" matches anywhere in the subject.', $violation->message);
    }

    /**
     * A comment between the anchor and the edge of its branch does not
     * hide the anchor: the branches bind the same way, and the tip groups
     * them past the comment.
     */
    #[Test]
    #[DataProvider('provideAnchorsBesideAComment')]
    public function test_an_anchor_beside_a_comment_is_still_read(string $pattern, string $subject, string $bareMatch, string $grouped): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the bare branch matches inside the subject.
        $this->assertSame(1, preg_match($pattern, $subject, $matches), $pattern);
        $this->assertSame([$bareMatch], $matches);

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(\sprintf('An anchor holds for its own alternative only: "%s" matches anywhere in the subject.', $bareMatch), $violation->message);
        $this->assertSame(\sprintf('If the anchors are meant for every alternative, group them: "%s".', $grouped), $violation->hint);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, bareMatch: string, grouped: string}>
     */
    public static function provideAnchorsBesideAComment(): iterable
    {
        yield 'comment before a start anchor' => ['pattern' => '/(?#c)^a|b/', 'subject' => 'xbx', 'bareMatch' => 'b', 'grouped' => '^(?:a|b)'];
        yield 'comment after an end anchor' => ['pattern' => '/a|b$(?#c)/', 'subject' => 'xax', 'bareMatch' => 'a', 'grouped' => '(?:a|b)$'];
        yield 'extended-mode comment after an end anchor' => ['pattern' => '/a|b$ # c/x', 'subject' => 'xax', 'bareMatch' => 'a', 'grouped' => '(?:a|b)$'];
    }

    /**
     * The first and last branches anchored, the middle one bare: reported;
     * the tip's exact form is left to the rule.
     */
    #[Test]
    public function test_an_anchor_on_an_inner_side_silences_the_rule_as_sonar_does(): void
    {
        // Oracle: "b" matches anywhere, "^c" only at the start. An anchor
        // written on a branch other than the outer edges shows the anchors
        // are placed branch by branch: SonarPHP stays silent, and so does
        // the rule.
        $this->assertSame(1, preg_match('/^a|b|^c/', 'xb'));
        $this->assertSame(0, preg_match('/^a|b|^c/', 'xc'));

        $this->assertNull($this->violation('/^a|b|^c/'));
    }

    #[Test]
    #[DataProvider('provideAlternationsAnchoredOnPurpose')]
    public function test_an_alternation_every_branch_of_which_is_anchored_or_none_is_not_reported(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAlternationsAnchoredOnPurpose(): iterable
    {
        yield 'trim idiom' => ['pattern' => '/^\s+|\s+$/'];
        yield 'one anchor per branch, first and last' => ['pattern' => '/^a|b$/'];
        yield 'every branch starts anchored' => ['pattern' => '/^a|^b/'];
        yield 'every branch anchored on both sides' => ['pattern' => '/^a$|^b$/'];
        yield 'grouped already' => ['pattern' => '/^(?:a|b)$/'];
        yield 'no anchor at all' => ['pattern' => '/a|b/'];
        yield 'no alternation' => ['pattern' => '/^ab$/'];
        // An alternative that is nothing but the anchor means "or the end"
        // (or "or the start"): the anchor is the alternative, not a slip.
        yield 'last alternative only an end anchor' => ['pattern' => '/(\s+2>&1)*(\s*\|)|$/'];
        yield 'first alternative only a start anchor' => ['pattern' => '/^|,/'];
        yield 'previous match end and an end anchor' => ['pattern' => '/\Ga|b$/'];
        // Groups that change nothing about where the branch matches, and a
        // lookaround that only asserts the anchor, leave it in force.
        yield 'end anchor inside a non-capturing group' => ['pattern' => '/^a|(?:b$)/'];
        yield 'end anchor inside a capturing group' => ['pattern' => '/^a|(b$)/'];
        yield 'end anchor inside an atomic group' => ['pattern' => '/^a|(?>b$)/'];
        yield 'end anchor inside a one-branch reset group' => ['pattern' => '/^a|(?|b$)/'];
        yield 'start anchor inside a group' => ['pattern' => '/(?:^a)|b$/'];
        yield 'lookahead asserting the end' => ['pattern' => '/^a|b(?=$)/'];
        yield 'lookbehind asserting the start' => ['pattern' => '/(?<=^)a|b$/'];
        yield 'options before the start anchor of every branch' => ['pattern' => '/(?i)^a|^b/'];
        // An anchor on an inner side, as SonarPHP reads it: the anchors are
        // placed branch by branch ("trim, and replace anywhere").
        yield 'middle branch anchored at the end' => ['pattern' => '#^ +| +$|,#'];
        yield 'middle branch anchored on both sides' => ['pattern' => '/^[$]|^\\d+$|[^0-9a-zA-Z$_]/'];
        yield 'first branch anchored at both ends' => ['pattern' => '/^a$|b/'];
        yield 'last branch anchored at both ends' => ['pattern' => '/a|^b$/'];
        // Under A every branch starts at the start of the subject:
        // preg_match('/__|this$/A', 'x__') is 0.
        yield 'start anchored by the A modifier' => ['pattern' => '/__|this$/A'];
        yield 'start anchored by the A modifier, several branches' => ['pattern' => '/ʟ_|__|GLOBALS$|this$/A'];
    }

    /**
     * A group that may match in more than one way, or a negative
     * lookaround, does not anchor its branch.
     */
    #[Test]
    #[DataProvider('provideGroupsThatDoNotAnchor')]
    public function test_a_group_that_does_not_anchor_leaves_the_branch_bare(string $pattern, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the last branch matches inside the subject.
        $this->assertSame(1, preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE), $pattern);
        $this->assertGreaterThan(0, $matches[0][1]);

        $this->assertInstanceOf(RuleViolation::class, $this->violation($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideGroupsThatDoNotAnchor(): iterable
    {
        yield 'two-branch reset group' => ['pattern' => '/^a|(?|b$|c)/', 'subject' => 'xcx'];
        yield 'negative lookahead' => ['pattern' => '/^a|b(?!$)/', 'subject' => 'xbx'];
        yield 'lookahead reading more than the anchor' => ['pattern' => '/^a|b(?=x$)/', 'subject' => 'xbx'];
        yield 'group with an alternation' => ['pattern' => '/^a|(?:b$|c)/', 'subject' => 'xcx'];
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

    private function violation(string $pattern): ?RuleViolation
    {
        foreach ($this->lint($pattern) as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}

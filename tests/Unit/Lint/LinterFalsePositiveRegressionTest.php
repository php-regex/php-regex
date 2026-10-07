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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for linter false positives found by confronting the
 * corpus log with the real PCRE engine.
 */
final class LinterFalsePositiveRegressionTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, ruleId: string}>
     */
    public static function provideConditionalBranchesKeepFlagsUseful(): iterable
    {
        yield 'dot inside a conditional keeps the s flag useful' => [
            'pattern' => '/(\d)(?(1)a.b|)/s',
            'ruleId' => 'regex.lint.flag.useless.s',
        ];

        yield 'letters inside a conditional keep the i flag useful' => [
            'pattern' => '/(\d)(?(1)aZb|)/i',
            'ruleId' => 'regex.lint.flag.useless.i',
        ];

        yield 'anchor inside a conditional keeps the m flag useful' => [
            'pattern' => '/(\d)(?(1)a\n^b|)/m',
            'ruleId' => 'regex.lint.flag.useless.m',
        ];

        yield 'dot inside a DEFINE group keeps the s flag useful' => [
            'pattern' => '/x(?(DEFINE)a.b)/s',
            'ruleId' => 'regex.lint.flag.useless.s',
        ];

        yield 'dot inside the no branch of a conditional keeps the s flag useful' => [
            'pattern' => '/(x)(?(1)a|b.c)/s',
            'ruleId' => 'regex.lint.flag.useless.s',
        ];

        yield 'anchor inside the condition of a conditional keeps the m flag useful' => [
            'pattern' => '/(?(?<!^--) +\n|  +\n)/m',
            'ruleId' => 'regex.lint.flag.useless.m',
        ];
    }

    #[Test]
    #[DataProvider('provideConditionalBranchesKeepFlagsUseful')]
    public function test_conditional_branches_keep_flags_useful(string $pattern, string $ruleId): void
    {
        // A target that reads the rows below, the extended classes among
        // them (PCRE2 10.45), whatever engine runs the suite.
        $visitor = new PatternLinter();
        Regex::create(['php_version' => '8.4', 'pcre_version' => '10.45'])->parse($pattern)->accept($visitor);

        $issueIds = array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues());

        $this->assertNotContains($ruleId, $issueIds, $pattern);
    }

    #[Test]
    public function test_conditional_branches_report_redundant_class_elements(): void
    {
        $visitor = new PatternLinter();
        Regex::create()->parse('/(\d)(?(1)a[bb]|)/')->accept($visitor);

        $issueIds = array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues());

        $this->assertContains('regex.lint.charclass.redundant', $issueIds);
    }

    #[Test]
    public function test_define_groups_count_as_capturing_groups(): void
    {
        $visitor = new PatternLinter();
        Regex::create()->parse('/(?(DEFINE)(a))(b)\2/')->accept($visitor);

        $issueIds = array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues());

        $this->assertNotContains('regex.lint.backref.undefined', $issueIds);
    }

    /**
     * @return iterable<string, array{pattern: string, reported: bool}>
     */
    public static function provideConcatenatedQuantifierDotPremise(): iterable
    {
        yield 'whitespace before a dot without the s flag has a false premise' => [
            'pattern' => '/\s+.*$/',
            'reported' => false,
        ];

        yield 'whitespace before a dot with the s flag is optimizable' => [
            'pattern' => '/\s+.*$/s',
            'reported' => true,
        ];

        yield 'strict subset with min 1 stays optimizable' => [
            'pattern' => '/[a-z]+[a-z0-9_]*\z/',
            'reported' => true,
        ];
    }

    #[Test]
    #[DataProvider('provideConcatenatedQuantifierDotPremise')]
    public function test_concatenated_quantifiers_respect_the_s_flag_for_dot(string $pattern, bool $reported): void
    {
        $visitor = new PatternLinter();
        Regex::create()->parse($pattern)->accept($visitor);

        $issueIds = array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues());

        $reported
            ? $this->assertContains('regex.lint.quantifier.concatenation', $issueIds, $pattern)
            : $this->assertNotContains('regex.lint.quantifier.concatenation', $issueIds, $pattern);
    }

    /**
     * The dot reads the s flag in force where it stands, not the pattern's
     * modifiers: under a local (?-s) it stops at "\n", so "\n+" is no
     * subset of it and tightening "\n+" to "\n" changes the match ("a\n\nb"
     * matches the pattern, not the rewrite). Each row compares the pattern
     * with the rewrite the hint proposes, match offset and extent, over
     * every subject of up to five characters of "a", "\n" and "b".
     *
     * @return iterable<string, array{pattern: string, rewrite: string, reported: bool}>
     */
    public static function provideConcatenationUnderALocalDotAll(): iterable
    {
        yield 's turned off at the start under the s flag' => ['pattern' => '/(?-s)a\n+.+/s', 'rewrite' => '/(?-s)a\n.+/s', 'reported' => false];
        yield 's turned off in a group under the s flag' => ['pattern' => '/a(?-s:\n+.+)/s', 'rewrite' => '/a(?-s:\n.+)/s', 'reported' => false];
        // A (?-s) set in an earlier alternative carries into the later ones.
        yield 's turned off in an earlier alternative under the s flag' => ['pattern' => '/x(?-s)|a\n+.+/s', 'rewrite' => '/x(?-s)|a\n.+/s', 'reported' => false];
        yield 's turned off between the two runs under the s flag' => ['pattern' => '/a\n+(?-s).+/s', 'rewrite' => '/a\n(?-s).+/s', 'reported' => false];
        yield 's set then turned off' => ['pattern' => '/(?s)(?-s)a\n+.+/', 'rewrite' => '/(?s)(?-s)a\n.+/', 'reported' => false];
        // Where the dot does take "\n", the tightening is sound.
        yield 's flag' => ['pattern' => '/a\n+.+/s', 'rewrite' => '/a\n.+/s', 'reported' => true];
        yield 's set inline' => ['pattern' => '/(?s)a\n+.+/', 'rewrite' => '/(?s)a\n.+/', 'reported' => true];
    }

    #[Test]
    #[DataProvider('provideConcatenationUnderALocalDotAll')]
    public function test_concatenated_quantifiers_read_the_s_flag_in_force(string $pattern, string $rewrite, bool $reported): void
    {
        $same = true;
        foreach (self::subjects(['a', "\n", 'b'], 5) as $subject) {
            $left = preg_match($pattern, $subject, $leftMatch, \PREG_OFFSET_CAPTURE);
            $right = preg_match($rewrite, $subject, $rightMatch, \PREG_OFFSET_CAPTURE);
            if ($left !== $right || ($leftMatch[0] ?? null) !== ($rightMatch[0] ?? null)) {
                $same = false;

                break;
            }
        }
        $this->assertSame($reported, $same, 'Oracle disagrees with the row.');
        $this->assertSame(1, preg_match($pattern, "a\n\nb"));
        $this->assertSame($reported ? 1 : 0, preg_match($rewrite, "a\n\nb"), 'Oracle disagrees with the row on "a\n\nb".');

        $reported
            ? $this->assertContains('regex.lint.quantifier.concatenation', $this->issueIds($pattern), $pattern)
            : $this->assertNotContains('regex.lint.quantifier.concatenation', $this->issueIds($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, hint: string}>
     */
    public static function provideConcatenatedQuantifierHints(): iterable
    {
        yield 'droppable first quantifier' => [
            'pattern' => '/[a-z]*[a-z0-9_]*\z/',
            'hint' => 'The first quantifier can match zero times already',
        ];

        yield 'tightenable first quantifier' => [
            'pattern' => '/[a-z]+[a-z0-9_]*\z/',
            'hint' => 'Consider tightening the first quantifier to its minimum.',
        ];

        yield 'droppable second quantifier' => [
            'pattern' => '/[a-z0-9_]*[a-z]*\z/',
            'hint' => 'The second quantifier can match zero times already',
        ];

        yield 'tightenable second quantifier' => [
            'pattern' => '/[a-z0-9_]*[a-z]+\z/',
            'hint' => 'Consider tightening the second quantifier to its minimum.',
        ];
    }

    #[Test]
    public function test_concatenated_quantifiers_hint_distinguishes_dropping_from_tightening(): void
    {
        $hints = [];
        foreach (['/[a-z]*[a-z0-9_]*\z/', '/[a-z]+[a-z0-9_]*\z/'] as $pattern) {
            $visitor = new PatternLinter();
            Regex::create()->parse($pattern)->accept($visitor);
            foreach ($visitor->getIssues() as $issue) {
                if ('regex.lint.quantifier.concatenation' === $issue->id) {
                    $hints[$pattern] = (string) $issue->hint;
                }
            }
        }

        $this->assertSame(['/[a-z]*[a-z0-9_]*\z/', '/[a-z]+[a-z0-9_]*\z/'], array_keys($hints));
        $this->assertStringContainsString('dropping the whole quantified term', $hints['/[a-z]*[a-z0-9_]*\z/'] ?? '');
        $this->assertStringContainsString('to its minimum', $hints['/[a-z]+[a-z0-9_]*\z/'] ?? '');
    }

    #[Test]
    #[DataProvider('provideConcatenatedQuantifierHints')]
    public function test_concatenated_quantifiers_hint_matches_each_shape(string $pattern, string $hint): void
    {
        $visitor = new PatternLinter();
        Regex::create()->parse($pattern)->accept($visitor);

        $hints = [];
        foreach ($visitor->getIssues() as $issue) {
            if ('regex.lint.quantifier.concatenation' === $issue->id) {
                $hints[] = (string) $issue->hint;
            }
        }

        $this->assertStringContainsString($hint, implode("\n", $hints), $pattern);
    }

    /**
     * `$` and `\Z` match before the subject's final newline (and, under /m,
     * `$` before any newline), so a tail that can continue that newline
     * match is not impossible.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideEndAnchorNewlineTails(): iterable
    {
        yield 'dollar before a newline literal' => [
            'pattern' => '/^a$\n/',
        ];

        yield 'dollar before a newline literal with the m flag' => [
            'pattern' => '/^a$\n/m',
        ];

        yield 'uppercase Z before a newline literal' => [
            'pattern' => '/a\Z\n/',
        ];

        yield 'the line-by-line composer-json idiom' => [
            'pattern' => '{^\s*+"name":.*,$\n}m',
        ];

        yield 'character class containing a newline' => [
            'pattern' => '/a$[\n]/',
        ];

        yield 'whitespace shorthand matches the newline' => [
            'pattern' => '/a$\s/',
        ];

        yield 'newline with an unbounded quantifier' => [
            'pattern' => '/a$\n+/',
        ];

        yield 'newline inside a capturing group' => [
            'pattern' => '/a$(\n)/',
        ];

        yield 'newline as one alternative' => [
            'pattern' => '/a$(?:\n|x)/',
        ];

        yield 'newline beside an optional sibling' => [
            'pattern' => '/a$(?:\n)?\n?/',
        ];

        yield 'end anchor inside a conditional branch' => [
            'pattern' => '/(\d)(?(1)$\n|)/',
        ];

        yield 'conditional tail matching the newline branch' => [
            'pattern' => '/(x)a$(?(1)\n|y)/',
        ];

        yield 'dotall tail under multiline' => [
            'pattern' => '/^a$.b/ms',
        ];

        yield 'dotall sequence tail under multiline' => [
            'pattern' => '/^a$(?:.b)+/ms',
        ];

        yield 'extended character class containing a newline' => [
            'pattern' => '/a$(?[ [\n] ])/',
        ];

        yield 'newline then an optional newline inside a group' => [
            'pattern' => '/a$(?:\n\n?)/',
        ];

        yield 'hexadecimal newline spelling' => [
            'pattern' => '/a$\x0a/',
        ];

        yield 'control-character newline spelling' => [
            'pattern' => '/a$\cJ/',
        ];

        yield 'inline multiline scope' => [
            'pattern' => '/a(?m:$\n)/',
        ];

        yield 'inline multiline scope before a longer tail' => [
            'pattern' => '/a(?m:$\nx)/',
        ];

        yield 'inline multiline flag for the rest of the pattern' => [
            'pattern' => '/a(?m)$\n\n/',
        ];

        yield 'inline dotall scope' => [
            'pattern' => '/a$(?s)./',
        ];

        yield 'octal newline spelling' => [
            'pattern' => '/a$\012/',
        ];

        yield 'braced hex newline spelling' => [
            'pattern' => '/a$\x{a}/u',
        ];

        yield 'negated class matching the newline' => [
            'pattern' => '/a$[^a]/',
        ];

        yield 'inline flag reset before the newline tail' => [
            'pattern' => '/a(?^)$\n/m',
        ];

        yield 'scoped inline flags on a sibling' => [
            'pattern' => '/a(?s:b)$\n/',
        ];

        yield 'backtracking verb beside the newline' => [
            'pattern' => '/a$(*SKIP)\n/',
        ];

        yield 'always-empty alternation beside the newline' => [
            'pattern' => '/a$\n(?:\b|)/',
        ];

        yield 'always-empty sequence beside the newline' => [
            'pattern' => '/a$\n(?:x?y?)/',
        ];

        yield 'always-empty conditional beside the newline' => [
            'pattern' => '/(a)$\n(?(1)x?|y?)/',
        ];

        yield 'standalone inline dotall inside the tail' => [
            'pattern' => '/^a$(?s).b/m',
        ];

        yield 'standalone inline dotall before a bare dot' => [
            'pattern' => '/a$(?s)./',
        ];

        yield 'scoped dotall group on a sibling' => [
            'pattern' => '/a(?s:.)$\n/',
        ];

        yield 'end anchor after the newline' => [
            'pattern' => '/a$\n$/',
        ];

        yield 'absolute end anchor after the newline' => [
            'pattern' => '/a$\n\z/',
        ];

        yield 'final-newline assertion after the newline' => [
            'pattern' => '/a$\n\Z/',
        ];

        yield 'keep mark after the newline' => [
            'pattern' => '/a$\n\K/',
        ];

        yield 'optional quantified dot under dotall and multiline' => [
            'pattern' => '/^a$.*b/ms',
        ];

        yield 'vertical whitespace shorthand' => [
            'pattern' => '/a$\v/',
        ];

        yield 'line break shorthand' => [
            'pattern' => '/a$\R/',
        ];

        yield 'newline tail as the whole pattern' => [
            'pattern' => '/(a$\n)/',
        ];

        yield 'scoped dotall group as the tail' => [
            'pattern' => '/a$(?s:.)/',
        ];

        yield 'absolute end assertion after the enclosing group' => [
            'pattern' => '/(?:a$\n)\z/',
        ];
    }

    #[Test]
    public function test_define_body_is_not_linted_for_anchor_impossibility(): void
    {
        $visitor = new PatternLinter();
        Regex::create()->parse('/(?(DEFINE)a$b)/')->accept($visitor);

        $issueIds = array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues());

        $this->assertNotContains('regex.lint.anchor.impossible.end', $issueIds);
    }

    #[Test]
    #[DataProvider('provideEndAnchorNewlineTails')]
    public function test_end_anchor_allows_newline_continuation_tails(string $pattern): void
    {
        $visitor = new PatternLinter();
        Regex::create()->parse($pattern)->accept($visitor);

        $issueIds = array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues());

        $this->assertNotContains('regex.lint.anchor.impossible.end', $issueIds, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideEndAnchorImpossibleTails(): iterable
    {
        yield 'tail continues past the final newline' => [
            'pattern' => '/^a$\nx/',
        ];

        yield 'consuming tail without the m flag' => [
            'pattern' => '/^foo$bar/',
        ];

        yield 'consuming tail even with the m flag' => [
            'pattern' => '/^foo$bar/m',
        ];

        yield 'absolute end anchor before a newline literal' => [
            'pattern' => '/^a\z\n/',
        ];

        yield 'lookahead then a consuming literal' => [
            'pattern' => '/^a$(?=\n)x/',
        ];

        yield 'alternation whose branches cannot match a newline' => [
            'pattern' => '/a$(?:x|y)/',
        ];

        yield 'dollar modifier makes the dollar strict' => [
            'pattern' => '/a$\n/D',
        ];

        yield 'lookahead head under multiline cannot consume the newline' => [
            'pattern' => '/^a$(?=\n)x/m',
        ];

        yield 'unicode word character never matches the newline' => [
            'pattern' => '/a$\w/mu',
        ];

        yield 'non-newline shorthand under multiline' => [
            'pattern' => '/a$\N/',
        ];

        yield 'backreference head under multiline' => [
            'pattern' => '/(a)$\1x/m',
        ];

        yield 'keep mark head under multiline' => [
            'pattern' => '/a$\Kx/m',
        ];

        yield 'failing lookahead after the newline' => [
            'pattern' => '/a$\n(?=x)/',
        ];

        yield 'failing word boundary after the newline' => [
            'pattern' => '/a$\n\b/',
        ];

        yield 'inline negated multiline before a doubled newline' => [
            'pattern' => '/a(?-m)$\n\n/m',
        ];

        yield 'inline negated dotall before a dot' => [
            'pattern' => '/a$(?-s)./s',
        ];

        yield 'unicode property tail on an unknown set' => [
            'pattern' => '/a$\pL/',
        ];

        yield 'alternation beside the newline with no empty branch' => [
            'pattern' => '/a$\n(?:a|b)/',
        ];

        yield 'always-failing backtracking verb after the newline' => [
            'pattern' => '/a$\n(*FAIL)/',
        ];

        yield 'negated inline dotall before a quantified dot' => [
            'pattern' => '/a$(?-s).*x/ms',
        ];

        yield 'negated inline dotall with an optional dot' => [
            'pattern' => '/a$(?-s).*b/m',
        ];

        yield 'consuming group after the newline tail' => [
            'pattern' => '/(?:a$\n)b/',
        ];

        yield 'capturing group after the newline tail' => [
            'pattern' => '/(a$\n)b/',
        ];

        yield 'consuming group after a final-newline tail' => [
            'pattern' => '/(?:a\Z\n)b/',
        ];

        yield 'consuming group after a final-newline tail under multiline' => [
            'pattern' => '/(?:a\Z\n)b/m',
        ];

        yield 'horizontal whitespace shorthand' => [
            'pattern' => '/a$\h/',
        ];

        yield 'negated vertical whitespace shorthand' => [
            'pattern' => '/a$\V/',
        ];
    }

    #[Test]
    public function test_tail_can_start_with_newline_allows_an_all_optional_tail(): void
    {
        $ast = Regex::create()->parse('/b?c?/');

        $sequence = $ast->pattern;
        $this->assertInstanceOf(SequenceNode::class, $sequence);

        $this->assertTrue(NodePredicates::tailCanStartWithNewline(
            array_values($sequence->children),
            new CharSetAnalyzer(),
            false,
        ));
    }

    #[Test]
    public function test_backreference_never_counts_as_a_newline_match(): void
    {
        // Documented conservative limit: a backref could match "\n" at
        // runtime, but its charset is unknown, so an exotic tail stays
        // reported rather than silenced.
        $this->assertFalse(NodePredicates::canMatchNewline(
            new BackrefNode('1', 0, 0),
            new CharSetAnalyzer(),
            false,
        ));
    }

    /**
     * @return iterable<string, array{base: string, inline: string, expected: string}>
     */
    public static function provideInlineFlagsFolds(): iterable
    {
        yield 'plain set' => ['base' => '', 'inline' => 'ms', 'expected' => 'ms'];

        yield 'set over base' => ['base' => 'i', 'inline' => 's', 'expected' => 'is'];

        yield 'unset' => ['base' => 's', 'inline' => '-s', 'expected' => ''];

        yield 'set and unset together' => ['base' => 'is', 'inline' => 's-x', 'expected' => 'is'];

        yield 'reset discards the base' => ['base' => 'i', 'inline' => '^s', 'expected' => 's'];

        yield 'reset alone empties everything' => ['base' => 'im', 'inline' => '^', 'expected' => ''];
    }

    #[Test]
    #[DataProvider('provideInlineFlagsFolds')]
    public function test_inline_flags_fold(string $base, string $inline, string $expected): void
    {
        $this->assertSame($expected, NodePredicates::applyInlineFlags($base, $inline));
    }

    #[Test]
    public function test_only_bare_flag_groups_toggle_scope(): void
    {
        $scoped = Regex::create()->parse('/(?s:b)/')->pattern;
        $this->assertInstanceOf(GroupNode::class, $scoped);
        $this->assertFalse(NodePredicates::isStandaloneInlineFlagsGroup($scoped));

        $bare = Regex::create()->parse('/(?s)/')->pattern;
        $this->assertInstanceOf(GroupNode::class, $bare);
        $this->assertTrue(NodePredicates::isStandaloneInlineFlagsGroup($bare));
    }

    #[Test]
    #[DataProvider('provideEndAnchorImpossibleTails')]
    public function test_end_anchor_still_reports_impossible_tails(string $pattern): void
    {
        $visitor = new PatternLinter();
        Regex::create()->parse($pattern)->accept($visitor);

        $issueIds = array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues());

        $this->assertContains('regex.lint.anchor.impossible.end', $issueIds, $pattern);
    }

    /**
     * A lookaround glued to a single character restricts where that
     * character may sit: `.(?!x)` is not the set of `.`, so the following
     * quantifier cannot always take over what the loop matched. Each row
     * names the rewrite the hint would propose and the subject on which the
     * engine tells them apart.
     *
     * @return iterable<string, array{pattern: string, rewrite: string, subject: string}>
     */
    public static function provideConcatenationThroughALookaround(): iterable
    {
        // pimcore: dropping ".?" turns a match into a miss.
        yield 'quoted string loop before an optional dot' => [
            'pattern' => <<<'REGEX'
                /((?<![\\])['"])((?:.(?!(?<![\\])\1))*.?)\1/
                REGEX,
            'rewrite' => <<<'REGEX'
                /((?<![\\])['"])((?:.(?!(?<![\\])\1))*)\1/
                REGEX,
            'subject' => "'ab'",
        ];

        // Tightening ".{1,3}" to "." loses "ax": the loop cannot take the
        // "a" a "x" follows.
        yield 'negative lookahead loop before a bounded dot' => [
            'pattern' => '/^(?:.(?!x))*.{1,3}$/',
            'rewrite' => '/^(?:.(?!x))*.$/',
            'subject' => 'ax',
        ];
    }

    #[Test]
    #[DataProvider('provideConcatenationThroughALookaround')]
    public function test_concatenation_through_a_lookaround_is_not_reported(string $pattern, string $rewrite, string $subject): void
    {
        // Oracle: the hinted rewrite changes the verdict.
        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(0, preg_match($rewrite, $subject));

        $this->assertNotContains('regex.lint.quantifier.concatenation', $this->issueIds($pattern), $pattern);
    }

    /**
     * Corpus: the lookahead (?!__) can only fail before a "_", and the
     * trailing [a-z0-9]+ never holds one, so the loop can take every
     * character but the last and the tightening is sound. The engine agrees
     * on every subject over {a, 0, _, -} up to six characters.
     */
    #[Test]
    public function test_concatenation_through_a_lookaround_the_tail_cannot_trip_is_still_reported(): void
    {
        $pattern = '/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+$/';
        $tightened = '/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]$/';

        foreach (self::subjects(['a', '0', '_', '-'], 6) as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($tightened, $subject), $subject);
        }

        $hints = [];
        $visitor = new PatternLinter();
        Regex::create()->parse($pattern)->accept($visitor);
        foreach ($visitor->getIssues() as $issue) {
            if ('regex.lint.quantifier.concatenation' === $issue->id) {
                $hints[] = (string) $issue->hint;
            }
        }

        $this->assertSame(['Consider tightening the second quantifier to its minimum.'], $hints);
    }

    /**
     * A backreference in a group a subroutine call re-enters reads the
     * capture another alternative made before the call.
     *
     * @return iterable<string, array{pattern: string, withoutBackref: string, subjects: list<string>}>
     */
    public static function provideBackrefsReenteredBySubroutine(): iterable
    {
        // WordPress IPv6 validator: group 4 holds \3 and \2, and (?4){5}
        // re-enters it after group 1's first alternative captured both.
        $ipv6 = '/^(((?=.*(::))(?!.*\3.+\3))\3?|([\dA-F]{1,4}(\3|:\b|$)|\2))(?4){5}((?4){2}|(((2[0-4]|1\d|[1-9])?\d|25[0-5])\.?\b){4})$/i';

        yield 'IPv6 validator, backref to group 2' => [
            'pattern' => $ipv6,
            'withoutBackref' => str_replace('|\2))', '|(?!)))', $ipv6),
            'subjects' => ['::1', '1::', '2001:db8::1', '::ffff:1.2.3.4'],
        ];

        yield 'IPv6 validator, backref to group 3' => [
            'pattern' => $ipv6,
            'withoutBackref' => str_replace('(\3|:\b|$)', '((?!)|:\b|$)', $ipv6),
            'subjects' => ['1::', '2001:db8::1'],
        ];

        yield 'minimal re-entry' => [
            'pattern' => '/^(?:(a)|(\1))(?2)$/',
            'withoutBackref' => '/^(?:(a)|((?!)))(?2)$/',
            'subjects' => ['aa'],
        ];
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideBackrefsReenteredBySubroutine')]
    public function test_backref_reentered_by_a_subroutine_call_is_not_useless(string $pattern, string $withoutBackref, array $subjects): void
    {
        $this->assertNotSame($pattern, $withoutBackref);
        foreach ($subjects as $subject) {
            // Oracle: removing the backreference loses the match.
            $this->assertSame(1, preg_match($pattern, $subject), $subject);
            $this->assertSame(0, preg_match($withoutBackref, $subject), $subject);
        }

        $this->assertNotContains('regex.lint.backref.useless', $this->issueIds($pattern), $pattern);
    }

    /**
     * Inside a branch reset two groups share a number, and (?2) calls the
     * first of them. A backreference in the called one is re-entered after
     * group 1 captured; one in the other is never reached that way, and
     * group 1 sits in another alternative: it is useless.
     *
     * @return iterable<string, array{pattern: string, withoutBackref: string, differs: bool, reported: bool}>
     */
    public static function provideBackrefsInABranchReset(): iterable
    {
        // "aa" matches only through the backreference.
        yield 'backref in the group (?2) calls' => [
            'pattern' => '/^(?:(a)|(?|(\1)|(x)))(?2)$/',
            'withoutBackref' => '/^(?:(a)|(?|((?!))|(x)))(?2)$/',
            'differs' => true,
            'reported' => false,
        ];
        // (?2) calls (x): the backreference never matters, but the rule
        // stays silent inside a branch reset, whose group numbers it does
        // not resolve: a known false negative, never a false report.
        yield 'backref in the group sharing the number' => [
            'pattern' => '/^(?:(a)|(?|(x)|(\1)))(?2)$/',
            'withoutBackref' => '/^(?:(a)|(?|(x)|((?!))))(?2)$/',
            'differs' => false,
            'reported' => false,
        ];
    }

    #[Test]
    #[DataProvider('provideBackrefsInABranchReset')]
    public function test_backref_in_a_branch_reset_follows_the_group_the_call_reaches(string $pattern, string $withoutBackref, bool $differs, bool $reported): void
    {
        // Oracle: every subject over {a, x} up to five characters.
        $differences = 0;
        foreach (self::subjects(['a', 'x'], 5) as $subject) {
            preg_match($pattern, $subject, $with);
            preg_match($withoutBackref, $subject, $without);
            $differences += $with === $without ? 0 : 1;
        }
        $this->assertSame($differs, $differences > 0, 'Oracle disagrees with the row.');

        $reported
            ? $this->assertContains('regex.lint.backref.useless', $this->issueIds($pattern), $pattern)
            : $this->assertNotContains('regex.lint.backref.useless', $this->issueIds($pattern), $pattern);
    }

    /**
     * @return list<string>
     */
    private function issueIds(string $pattern): array
    {
        $visitor = new PatternLinter();
        Regex::create()->parse($pattern)->accept($visitor);

        return array_values(array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues()));
    }

    /**
     * Every string over the alphabet up to the given length.
     *
     * @param list<string> $alphabet
     *
     * @return \Generator<int, string>
     */
    private static function subjects(array $alphabet, int $maxLength): \Generator
    {
        $words = [''];
        yield '';
        for ($length = 1; $length <= $maxLength; $length++) {
            $next = [];
            foreach ($words as $word) {
                foreach ($alphabet as $letter) {
                    $next[] = $word.$letter;
                    yield $word.$letter;
                }
            }
            $words = $next;
        }
    }
}

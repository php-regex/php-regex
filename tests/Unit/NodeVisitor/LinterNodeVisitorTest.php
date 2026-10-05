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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinterNodeVisitorTest extends TestCase
{
    public function test_useless_i_flag_on_digits(): void
    {
        $regex = Regex::create()->parse('/^\d+$/i');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains("Flag 'i' is useless: the pattern contains no case-sensitive characters.", $warnings);
    }

    public function test_i_flag_not_useless_on_letters(): void
    {
        $regex = Regex::create()->parse('/[a-z]/i');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        // Ensure we did not incorrectly emit the useless-flag warning when
        // case-sensitive characters are present.
        $this->assertNotContains("Flag 'i' is useless: the pattern contains no case-sensitive characters.", $warnings);
    }

    public function test_i_flag_not_useless_with_backreference(): void
    {
        $regex = Regex::create()->parse('/^<(\\w+)>.*<\\/\\1>$/i');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("Flag 'i' is useless: the pattern contains no case-sensitive characters.", $warnings);
    }

    public function test_i_flag_not_useless_with_named_backreference(): void
    {
        $regex = Regex::create()->parse('/^<(?<tag>\\w+)>.*<\\/\\k<tag>>$/i');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("Flag 'i' is useless: the pattern contains no case-sensitive characters.", $warnings);
    }

    public function test_i_flag_not_useless_on_unicode_escape(): void
    {
        $regex = Regex::create()->parse('/\\x41/i');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("Flag 'i' is useless: the pattern contains no case-sensitive characters.", $warnings);
    }

    public function test_i_flag_not_useless_on_char_class_unicode_escape(): void
    {
        $regex = Regex::create()->parse('/[\\x41]/i');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("Flag 'i' is useless: the pattern contains no case-sensitive characters.", $warnings);
    }

    public function test_i_flag_not_useless_on_unicode_property(): void
    {
        $regex = Regex::create()->parse('/\\p{Lu}/iu');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("Flag 'i' is useless: the pattern contains no case-sensitive characters.", $warnings);
    }

    public function test_useless_s_flag_no_dots(): void
    {
        $regex = Regex::create()->parse('/^\d+$/s');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains("Flag 's' is useless: the pattern contains no unescaped dot outside a character class.", $warnings);
    }

    public function test_s_flag_not_useless_with_dots(): void
    {
        $regex = Regex::create()->parse('/.+/s');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("Flag 's' is useless: the pattern contains no unescaped dot outside a character class.", $warnings);
        $this->assertNotContains('regex.lint.flag.useless.s', array_map(static fn (RuleViolation $issue): string => $issue->id, $linter->getIssues()));
    }

    /**
     * \A and \z do not read m, and an escaped dot does not read s: the
     * message names the exact construct that is missing.
     *
     * @return iterable<string, array{pattern: string, ruleId: string, message: string}>
     */
    public static function provideUselessFlagMessages(): iterable
    {
        yield 'm with only \A and \z' => [
            'pattern' => '/\Afoo\z/m',
            'ruleId' => 'regex.lint.flag.useless.m',
            'message' => "Flag 'm' is useless: the pattern contains no ^ or $ anchor.",
        ];
        yield 'm on a multi-line x pattern' => [
            'pattern' => "/a\nb # c\n/xm",
            'ruleId' => 'regex.lint.flag.useless.m',
            'message' => "Flag 'm' is useless: the pattern contains no ^ or $ anchor.",
        ];
        yield 's with only a dot inside a class' => [
            'pattern' => '/a[.]b/s',
            'ruleId' => 'regex.lint.flag.useless.s',
            'message' => "Flag 's' is useless: the pattern contains no unescaped dot outside a character class.",
        ];
        yield 's with only an escaped dot' => [
            'pattern' => '/a\.b/s',
            'ruleId' => 'regex.lint.flag.useless.s',
            'message' => "Flag 's' is useless: the pattern contains no unescaped dot outside a character class.",
        ];
    }

    #[DataProvider('provideUselessFlagMessages')]
    public function test_useless_flag_message_names_the_missing_construct(string $pattern, string $ruleId, string $message): void
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        $issue = $this->findIssueById($linter->getIssues(), $ruleId);

        $this->assertInstanceOf(RuleViolation::class, $issue);
        $this->assertSame($message, $issue->message);
        $this->assertStringNotContainsString("\n", $issue->message);
    }

    public function test_useless_flag_verdicts_match_the_engine(): void
    {
        // \A and \z ignore m; \. ignores s.
        $this->assertSame(preg_match('/\Afoo\z/m', "x\nfoo"), preg_match('/\Afoo\z/', "x\nfoo"));
        $this->assertSame(0, preg_match('/\Afoo\z/m', "x\nfoo"));
        $this->assertSame(preg_match('/a\.b/s', "a\nb"), preg_match('/a\.b/', "a\nb"));
        $this->assertSame(0, preg_match('/a\.b/s', "a\nb"));
    }

    public function test_useless_m_flag_no_anchors(): void
    {
        $regex = Regex::create()->parse('/\\d+/m');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $found = false;
        foreach ($warnings as $warning) {
            if (str_contains($warning, "Flag 'm' is useless:")) {
                $found = true;

                break;
            }
        }
        $this->assertTrue($found, "Expected 'Flag 'm' is useless:' warning not found in: ".implode(', ', $warnings));
        // The message names what is missing; it no longer embeds the pattern.
        $this->assertContains("Flag 'm' is useless: the pattern contains no ^ or $ anchor.", $warnings);
    }

    public function test_m_flag_not_useless_with_anchors(): void
    {
        $regex = Regex::create()->parse('/^test$/m');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        // The message checked is the one the rule emits, so this can fail.
        $this->assertNotContains("Flag 'm' is useless: the pattern contains no ^ or $ anchor.", $warnings);
        $this->assertNotContains('regex.lint.flag.useless.m', array_map(static fn (RuleViolation $issue): string => $issue->id, $linter->getIssues()));
    }

    public function test_start_anchor_conflict(): void
    {
        $regex = Regex::create()->parse('/foo^bar/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains("Start anchor '^' appears after consuming characters, making it impossible to match.", $warnings);
    }

    public function test_end_anchor_conflict(): void
    {
        $regex = Regex::create()->parse('/$foo/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains("End anchor '$' appears before consuming characters, making it impossible to match.", $warnings);
    }

    public function test_start_anchor_assertion_conflict(): void
    {
        $regex = Regex::create()->parse('/foo\\Abar/');
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issueIds = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
        $this->assertContains('regex.lint.anchor.impossible.start', $issueIds);
    }

    public function test_end_anchor_assertion_conflict(): void
    {
        $regex = Regex::create()->parse('/foo\\zbar/');
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issueIds = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
        $this->assertContains('regex.lint.anchor.impossible.end', $issueIds);
    }

    public function test_no_anchor_conflict_at_boundaries(): void
    {
        $regex = Regex::create()->parse('/^foo$/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("Start anchor '^' appears after consuming characters", $warnings);
        $this->assertNotContains("End anchor '$' appears before consuming characters", $warnings);
    }

    public function test_start_anchor_multiline_valid(): void
    {
        $regex = Regex::create()->parse('/^header\n^body/m');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("Start anchor '^' appears after consuming characters", $warnings);
    }

    public function test_start_anchor_false_positive(): void
    {
        $regex = Regex::create()->parse('/foo^bar/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains("Start anchor '^' appears after consuming characters, making it impossible to match.", $warnings);
    }

    public function test_start_anchor_no_multiline_flag(): void
    {
        $regex = Regex::create()->parse('/foo\\n^bar/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains("Start anchor '^' appears after consuming characters, making it impossible to match.", $warnings);
    }

    public function test_escaped_dollar_does_not_trigger_anchor_conflict(): void
    {
        $regex = Regex::create()->parse('/foo\\$bar/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains("End anchor '$' appears before consuming characters, making it impossible to match.", $warnings);
        $this->assertNotContains("Start anchor '^' appears after consuming characters, making it impossible to match.", $warnings);
    }

    #[DataProvider('provideAnchorConflictCases')]
    public function test_anchor_conflict_detection(string $pattern, bool $expectStart, bool $expectEnd): void
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $startMessage = "Start anchor '^' appears after consuming characters, making it impossible to match.";
        $endMessage = "End anchor '$' appears before consuming characters, making it impossible to match.";

        if ($expectStart) {
            $this->assertContains($startMessage, $warnings);
        } else {
            $this->assertNotContains($startMessage, $warnings);
        }

        if ($expectEnd) {
            $this->assertContains($endMessage, $warnings);
        } else {
            $this->assertNotContains($endMessage, $warnings);
        }
    }

    public static function provideAnchorConflictCases(): \Generator
    {
        yield 'escaped dollar literal' => ['/foo\\$bar/', false, false];
        yield 'escaped caret literal' => ['/foo\\^bar/', false, false];
        yield 'start anchor in sequence' => ['/foo^bar/', true, false];
        yield 'end anchor in sequence' => ['/$foo/', false, true];
        yield 'pcre verb before anchor' => ['/(*MARK:foo)^bar/', false, false];
        yield 'limit match verb before anchor' => ['/(*LIMIT_MATCH=10)^bar/', false, false];
        yield 'broken char class from corpus' => [
            trim(<<<'REGEX'
                /.*?(\$(?![0-9])(?:[a-zA-Z0-9-_]|(?:\[!"#$%&'\(\)*+,.\/:;<=>?@\[\]^{|}~]))+)/
                REGEX
            ),
            true,
            true,
        ];
        yield 'proper punctuation class' => [
            trim(<<<'REGEX'
                /.*?(\$(?![0-9])(?:[a-zA-Z0-9-_]|(?:[!"#$%&'\(\)*+,.\/:;<=>?@\[\]^{|}~]))+)/
                REGEX
            ),
            false,
            false,
        ];
    }

    #[DataProvider('provideRedundantCharClassHints')]
    public function test_redundant_char_class_hints(string $pattern, string $expectedHint): void
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issue = $this->findIssueById($linter->getIssues(), 'regex.lint.charclass.redundant');
        $this->assertInstanceOf(RuleViolation::class, $issue);
        $this->assertNotNull($issue->hint);
        $this->assertStringContainsString($expectedHint, (string) $issue->hint);
    }

    public static function provideRedundantCharClassHints(): \Generator
    {
        yield 'multipart boundary underscore duplicate' => [
            trim(<<<'REGEX'
                {multipart/form-data; boundary=(?|"([^"\r\n]++)"|([-!#$%&'*+.^_`|~_A-Za-z0-9]++))}
                REGEX
            ),
            "'_' (duplicate)",
        ];
        yield 'telegram punctuation range overlap' => [
            '/([.!#>+-=|{}~])/',
            "'.' (covered by range '+'-'=')",
        ];
        // Neither range is removable: [a-mk-z] matches "n", [a-m] does not.
        yield 'partial range overlap asks for a merge' => [
            '/[a-mk-z]/',
            "ranges 'a'-'m' and 'k'-'z' overlap: merge them into 'a'-'z'",
        ];
        yield 'earlier range covered by a later one' => [
            '/[b-cA-z]/',
            "range 'b'-'c' (covered by range 'A'-'z')",
        ];
        yield 'repeated range is unchanged' => [
            '/[a-zA-Za-z]/',
            "range 'a'-'z' (overlaps 'a'-'z')",
        ];
    }

    public function test_partial_range_overlap_is_not_called_redundant_range(): void
    {
        // Oracle: dropping either range changes what the class matches.
        $this->assertSame(1, preg_match('/^[a-mk-z]+$/', 'n'));
        $this->assertSame(0, preg_match('/^[a-m]+$/', 'n'));
        $this->assertSame(1, preg_match('/^[a-mk-z]+$/', 'a'));
        $this->assertSame(0, preg_match('/^[k-z]+$/', 'a'));

        $linter = new PatternLinter();
        Regex::create()->parse('/[a-mk-z]/')->accept($linter);
        $issue = $this->findIssueById($linter->getIssues(), 'regex.lint.charclass.redundant');

        $this->assertInstanceOf(RuleViolation::class, $issue);
        $this->assertStringNotContainsString("range 'k'-'z' (overlaps 'a'-'m')", (string) $issue->hint);
    }

    public function test_adjacent_ranges_are_not_redundant(): void
    {
        $linter = new PatternLinter();
        Regex::create()->parse('/[a-mn-z]/')->accept($linter);

        $this->assertNull($this->findIssueById($linter->getIssues(), 'regex.lint.charclass.redundant'));
    }

    #[DataProvider('provideInlineFlagRedundantHints')]
    public function test_inline_flag_redundant_hints(string $pattern, string $expectedHint): void
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issue = $this->findIssueById($linter->getIssues(), 'regex.lint.flag.redundant');
        $this->assertInstanceOf(RuleViolation::class, $issue);
        $this->assertNotNull($issue->hint);
        $this->assertStringContainsString($expectedHint, (string) $issue->hint);
    }

    public static function provideInlineFlagRedundantHints(): \Generator
    {
        yield 'simple redundant inline flag' => [
            '/(?-i:foo)/',
            "Remove '-i' from the inline flag group",
        ];
        yield 'symfony inline flag' => [
            trim(<<<'REGEX'
                /[\x80-\xFF]|(?<!\\)\\(?:\\\\)*+(?-i:X|[pP][\{CLMNPSZ]|x\{[A-Fa-f0-9]{3})/
                REGEX
            ),
            "Remove '-i' from the inline flag group",
        ];
    }

    public function test_char_class_group_tokens_are_literal(): void
    {
        $regex = Regex::create()->parse('/[?:()]+/');
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issueIds = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
        $warnings = $linter->getWarnings();

        $this->assertNotContains('regex.lint.charclass.redundant', $issueIds);
        $this->assertNotContains("Start anchor '^' appears after consuming characters", $warnings);
        $this->assertNotContains("End anchor '$' appears before consuming characters", $warnings);
    }

    public function test_backref_to_nonexistent_group(): void
    {
        $regex = Regex::create()->parse('/\\2/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains('Backreference \\2 refers to a non-existent capturing group.', $warnings);
    }

    public function test_backref_to_valid_group(): void
    {
        $regex = Regex::create()->parse('/(a)\\1/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Backreference \\1 refers to a non-existent capturing group.', $warnings);
    }

    public function test_g_backref_to_nonexistent_group(): void
    {
        $regex = Regex::create()->parse('/\\g{2}/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains('Backreference \\2 refers to a non-existent capturing group.', $warnings);
    }

    public function test_g_backref_relative_reference_is_not_flagged(): void
    {
        $regex = Regex::create()->parse('/(a)\\g{-1}/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Backreference \\1 refers to a non-existent capturing group.', $warnings);
    }

    public function test_named_backref_to_nonexistent_group(): void
    {
        $regex = Regex::create()->parse('/\\k<foo>/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains('Backreference \\k<foo> refers to a non-existent named group.', $warnings);
    }

    public function test_named_backref_to_valid_group(): void
    {
        $regex = Regex::create()->parse('/(?<foo>a)\\k<foo>/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Backreference \\k<foo> refers to a non-existent named group.', $warnings);
    }

    public function test_semantic_overlap_in_alternation_inside_quantifier(): void
    {
        // Overlapping alternations should only be flagged when inside an unbounded quantifier
        $regex = Regex::create()->parse('/([a-c]|[b-d])+/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    public function test_no_semantic_overlap_when_not_inside_quantifier(): void
    {
        // Without a quantifier, overlapping alternations don't cause ReDoS
        $regex = Regex::create()->parse('/[a-c]|[b-d]/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    public function test_no_semantic_overlap_in_alternation(): void
    {
        $regex = Regex::create()->parse('/[a-c]|[d-e]/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    public function test_no_overlap_warning_without_alternation(): void
    {
        $regex = Regex::create()->parse('/[0-9]/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    public function test_semantic_overlap_with_char_types_inside_quantifier(): void
    {
        // Overlapping alternations should only be flagged when inside an unbounded quantifier
        $regex = Regex::create()->parse('/(\\d|[0-9])+/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    public function test_no_overlap_for_line_ending_pattern(): void
    {
        // The canonical line-ending pattern should NOT be flagged as it's safe
        $regex = Regex::create()->parse('/\\r\\n|\\r|\\n/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    public function test_no_overlap_for_isbn_prefix_pattern(): void
    {
        // Fixed-length literal alternations should NOT be flagged
        $regex = Regex::create()->parse('/^(978|979)/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    public function test_overlap_inside_possessive_quantifier_not_flagged(): void
    {
        // Possessive quantifiers don't backtrack, so overlaps are safe
        $regex = Regex::create()->parse('/([a-c]|[b-d])++/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    public function test_overlap_inside_atomic_group_not_flagged(): void
    {
        // Atomic groups don't backtrack, so overlaps are safe
        $regex = Regex::create()->parse('/(?>[a-c]|[b-d])+/');
        $linter = new PatternLinter();
        $regex->accept($linter);
        $warnings = $linter->getWarnings();

        $this->assertNotContains('Alternation branches have overlapping character sets, which may cause unnecessary backtracking.', $warnings);
    }

    // ---------------------------------------------------------------
    // Backreference-as-octal inside character class
    // ---------------------------------------------------------------

    #[DataProvider('provideBackrefAsOctalInCharClassCases')]
    public function test_backref_as_octal_in_char_class(string $pattern, bool $expectWarning): void
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issue = $this->findIssueById($linter->getIssues(), 'regex.lint.charclass.backrefAsOctal');

        if ($expectWarning) {
            $this->assertInstanceOf(RuleViolation::class, $issue, "Expected backref_as_octal warning for: {$pattern}");
            $this->assertNotNull($issue->hint);
        } else {
            $this->assertNotInstanceOf(RuleViolation::class, $issue, "Did NOT expect backref_as_octal warning for: {$pattern}");
        }
    }

    public static function provideBackrefAsOctalInCharClassCases(): \Generator
    {
        yield 'backref \1 in negated class with one group' => ['/(a)([^\1]*?)\1/', true];
        yield 'backref \2 in class with two groups' => ['/(a)(b)[\2]/', true];
        yield 'backref \1 in non-negated class' => ['/(a)[\1]/', true];
        yield 'no group defined — just octal' => ['/[\1]/', false];
        yield 'octal \0 is not a backref' => ['/(a)[\0]/', false];
        yield 'three-digit octal \177 is not a backref' => ['/(a)[\177]/', false];
        yield 'backref \1 outside class is fine' => ['/(a)\1/', false];
        yield 'backref \7 with enough groups' => ['/(a)(b)(c)(d)(e)(f)(g)[\7]/', true];
        yield 'high digit without enough groups' => ['/(a)[\9]/', false];
    }

    // ---------------------------------------------------------------
    // Literal metacharacter inside character class
    // ---------------------------------------------------------------

    #[DataProvider('provideLiteralMetacharInCharClassCases')]
    public function test_literal_metachar_in_char_class(string $pattern, bool $expectWarning): void
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issue = $this->findIssueById($linter->getIssues(), 'regex.lint.charclass.literalMetachar');

        if ($expectWarning) {
            $this->assertInstanceOf(RuleViolation::class, $issue, "Expected literal_metachar warning for: {$pattern}");
            $this->assertNotNull($issue->hint);
        } else {
            $this->assertNotInstanceOf(RuleViolation::class, $issue, "Did NOT expect literal_metachar warning for: {$pattern}");
        }
    }

    public static function provideLiteralMetacharInCharClassCases(): \Generator
    {
        yield 'plus with \w shorthand' => ['pattern' => '/[\w+]*/', 'expectWarning' => true];
        yield 'star with \d shorthand' => ['pattern' => '/[\d*]/', 'expectWarning' => true];
        yield 'question mark with \s shorthand' => ['pattern' => '/[\s?]/', 'expectWarning' => true];
        yield 'plus without shorthand — not flagged' => ['pattern' => '/[a-z+]/', 'expectWarning' => false];
        yield 'star without shorthand — not flagged' => ['pattern' => '/[0-9*]/', 'expectWarning' => false];
        yield 'no metachar with shorthand — not flagged' => ['pattern' => '/[\w-]/', 'expectWarning' => false];
        yield 'plus with \W shorthand' => ['pattern' => '/[\W+]/', 'expectWarning' => true];
        yield 'negated class — not flagged' => ['pattern' => '/[^\s+]/', 'expectWarning' => false];
        yield 'multi-element URI scheme — not flagged' => ['pattern' => '/[a-z\d+.-]/', 'expectWarning' => false];
        yield 'multi-element base64 — not flagged' => ['pattern' => '/[a-zA-Z\d\/+]/', 'expectWarning' => false];
        // An escaped metacharacter says the author meant the literal.
        yield 'escaped star with \w shorthand — not flagged' => ['pattern' => '/[\w\*]/', 'expectWarning' => false];
        yield 'escaped plus with \w shorthand — not flagged' => ['pattern' => '/[\w\+]/', 'expectWarning' => false];
        yield 'escaped question mark with \w shorthand — not flagged' => ['pattern' => '/[\w\?]/', 'expectWarning' => false];
        yield 'escaped star with \d shorthand — not flagged' => ['pattern' => '/[\d\*]/', 'expectWarning' => false];
        yield 'star with \w shorthand' => ['pattern' => '/[\w*]/', 'expectWarning' => true];
    }

    public function test_escaped_and_bare_metachar_in_class_match_the_same_byte(): void
    {
        // Oracle: the escape changes nothing for the engine, only the intent.
        foreach (['*', '+', '?'] as $metachar) {
            $this->assertSame(1, preg_match('/^[\w\\'.$metachar.']$/', $metachar));
            $this->assertSame(1, preg_match('/^[\w'.$metachar.']$/', $metachar));
        }
    }

    // ---------------------------------------------------------------
    // (.|\n) anti-pattern detection
    // ---------------------------------------------------------------

    #[DataProvider('provideDotNewlineAntiPatternCases')]
    public function test_dot_newline_anti_pattern(string $pattern, bool $expectWarning, ?string $hintFragment = null): void
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issue = $this->findIssueById($linter->getIssues(), 'regex.lint.alternation.dotNewline');

        if ($expectWarning) {
            $this->assertInstanceOf(RuleViolation::class, $issue, "Expected dot_newline warning for: {$pattern}");
            if (null !== $hintFragment) {
                $this->assertNotNull($issue->hint);
                $this->assertStringContainsString($hintFragment, (string) $issue->hint);
            }
        } else {
            $this->assertNotInstanceOf(RuleViolation::class, $issue, "Did NOT expect dot_newline warning for: {$pattern}");
        }
    }

    public static function provideDotNewlineAntiPatternCases(): \Generator
    {
        yield 'dot-or-newline quantified' => ['/(.|\\n)+/', true, '[\s\S]'];
        yield 'newline-or-dot quantified' => ['/(\\n|.)+/', true, '[\s\S]'];
        yield 'dot-or-newline without quantifier — still an anti-pattern' => ['/.|\\n/', true, '[\s\S]'];
        yield 'dot-or-newline with s flag suggests clarity' => ['/(.|\\n)+/s', true, 'already active'];
        yield 'three-way alternation — not the pattern' => ['/(.|\\n|x)+/', false];
        yield 'dot-or-carriage-return is not the pattern' => ['/(.|\\r)+/', false];
        yield 'just a dot — not the pattern' => ['/.+/', false];
    }

    // ---------------------------------------------------------------
    // Quantified capturing group
    // ---------------------------------------------------------------

    #[DataProvider('provideQuantifiedCapturingGroupCases')]
    public function test_quantified_capturing_group(string $pattern, bool $expectWarning, ?LintSeverity $expectedSeverity = null): void
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issue = $this->findIssueById($linter->getIssues(), 'regex.lint.group.quantifiedCapture');

        if ($expectWarning) {
            $this->assertInstanceOf(RuleViolation::class, $issue, "Expected quantified_capture warning for: {$pattern}");
            $this->assertNotNull($issue->hint);
            if (null !== $expectedSeverity) {
                $this->assertSame($expectedSeverity, $issue->severity, "Expected severity {$expectedSeverity->value} for: {$pattern}");
            }
        } else {
            $this->assertNotInstanceOf(RuleViolation::class, $issue, "Did NOT expect quantified_capture warning for: {$pattern}");
        }
    }

    public static function provideQuantifiedCapturingGroupCases(): \Generator
    {
        yield 'named group with + — Warning' => ['/(?<digit>\d+)+/', true, LintSeverity::Warning];
        yield 'numbered group with + — Info' => ['/(\d+)+/', true, LintSeverity::Info];
        yield 'numbered group with * — Info' => ['/(\d+)*/', true, LintSeverity::Info];
        yield 'numbered group with {2,} — Info' => ['/(\d+){2,}/', true, LintSeverity::Info];
        yield 'exact repetition {3} — still warns' => ['/(\d+){3}/', true, LintSeverity::Info];
        yield 'non-capturing group — not flagged' => ['/(?:\d+)+/', false];
        yield 'optional group (?) — not flagged' => ['/(\d+)?/', false];
        yield 'single repetition {1} — not flagged' => ['/(\d+){1}/', false];
        yield 'atomic group — not flagged' => ['/(?>(\d+))+/', false];
    }

    /**
     * @param array<RuleViolation> $issues
     */
    private function findIssueById(array $issues, string $id): ?RuleViolation
    {
        foreach ($issues as $issue) {
            if ($issue->id === $id) {
                return $issue;
            }
        }

        return null;
    }
}

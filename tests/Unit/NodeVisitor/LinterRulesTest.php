<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Linter\PatternLinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinterRulesTest extends TestCase
{
    public function test_nested_quantifier_warning(): void
    {
        $issues = $this->lint('/(a+)+/');
        $this->assertContains('regex.lint.quantifier.nested', $issues);
    }

    public function test_nested_quantifier_warning_skips_possessive_quantifiers(): void
    {
        $issues = $this->lint('/(?:a*+)+/');
        $this->assertNotContains('regex.lint.quantifier.nested', $issues);

        $issues = $this->lint('/(?:a+)++/');
        $this->assertNotContains('regex.lint.quantifier.nested', $issues);
    }

    #[DataProvider('provideNestedQuantifierPatterns')]
    public function test_nested_quantifier_warning_respects_separators(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);
        $hasWarning = \in_array('regex.lint.quantifier.nested', $issues, true);

        $this->assertSame($shouldWarn, $hasWarning);
    }

    public function test_dotstar_in_unbounded_quantifier_warning(): void
    {
        $issues = $this->lint('/(?:.*)+/');
        $this->assertContains('regex.lint.dotstar.nested', $issues);
    }

    public function test_redundant_non_capturing_group_warning(): void
    {
        $issues = $this->lint('/(?:a)/');
        $this->assertContains('regex.lint.group.redundant', $issues);
    }

    public function test_alternation_duplicate_warning(): void
    {
        $issues = $this->lint('/(a|a)/');
        $this->assertContains('regex.lint.alternation.duplicateDisjunction', $issues);
    }

    public function test_alternation_overlap_warning(): void
    {
        // Overlapping alternation must be inside an unbounded quantifier to trigger warning
        // (patterns like /(a|aa)/ without quantifier don't pose ReDoS risk)
        $issues = $this->lint('/(a|aa)+/');
        $this->assertContains('regex.lint.alternation.overlap', $issues);
    }

    public function test_alternation_duplicate_with_lookarounds(): void
    {
        $issues = $this->lint('/(?=foo)|(?=foo)/');
        $this->assertContains('regex.lint.alternation.duplicateDisjunction', $issues);
    }

    public function test_redundant_char_class_warning(): void
    {
        $issues = $this->lint('/[a-zA-Za-z]/');
        $this->assertContains('regex.lint.charclass.redundant', $issues);
    }

    public function test_suspicious_char_class_range_warning(): void
    {
        $issues = $this->lint('/[A-z]/');
        $this->assertContains('regex.lint.charclass.suspiciousRange', $issues);
    }

    public function test_suspicious_char_class_range_message_uses_ascii_order(): void
    {
        $regex = Regex::create()->parse('/[A-z]/');
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issues = array_values(array_filter(
            $linter->getIssues(),
            static fn ($issue): bool => 'regex.lint.charclass.suspiciousRange' === $issue->id,
        ));

        $this->assertCount(1, $issues);
        $this->assertSame(
            'Suspicious ASCII range "A-z" includes non-letters between "A" and "z" in ASCII order.',
            $issues[0]->message,
        );
    }

    public function test_suspicious_char_class_pipe_warning(): void
    {
        $issues = $this->lint('/[error|failure]/');
        $this->assertContains('regex.lint.charclass.suspiciousPipe', $issues);
    }

    public function test_suspicious_char_class_pipe_ignores_small_sets(): void
    {
        $issues = $this->lint('/[a|b]/');
        $this->assertNotContains('regex.lint.charclass.suspiciousPipe', $issues);
    }

    public function test_inline_flag_redundant_warning(): void
    {
        $issues = $this->lint('/(?i:foo)/i');
        $this->assertContains('regex.lint.flag.redundant', $issues);
    }

    public function test_inline_flag_override_warning(): void
    {
        $issues = $this->lint('/(?-i:foo)/i');
        $this->assertContains('regex.lint.flag.override', $issues);
    }

    public function test_inline_flag_override_respects_preceding_inline_flag(): void
    {
        $issues = $this->lint('/(?i)(?-i:foo)/');
        $this->assertContains('regex.lint.flag.override', $issues);
        $this->assertNotContains('regex.lint.flag.redundant', $issues);
    }

    public function test_inline_flag_redundant_message_names_the_global_modifier(): void
    {
        $messages = $this->lintMessages('/(?i:foo)/i');

        $this->assertContains("Inline flag 'i' is redundant; it is already set globally.", $messages);
    }

    public function test_inline_flag_redundant_message_names_the_preceding_inline_group(): void
    {
        $messages = $this->lintMessages('/(?U)a(?U)b/');

        $this->assertContains("Inline flag 'U' is redundant; it is already set by an earlier inline flag group.", $messages);
    }

    public function test_inline_flag_redundant_ignores_case_of_global_modifier(): void
    {
        $issues = $this->lint('/(?U)a/u');

        $this->assertNotContains('regex.lint.flag.redundant', $issues);
    }

    public function test_suspicious_unicode_escape_warning(): void
    {
        $issues = $this->lint('/\\x{110000}/');
        $this->assertContains('regex.lint.escape.suspicious', $issues);
    }

    public function test_useless_flag_s_warning(): void
    {
        $issues = $this->lint('/no_dot/s');
        $this->assertContains('regex.lint.flag.useless.s', $issues);
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideNestedQuantifierPatterns(): \Iterator
    {
        yield 'safe dot separator' => ['/([0-9]+(?:\\.[0-9]+)*)/', false];
        yield 'safe hyphen separator' => ['/(a+(?:-a+)*)/', false];
        yield 'safe comma separator' => ['/(\\d+(?:,\\d+)*)/', false];
        yield 'unsafe no separator' => ['/(a+(?:a+)*)/', true];
        yield 'unsafe overlapping separator' => ['/(\\w+(?:_\\w+)*)/', true];
        yield 'unsafe direct overlap' => ['/([0-9]+(?:[0-9]+)*)/', true];
    }

    /**
     * @return array<string>
     */
    private function lintMessages(string $pattern): array
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        return array_map(static fn ($issue) => $issue->message, $linter->getIssues());
    }

    /**
     * @return array<string>
     */
    private function lint(string $pattern): array
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        return array_map(static fn ($issue) => $issue->id, $linter->getIssues());
    }
}

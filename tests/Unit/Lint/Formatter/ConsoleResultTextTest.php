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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\ConsoleResultText;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What both console formatters show of a result: its pattern, and each
 * message with the caret snippet under it.
 */
final class ConsoleResultTextTest extends TestCase
{
    #[Test]
    public function test_the_pattern_of_a_result_is_its_own(): void
    {
        $result = ['file' => 'test.php', 'line' => 1, 'pattern' => '/own/', 'issues' => [new LintIssue(LintSeverity::Error, 'm', 'test.php', 1)], 'optimizations' => []];

        $this->assertSame('/own/', ConsoleResultText::pattern($result));
    }

    #[Test]
    public function test_a_result_without_a_pattern_quotes_none_of_its_issues(): void
    {
        // An issue holds no pattern: only its result and its optimizations do.
        $result = ['file' => 'test.php', 'line' => 1, 'pattern' => null, 'issues' => [new LintIssue(LintSeverity::Error, 'm', 'test.php', 1)], 'optimizations' => []];

        $this->assertNull(ConsoleResultText::pattern($result));
    }

    #[Test]
    public function test_a_result_without_a_pattern_or_an_issue_takes_the_original_of_its_first_optimization(): void
    {
        $optimization = Regex::create()->optimize('/foo/');
        $result = ['file' => 'test.php', 'line' => 1, 'pattern' => null, 'issues' => [], 'optimizations' => [['file' => 'test.php', 'line' => 1, 'optimization' => $optimization, 'savings' => 0]]];

        $this->assertSame($optimization->original, ConsoleResultText::pattern($result));
    }

    #[Test]
    public function test_a_result_with_nothing_to_quote_has_no_pattern(): void
    {
        $result = ['file' => 'test.php', 'line' => 1, 'pattern' => '', 'issues' => [new LintIssue(LintSeverity::Error, 'm', 'test.php', 1)], 'optimizations' => []];

        $this->assertNull(ConsoleResultText::pattern($result));
    }

    #[Test]
    public function test_a_message_carries_the_caret_snippet_of_its_validation_under_it(): void
    {
        $validation = new ValidationResult(false, 'No closing parenthesis', 0, null, 1, "/(/\n  ^");
        $issue = new LintIssue(LintSeverity::Error, 'No closing parenthesis', 'test.php', 1, validation: $validation);

        $this->assertSame("No closing parenthesis\n/(/\n  ^", ConsoleResultText::messageWithSnippet($issue, '/(/'));
    }

    #[Test]
    public function test_a_message_without_a_snippet_stands_alone(): void
    {
        $issue = new LintIssue(LintSeverity::Warning, 'Nested quantifiers', 'test.php', 1, validation: new ValidationResult(true));

        $this->assertSame('Nested quantifiers', ConsoleResultText::messageWithSnippet($issue, '/(a+)+/'));
    }

    #[Test]
    public function test_a_line_label_at_the_start_of_a_line_is_blanked_out(): void
    {
        $this->assertSame(
            str_repeat(' ', \strlen('Line 12:'))." a\n".str_repeat(' ', \strlen('Line 345:'))." b\nsee Line 3: c",
            ConsoleResultText::withoutLineLabels("Line 12: a\nLine 345: b\nsee Line 3: c"),
        );
    }
}

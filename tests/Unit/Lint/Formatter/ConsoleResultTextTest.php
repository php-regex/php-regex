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
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * What both console formatters show of a result: its pattern, and each
 * message with the caret snippet under it.
 */
final class ConsoleResultTextTest extends TestCase
{
    public function test_the_pattern_of_a_result_is_its_own(): void
    {
        $result = ['file' => 'test.php', 'line' => 1, 'pattern' => '/own/', 'issues' => [['type' => 'error', 'message' => 'm', 'file' => 'test.php', 'line' => 1, 'pattern' => '/from-issue/']], 'optimizations' => [], 'problems' => []];

        $this->assertSame('/own/', ConsoleResultText::pattern($result));
    }

    public function test_a_result_without_a_pattern_takes_the_one_of_its_first_issue(): void
    {
        $result = ['file' => 'test.php', 'line' => 1, 'pattern' => null, 'issues' => [['type' => 'error', 'message' => 'm', 'file' => 'test.php', 'line' => 1, 'pattern' => '/from-issue/']], 'optimizations' => [], 'problems' => []];

        $this->assertSame('/from-issue/', ConsoleResultText::pattern($result));
    }

    public function test_a_result_without_a_pattern_or_an_issue_takes_the_original_of_its_first_optimization(): void
    {
        $optimization = Regex::create()->optimize('/foo/');
        $result = ['file' => 'test.php', 'line' => 1, 'pattern' => null, 'issues' => [], 'optimizations' => [['file' => 'test.php', 'line' => 1, 'optimization' => $optimization, 'savings' => 0]], 'problems' => []];

        $this->assertSame($optimization->original, ConsoleResultText::pattern($result));
    }

    public function test_a_result_with_nothing_to_quote_has_no_pattern(): void
    {
        $result = ['file' => 'test.php', 'line' => 1, 'pattern' => '', 'issues' => [['type' => 'error', 'message' => 'm', 'file' => 'test.php', 'line' => 1]], 'optimizations' => [], 'problems' => []];

        $this->assertNull(ConsoleResultText::pattern($result));
    }

    public function test_a_message_carries_the_caret_snippet_of_its_validation_under_it(): void
    {
        $validation = new ValidationResult(false, 'No closing parenthesis', 0, null, 1, "/(/\n  ^");
        $issue = ['type' => 'error', 'message' => 'No closing parenthesis', 'file' => 'test.php', 'line' => 1, 'validation' => $validation];

        $this->assertSame("No closing parenthesis\n/(/\n  ^", ConsoleResultText::messageWithSnippet($issue, '/(/'));
    }

    public function test_a_message_without_a_snippet_stands_alone(): void
    {
        $issue = ['type' => 'warning', 'message' => 'Nested quantifiers', 'file' => 'test.php', 'line' => 1, 'validation' => new ValidationResult(true)];

        $this->assertSame('Nested quantifiers', ConsoleResultText::messageWithSnippet($issue, '/(a+)+/'));
    }

    public function test_a_line_label_at_the_start_of_a_line_is_blanked_out(): void
    {
        $this->assertSame(
            str_repeat(' ', \strlen('Line 12:'))." a\n".str_repeat(' ', \strlen('Line 345:'))." b\nsee Line 3: c",
            ConsoleResultText::withoutLineLabels("Line 12: a\nLine 345: b\nsee Line 3: c"),
        );
    }
}

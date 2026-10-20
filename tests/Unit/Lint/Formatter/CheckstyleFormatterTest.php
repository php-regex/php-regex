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

use PHPRegex\Linter\Formatter\CheckstyleFormatter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Tests\Support\LintIssueFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckstyleFormatterTest extends TestCase
{
    private CheckstyleFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CheckstyleFormatter();
    }

    public function test_format_empty_report(): void
    {
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContainsString('<checkstyle version="4.3">', $output);
        $this->assertStringContainsString('</checkstyle>', $output);
        $this->assertStringNotContainsString('<file', $output);
    }

    public function test_format_with_single_problem(): void
    {
        $problem = LintIssueFixtures::invalid('Invalid > pattern');

        $result = [
            'file' => '/path/to/file.php',
            'line' => 10,
            'column' => 12,
            'source' => 'preg_match',
            'pattern' => '/test/',
            'location' => 'in function call',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<file name="/path/to/file.php">', $output);
        $this->assertStringContainsString('line="10"', $output);
        // The column of the pattern in the file, not the position 5 inside the pattern.
        $this->assertStringContainsString('column="12"', $output);
        $this->assertStringContainsString('severity="error"', $output);
        $this->assertStringContainsString('source="'.LintIssueFixtures::INVALID_CODE.'"', $output);
        $this->assertStringContainsString('Invalid &gt; pattern', $output);
        $this->assertStringContainsString('Location: in function call', $output);
        $this->assertStringContainsString('Line 1: a**', $output);
        $this->assertStringContainsString('Suggestion: '.LintIssueFixtures::INVALID_HINT, $output);
    }

    public function test_format_with_multiple_problems(): void
    {
        $problem1 = LintIssueFixtures::finding(LintSeverity::Warning, 'Nested quantifier', 'regex.lint.quantifier.nested');

        $problem2 = LintIssueFixtures::redos(LintSeverity::Error, 'ReDoS risk', 'regex.redos');

        $result1 = [
            'file' => 'file1.php',
            'line' => 5,
            'pattern' => '/test1/',
            'optimizations' => [],
            'issues' => [$problem1],
        ];

        $result2 = [
            'file' => 'file2.php',
            'line' => 15,
            'pattern' => '/test2/',
            'optimizations' => [],
            'issues' => [$problem2],
        ];

        $report = new LintReport([$result1, $result2], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<file name="file1.php">', $output);
        $this->assertStringContainsString('<file name="file2.php">', $output);
        $this->assertStringContainsString('severity="warning"', $output);
        $this->assertStringContainsString('severity="error"', $output);
        // The source is the issue's identifier, as the JSON report gives it.
        $this->assertStringContainsString('source="regex.lint.quantifier.nested"', $output);
        $this->assertStringContainsString('source="regex.redos"', $output);
        $this->assertStringNotContainsString('php-regex.regex.', $output);
    }

    public function test_format_with_different_severities(): void
    {
        $problems = [
            LintIssueFixtures::finding(LintSeverity::Info, 'Info message'),
            LintIssueFixtures::finding(LintSeverity::Warning, 'Warning message'),
            LintIssueFixtures::invalid('Error message'),
            LintIssueFixtures::redos(LintSeverity::Critical, 'Critical message', null),
        ];

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => $problems,
        ];

        $report = new LintReport([$result], ['errors' => 2, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('severity="info"', $output);
        $this->assertStringContainsString('severity="warning"', $output);
        $this->assertStringContainsString('severity="error"', $output);
        $this->assertStringContainsString('Info message', $output);
        $this->assertStringContainsString('Warning message', $output);
        $this->assertStringContainsString('Error message', $output);
        $this->assertStringContainsString('Critical message', $output);
    }

    public function test_format_normalizes_file_paths(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test');

        $result = [
            'file' => 'C:\\Windows\\test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<file name="C:/Windows/test.php">', $output);
    }

    public function test_format_normalizes_line_numbers(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test');

        $result = [
            'file' => 'test.php',
            'line' => 0,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('line="1"', $output);
    }

    /**
     * The column attribute is optional in Checkstyle: a column the report
     * does not know is left out, never invented.
     */
    public function test_format_normalizes_column_positions(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<error line="1" severity="error"', $output);
        $this->assertStringNotContainsString('column=', $output);
    }

    public function test_format_writes_the_column_of_the_pattern_in_the_file(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test');

        $result = [
            'file' => 'test.php',
            'line' => 4,
            'column' => 17,
            'pattern' => '/(a/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<error line="4" column="17" severity="error"', $output);
    }

    public function test_format_escapes_xml(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Message with <tags> & "quotes"');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('Message with &lt;tags&gt; &amp; &quot;quotes&quot;', $output);
    }

    public function test_format_error(): void
    {
        $message = 'Test error message with <tags> & "quotes"';

        $output = $this->formatter->formatError($message);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContainsString('<checkstyle version="4.3">', $output);
        $this->assertStringContainsString('<file name="php-regex">', $output);
        $this->assertStringContainsString('Test error message with &lt;tags&gt; &amp; &quot;quotes&quot;', $output);
        $this->assertStringContainsString('</checkstyle>', $output);
    }

    public function test_format_with_minimal_problem(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Info, 'Simple message');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('source="php-regex"', $output);
        $this->assertStringContainsString('Simple message', $output);
    }

    #[Test]
    public function test_format_leaves_the_column_out_when_the_key_is_absent(): void
    {
        $problem = LintIssueFixtures::invalid('Invalid regex pattern');

        // As the JUnit build writes it: no column key at all.
        $result = [
            'file' => '/path/to/file.php',
            'line' => 10,
            'source' => 'preg_match',
            'pattern' => '/test/',
            'location' => 'in function call',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('line="10"', $output);
        $this->assertStringNotContainsString('column=', $output);
    }

    /**
     * A finding the docs explain names its page at the end of its message,
     * the one free text field of the format; an invalid pattern does not.
     */
    #[Test]
    public function test_format_ends_the_message_of_a_documented_finding_with_its_docs(): void
    {
        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/a{1}/',
            'optimizations' => [],
            'issues' => [
                LintIssueFixtures::finding(LintSeverity::Warning, 'Useless quantifier', 'regex.lint.quantifier.useless'),
                LintIssueFixtures::invalid(),
            ],
        ];

        $output = $this->formatter->format(new LintReport([$result], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]));

        $this->assertStringContainsString('message="Useless quantifier&#10;Read more: https://php-regex.com/reference/rules/#useless-quantifier" source="regex.lint.quantifier.useless"', $output);
        $this->assertSame(1, substr_count($output, 'Read more:'));
    }
}

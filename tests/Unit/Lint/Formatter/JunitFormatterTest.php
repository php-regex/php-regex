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

use PHPRegex\Linter\Formatter\JunitFormatter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Tests\Support\LintIssueFixtures;
use PHPUnit\Framework\TestCase;

final class JunitFormatterTest extends TestCase
{
    private JunitFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new JunitFormatter();
    }

    public function test_format_empty_report(): void
    {
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContainsString('<testsuite name="php-regex" tests="0" failures="0" errors="0" skipped="0">', $output);
        $this->assertStringContainsString('</testsuite>', $output);
    }

    public function test_format_with_critical_problem(): void
    {
        $problem = LintIssueFixtures::redos(LintSeverity::Critical, 'Critical security issue', 'regex.redos');

        $result = [
            'file' => '/path/to/file.php',
            'line' => 10,
            'source' => 'preg_match',
            'pattern' => '/test/',
            'location' => 'in function call',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testsuite name="php-regex" tests="1" failures="0" errors="1" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="Security (regex.redos)" classname="/path/to/file.php:10">', $output);
        $this->assertStringContainsString('<error message="Critical security issue">', $output);
        $this->assertStringContainsString('Critical security issue', $output);
        $this->assertStringContainsString('Location: in function call', $output);
        $this->assertStringContainsString(htmlspecialchars(LintIssueFixtures::REDOS_EVIDENCE, \ENT_XML1 | \ENT_QUOTES), $output);
        $this->assertStringContainsString('Suggestion: '.htmlspecialchars(LintIssueFixtures::redosAdvice(), \ENT_XML1 | \ENT_QUOTES), $output);
    }

    public function test_format_with_error_problem(): void
    {
        $problem = LintIssueFixtures::invalid('Syntax error');

        $result = [
            'file' => 'test.php',
            'line' => 5,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testsuite name="php-regex" tests="1" failures="1" errors="0" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="Syntax ('.LintIssueFixtures::INVALID_CODE.')" classname="test.php:5">', $output);
        $this->assertStringContainsString('<failure message="Syntax error">', $output);
    }

    public function test_format_with_warning_problem(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Warning, 'Lint warning', 'regex.lint');

        $result = [
            'file' => 'file.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testsuite name="php-regex" tests="1" failures="0" errors="0" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="Lint (regex.lint)" classname="file.php:1">', $output);
        $this->assertStringContainsString('<system-out>Lint warning</system-out>', $output);
    }

    public function test_format_with_info_problem(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Info, 'Info message');

        $result = [
            'file' => 'info.php',
            'line' => 2,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testcase name="Lint" classname="info.php:2">', $output);
        $this->assertStringContainsString('<system-out>Info message</system-out>', $output);
    }

    public function test_format_with_multiple_problems(): void
    {
        $problems = [
            LintIssueFixtures::invalid('Error 1'),
            LintIssueFixtures::finding(LintSeverity::Warning, 'Warning 1'),
            LintIssueFixtures::redos(LintSeverity::Critical, 'Critical 1', null),
        ];

        $result = [
            'file' => 'multi.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => $problems,
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testsuite name="php-regex" tests="3" failures="1" errors="1" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="Syntax ('.LintIssueFixtures::INVALID_CODE.')"', $output);
        $this->assertStringContainsString('<testcase name="Lint"', $output);
        $this->assertStringContainsString('<testcase name="Security"', $output);
        $this->assertStringContainsString('<error message="Critical 1">', $output);
        $this->assertStringContainsString('<failure message="Error 1">', $output);
        $this->assertStringContainsString('<system-out>Warning 1</system-out>', $output);
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

        $this->assertStringContainsString('classname="C:/Windows/test.php:1"', $output);
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

        $this->assertStringContainsString('classname="test.php:1"', $output);
    }

    public function test_format_calculates_column_positions(): void
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

        // The column is calculated but not directly visible in output
        // This test just ensures the code runs without errors
        $this->assertStringContainsString('<testcase', $output);
        $this->assertStringContainsString('Test', $output);
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

    public function test_format_problem_title_with_code(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test message', 'regex.lint.test');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('name="Lint (regex.lint.test)"', $output);
    }

    public function test_format_problem_title_without_code(): void
    {
        $problem = LintIssueFixtures::redos(LintSeverity::Error, 'Test message', null);

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('name="Security"', $output);
    }

    public function test_format_error(): void
    {
        $message = 'Test error message with <tags> & "quotes"';

        $output = $this->formatter->formatError($message);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContainsString('<testsuite name="php-regex" tests="1" failures="1" errors="0" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="pattern-collection">', $output);
        $this->assertStringContainsString('Test error message with &lt;tags&gt; &amp; &quot;quotes&quot;', $output);
        $this->assertStringContainsString('</testsuite>', $output);
    }
}

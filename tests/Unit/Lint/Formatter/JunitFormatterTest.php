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

use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\Formatter\JunitFormatter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPUnit\Framework\TestCase;

final class JunitFormatterTest extends TestCase
{
    private JunitFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new JunitFormatter();
    }

    public function test_construct(): void
    {
        $this->assertInstanceOf(JunitFormatter::class, new JunitFormatter());
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
        $problem = new Diagnostic(
            DiagnosticType::Security,
            LintSeverity::Critical,
            'Critical security issue',
            'regex.redos',
            5,
            'vulnerable pattern',
            'Use atomic groups',
        );

        $result = [
            'file' => '/path/to/file.php',
            'line' => 10,
            'source' => 'preg_match',
            'pattern' => '/test/',
            'location' => 'in function call',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testsuite name="php-regex" tests="1" failures="0" errors="1" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="Security (regex.redos)" classname="/path/to/file.php:10">', $output);
        $this->assertStringContainsString('<error message="Critical security issue">', $output);
        $this->assertStringContainsString('Critical security issue', $output);
        $this->assertStringContainsString('Location: in function call', $output);
        $this->assertStringContainsString('vulnerable pattern', $output);
        $this->assertStringContainsString('Suggestion: Use atomic groups', $output);
    }

    public function test_format_with_error_problem(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Syntax,
            LintSeverity::Error,
            'Syntax error',
            'regex.syntax',
            3,
            'bad syntax',
            'Fix syntax',
        );

        $result = [
            'file' => 'test.php',
            'line' => 5,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testsuite name="php-regex" tests="1" failures="1" errors="0" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="Syntax (regex.syntax)" classname="test.php:5">', $output);
        $this->assertStringContainsString('<failure message="Syntax error">', $output);
    }

    public function test_format_with_warning_problem(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Warning,
            'Lint warning',
            'regex.lint',
            null,
            null,
            null,
        );

        $result = [
            'file' => 'file.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testsuite name="php-regex" tests="1" failures="0" errors="0" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="Lint (regex.lint)" classname="file.php:1">', $output);
        $this->assertStringContainsString('<system-out>Lint warning</system-out>', $output);
    }

    public function test_format_with_info_problem(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Info,
            'Info message',
            null,
            null,
            null,
            null,
        );

        $result = [
            'file' => 'info.php',
            'line' => 2,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testcase name="Lint" classname="info.php:2">', $output);
        $this->assertStringContainsString('<system-out>Info message</system-out>', $output);
    }

    public function test_format_with_multiple_problems(): void
    {
        $problems = [
            new Diagnostic(DiagnosticType::Syntax, LintSeverity::Error, 'Error 1', null, null, null, null),
            new Diagnostic(DiagnosticType::Lint, LintSeverity::Warning, 'Warning 1', null, null, null, null),
            new Diagnostic(DiagnosticType::Security, LintSeverity::Critical, 'Critical 1', null, null, null, null),
        ];

        $result = [
            'file' => 'multi.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => $problems,
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<testsuite name="php-regex" tests="3" failures="1" errors="1" skipped="0">', $output);
        $this->assertStringContainsString('<testcase name="Syntax"', $output);
        $this->assertStringContainsString('<testcase name="Lint"', $output);
        $this->assertStringContainsString('<testcase name="Security"', $output);
        $this->assertStringContainsString('<error message="Critical 1">', $output);
        $this->assertStringContainsString('<failure message="Error 1">', $output);
        $this->assertStringContainsString('<system-out>Warning 1</system-out>', $output);
    }

    public function test_format_normalizes_file_paths(): void
    {
        $problem = new Diagnostic(DiagnosticType::Lint, LintSeverity::Error, 'Test', null, null, null, null);

        $result = [
            'file' => 'C:\\Windows\\test.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('classname="C:/Windows/test.php:1"', $output);
    }

    public function test_format_normalizes_line_numbers(): void
    {
        $problem = new Diagnostic(DiagnosticType::Lint, LintSeverity::Error, 'Test', null, null, null, null);

        $result = [
            'file' => 'test.php',
            'line' => 0,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('classname="test.php:1"', $output);
    }

    public function test_format_calculates_column_positions(): void
    {
        $problem = new Diagnostic(DiagnosticType::Lint, LintSeverity::Error, 'Test', null, 5, null, null);

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
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
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Error,
            'Message with <tags> & "quotes"',
            null,
            null,
            null,
            null,
        );

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('Message with &lt;tags&gt; &amp; &quot;quotes&quot;', $output);
    }

    public function test_format_problem_title_with_code(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Error,
            'Test message',
            'regex.lint.test',
            null,
            null,
            null,
        );

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('name="Lint (regex.lint.test)"', $output);
    }

    public function test_format_problem_title_without_code(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Security,
            LintSeverity::Error,
            'Test message',
            null,
            null,
            null,
            null,
        );

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
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

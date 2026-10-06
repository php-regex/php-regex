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
use PHPRegex\Linter\Formatter\CheckstyleFormatter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class CheckstyleFormatterTest extends TestCase
{
    private CheckstyleFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CheckstyleFormatter();
    }

    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        $formatter = new CheckstyleFormatter();
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
        $problem = new Diagnostic(
            DiagnosticType::Syntax,
            LintSeverity::Error,
            'Invalid regex pattern',
            'regex.syntax.error',
            5,
            'some > snippet',
            'Fix the pattern',
        );

        $result = [
            'file' => '/path/to/file.php',
            'line' => 10,
            'column' => 12,
            'source' => 'preg_match',
            'pattern' => '/test/',
            'location' => 'in function call',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<file name="/path/to/file.php">', $output);
        $this->assertStringContainsString('line="10"', $output);
        // The column of the pattern in the file, not the position 5 inside the pattern.
        $this->assertStringContainsString('column="12"', $output);
        $this->assertStringContainsString('severity="error"', $output);
        $this->assertStringContainsString('source="php-regex.regex.syntax.error"', $output);
        $this->assertStringContainsString('Invalid regex pattern', $output);
        $this->assertStringContainsString('Location: in function call', $output);
        $this->assertStringContainsString('some &gt; snippet', $output);
        $this->assertStringContainsString('Suggestion: Fix the pattern', $output);
    }

    public function test_format_with_multiple_problems(): void
    {
        $problem1 = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Warning,
            'Nested quantifier',
            'regex.lint.quantifier.nested',
            null,
            null,
            null,
        );

        $problem2 = new Diagnostic(
            DiagnosticType::Security,
            LintSeverity::Error,
            'ReDoS risk',
            'regex.redos',
            2,
            'vulnerable pattern',
            'Use atomic groups',
        );

        $result1 = [
            'file' => 'file1.php',
            'line' => 5,
            'pattern' => '/test1/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem1],
        ];

        $result2 = [
            'file' => 'file2.php',
            'line' => 15,
            'pattern' => '/test2/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem2],
        ];

        $report = new LintReport([$result1, $result2], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<file name="file1.php">', $output);
        $this->assertStringContainsString('<file name="file2.php">', $output);
        $this->assertStringContainsString('severity="warning"', $output);
        $this->assertStringContainsString('severity="error"', $output);
        $this->assertStringContainsString('php-regex.regex.lint.quantifier.nested', $output);
        $this->assertStringContainsString('php-regex.regex.redos', $output);
    }

    public function test_format_with_different_severities(): void
    {
        $problems = [
            new Diagnostic(DiagnosticType::Lint, LintSeverity::Info, 'Info message', null, null, null, null),
            new Diagnostic(DiagnosticType::Lint, LintSeverity::Warning, 'Warning message', null, null, null, null),
            new Diagnostic(DiagnosticType::Syntax, LintSeverity::Error, 'Error message', null, null, null, null),
            new Diagnostic(DiagnosticType::Security, LintSeverity::Critical, 'Critical message', null, null, null, null),
        ];

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => $problems,
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

        $this->assertStringContainsString('<file name="C:/Windows/test.php">', $output);
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

        $this->assertStringContainsString('line="1"', $output);
    }

    /**
     * The column attribute is optional in Checkstyle: a column the report
     * does not know is left out, never invented.
     */
    public function test_format_normalizes_column_positions(): void
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

        $this->assertStringContainsString('<error line="1" severity="error"', $output);
        $this->assertStringNotContainsString('column=', $output);
    }

    public function test_format_writes_the_column_of_the_pattern_in_the_file(): void
    {
        $problem = new Diagnostic(DiagnosticType::Lint, LintSeverity::Error, 'Test', null, 3, null, null);

        $result = [
            'file' => 'test.php',
            'line' => 4,
            'column' => 17,
            'pattern' => '/(a/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('<error line="4" column="17" severity="error"', $output);
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
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Info,
            'Simple message',
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

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('source="php-regex"', $output);
        $this->assertStringContainsString('Simple message', $output);
    }
}

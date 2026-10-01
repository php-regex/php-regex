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

namespace PhpRegex\Tests\Unit\Lint\Formatter;

use PhpRegex\Linter\Diagnostic;
use PhpRegex\Linter\DiagnosticType;
use PhpRegex\Linter\Formatter\GithubFormatter;
use PhpRegex\Linter\LintReport;
use PhpRegex\Linter\LintSeverity;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class GithubFormatterTest extends TestCase
{
    private GithubFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new GithubFormatter();
    }

    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        $formatter = new GithubFormatter();
    }

    public function test_format_empty_report(): void
    {
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertSame('', $output);
    }

    public function test_format_with_error_problem(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Syntax,
            LintSeverity::Error,
            'Invalid regex pattern',
            'regex.syntax.error',
            5,
            'some snippet',
            'Fix the pattern',
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

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('::error file=/path/to/file.php,line=10,col=5,title=Syntax (regex.syntax.error)::', $output);
        $this->assertStringContainsString('Invalid regex pattern', $output);
        $this->assertStringContainsString('Location: in function call', $output);
        $this->assertStringContainsString('some snippet', $output);
        $this->assertStringContainsString('Suggestion: Fix the pattern', $output);
    }

    public function test_format_with_warning_problem(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Warning,
            'Nested quantifier detected',
            'regex.lint.quantifier.nested',
            null,
            null,
            null,
        );

        $result = [
            'file' => 'file with spaces.php',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('::warning file=file with spaces.php,line=1,col=1,title=Lint (regex.lint.quantifier.nested)::', $output);
        $this->assertStringContainsString('Nested quantifier detected', $output);
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
            'line' => 3,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('::notice file=info.php,line=3,col=1,title=Lint::', $output);
        $this->assertStringContainsString('Info message', $output);
    }

    public function test_format_with_critical_problem(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Security,
            LintSeverity::Critical,
            'Critical security issue',
            'regex.redos',
            2,
            'vulnerable pattern',
            'Use atomic groups',
        );

        $result = [
            'file' => 'critical.php',
            'line' => 8,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('::error file=critical.php,line=8,col=2,title=Security (regex.redos)::', $output);
        $this->assertStringContainsString('Critical security issue', $output);
        $this->assertStringContainsString('vulnerable pattern', $output);
        $this->assertStringContainsString('Suggestion: Use atomic groups', $output);
    }

    public function test_format_with_multiple_problems(): void
    {
        $problem1 = new Diagnostic(DiagnosticType::Syntax, LintSeverity::Error, 'Error 1', null, null, null, null);
        $problem2 = new Diagnostic(DiagnosticType::Lint, LintSeverity::Warning, 'Warning 1', null, null, null, null);

        $result1 = [
            'file' => 'file1.php',
            'line' => 1,
            'pattern' => '/test1/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem1],
        ];

        $result2 = [
            'file' => 'file2.php',
            'line' => 2,
            'pattern' => '/test2/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem2],
        ];

        $report = new LintReport([$result1, $result2], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('::error', $lines[0]);
        $this->assertStringContainsString('::warning', $lines[1]);
    }

    public function test_format_with_empty_file(): void
    {
        $problem = new Diagnostic(DiagnosticType::Lint, LintSeverity::Error, 'Test', null, null, null, null);

        $result = [
            'file' => '',
            'line' => 1,
            'pattern' => '/test/',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('::error title=Lint::', $output);
        $this->assertStringNotContainsString('file=', $output);
        $this->assertStringNotContainsString('line=', $output);
        $this->assertStringNotContainsString('col=', $output);
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

        $this->assertStringContainsString('title=Lint (regex.lint.test)', $output);
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

        $this->assertStringContainsString('title=Security', $output);
    }

    public function test_format_escapes_properties(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Lint,
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

        $this->assertStringContainsString('file=test.php', $output);
    }

    public function test_format_escapes_data(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Error,
            "Message with\nnewlines and\r\ncrlf",
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

        $this->assertStringContainsString('Message with%0Anewlines and%0D%0Acrlf', $output);
    }

    public function test_format_escapes_title(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Error,
            'Test message',
            'code with % and \n',
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

        $this->assertStringContainsString('title=Lint (code with %25 and \n)', $output);
    }

    public function test_format_error(): void
    {
        $message = 'Test error message';

        $output = $this->formatter->formatError($message);

        $this->assertSame('::error::Test error message', $output);
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

        $this->assertStringContainsString('::notice file=test.php,line=1,col=1,title=Lint::Simple message', $output);
    }

    public function test_format_with_location_and_snippet(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Warning,
            'Warning message',
            null,
            null,
            'code snippet here',
            'fix suggestion',
        );

        $result = [
            'file' => 'test.php',
            'line' => 5,
            'pattern' => '/test/',
            'location' => 'inside preg_match call',
            'issues' => [],
            'optimizations' => [],
            'problems' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $expected = '::warning file=test.php,line=5,col=1,title=Lint::Warning message%0ALocation: inside preg_match call%0Acode snippet here%0ASuggestion: fix suggestion';
        $this->assertStringContainsString($expected, $output);
    }
}

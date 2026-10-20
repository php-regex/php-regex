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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\ConsoleLayout;
use PHPRegex\Linter\Formatter\ConsoleTagFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\Formatter\TagMarkup;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class ConsoleTagFormatterReportTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(OutputFormatter::class)) {
            $this->markTestSkipped('Symfony Console component is not available');
        }

        if (!class_exists(LinkFormatter::class)) {
            $this->markTestSkipped('LinkFormatter is not available');
        }
    }

    public function test_format_empty_report(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('PASS', $output);
        $this->assertStringContainsString('No issues found', $output);
    }

    public function test_format_error(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result = 'Test error message';

        $output = $formatter->formatError($result);

        $this->assertSame($result, $output);
    }

    public function test_format_with_error_issues(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Invalid regex pattern', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('test.php', $output);
        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringContainsString('Invalid regex pattern', $output);
    }

    public function test_format_with_warning_issues(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'issues' => [
                new LintIssue(LintSeverity::Warning, 'Complex pattern detected', 'test.php', 10, hint: 'Consider simplifying'),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('WARN', $output);
        $this->assertStringContainsString('Complex pattern detected', $output);
        $this->assertStringContainsString('Consider simplifying', $output);
    }

    public function test_format_with_info_issues(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Invalid regex pattern', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('FAIL', $output);
    }

    public function test_format_with_optimizations(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter, false);

        $optimization = new OptimizationResult('original', 'optimized');
        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => 'original',
            'issues' => [],
            'optimizations' => [
                [
                    'file' => 'test.php',
                    'line' => 10,
                    'optimization' => $optimization,
                    'savings' => 5,
                ],
            ],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('TIP', $output);
        $this->assertStringContainsString('original', $output);
        $this->assertStringContainsString('optimized', $output);
        // Black on cyan, as WARN is black on yellow.
        $this->assertStringContainsString('<bg=cyan;fg=black;options=bold> TIP </>', $output);
    }

    public function test_format_with_issue_suggested_pattern_tip(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter, false);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/(a+)+/',
            'issues' => [
                new LintIssue(LintSeverity::Warning, 'Nested quantifiers can cause catastrophic backtracking.', 'test.php', 10, issueId: 'regex.lint.quantifier.nested', hint: 'Consider using atomic groups (?>...) or possessive quantifiers.'),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        // No producer writes issue suggestions any more: the rewrite was
        // unsound, so an issue never renders a TIP diff.
        $this->assertStringNotContainsString('TIP', $output);
        $this->assertStringNotContainsString('- /(a+)+/', $output);
        $this->assertStringNotContainsString('+ /(?\>(a+))+/', $output);
    }

    public function test_format_with_location(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result = [
            'file' => 'test.php',
            'line' => 0,
            'pattern' => '/test/',
            'location' => 'in function call',
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Invalid regex pattern', 'test.php', 0),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('in function call', $output);
    }

    public function test_format_with_multiple_files(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result1 = [
            'file' => 'file1.php',
            'line' => 10,
            'pattern' => '/test1/',
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Error in file1', 'file1.php', 10),
            ],
            'optimizations' => [],
        ];

        $result2 = [
            'file' => 'file2.php',
            'line' => 20,
            'pattern' => '/test2/',
            'issues' => [
                new LintIssue(LintSeverity::Warning, 'Warning in file2', 'file2.php', 20),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result1, $result2], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('file1.php', $output);
        $this->assertStringContainsString('file2.php', $output);
        $this->assertStringContainsString('Error in file1', $output);
        $this->assertStringContainsString('Warning in file2', $output);
    }

    public function test_format_with_multiline_message(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'issues' => [
                new LintIssue(LintSeverity::Info, 'Pattern information', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('INFO', $output);
    }

    public function test_format_with_no_pattern(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result = [
            'file' => 'test.php',
            'line' => 0,
            'location' => 'in function call',
            'pattern' => null,
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Invalid regex pattern', 'test.php', 0),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('./test.php', $output);
        $this->assertStringContainsString('in function call', $output);
    }

    public function test_format_with_decorated_false(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter, false);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Invalid regex pattern', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        // Should not contain ANSI escape codes when decorated is false
        $this->assertStringNotContainsString("\033[", $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    public function test_format_summary_with_errors(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $report = new LintReport([], ['errors' => 2, 'warnings' => 1, 'optimizations' => 3]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('2 invalid patterns', $output);
        $this->assertStringContainsString('1 warnings, 3 optimizations', $output);
    }

    public function test_format_summary_with_warnings_only(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $report = new LintReport([], ['errors' => 0, 'warnings' => 2, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('2 warnings found', $output);
        $this->assertStringContainsString('1 optimizations available', $output);
    }

    public function test_format_summary_with_optimizations_only(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper();
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);

        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 3]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('No issues found', $output);
        $this->assertStringContainsString('3 optimizations available', $output);
    }

    public function test_display_pattern_context_shows_line_when_pattern_missing(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper('/app');
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);
        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $result = [
            'file' => '/app/src/File.php',
            'line' => 12,
            'pattern' => null,
            'issues' => [],
            'optimizations' => [],
        ];

        $output = $this->invokePrivate($formatter, 'displayPatternContext', [$result]);

        $this->assertIsString($output);
        $this->assertStringContainsString('./src/File.php:12', (string) $output);
    }

    public function test_safely_highlight_pattern_skips_highlight_when_backslash_present(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper('/app');
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);
        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $output = $this->invokePrivate($formatter, 'safelyHighlightPattern', ['/\d+/']);

        $this->assertIsString($output);
        $this->assertStringContainsString('\\d', (string) $output);
    }

    public function test_display_optimizations_skips_invalid_entries(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $relativePathHelper = new RelativePathHelper('/app');
        $linkFormatter = new LinkFormatter(null, $relativePathHelper);
        $formatter = new ConsoleTagFormatter($analysis, $linkFormatter);

        $output = $this->invokePrivate($formatter, 'displayOptimizations', [[
            ['optimization' => 'noop'],
        ]]);

        $this->assertIsString($output);
        $this->assertStringContainsString('TIP', (string) $output);
    }

    public function test_display_single_issue_handles_multiline_messages(): void
    {
        $badge = '<bg=gray;fg=white;options=bold> INFO </>';
        $message = "Top line\nLine 12: details\nMore";

        $output = (new ConsoleLayout(new TagMarkup()))->issue($badge, $message);

        $this->assertIsString($output);
        $this->assertStringContainsString('details', (string) $output);
        $this->assertStringContainsString('More', (string) $output);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($target);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invokeArgs($target, $args);
    }
}

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
use PHPRegex\Linter\Formatter\AnsiMarkup;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\LineDiff;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\TextPreservingHighlighter;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleFormatterTest extends TestCase
{
    private ConsoleFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ConsoleFormatter();
    }

    #[Test]
    public function test_format_empty_report(): void
    {
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertSame('', $output);
    }

    #[Test]
    public function test_format_quiet_verbosity(): void
    {
        $config = new OutputConfiguration(verbosity: OutputConfiguration::VERBOSITY_QUIET);
        $formatter = new ConsoleFormatter(config: $config);

        $report = new LintReport([], ['errors' => 1, 'warnings' => 2, 'optimizations' => 3]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('FAIL: 1 invalid patterns, 2 warnings, 3 optimizations.', $output);
    }

    #[Test]
    public function test_format_quiet_no_errors(): void
    {
        $config = new OutputConfiguration(verbosity: OutputConfiguration::VERBOSITY_QUIET);
        $formatter = new ConsoleFormatter(config: $config);

        $report = new LintReport([], ['errors' => 0, 'warnings' => 2, 'optimizations' => 3]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('WARN: 2 warnings found, 3 optimizations available.', $output);
    }

    #[Test]
    public function test_format_quiet_no_issues(): void
    {
        $config = new OutputConfiguration(verbosity: OutputConfiguration::VERBOSITY_QUIET);
        $formatter = new ConsoleFormatter(config: $config);

        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 3]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('PASS: No issues found, 3 optimizations available.', $output);
    }

    #[Test]
    public function test_get_summary(): void
    {
        $stats = ['errors' => 1, 'warnings' => 2, 'optimizations' => 3];

        $output = $this->formatter->getSummary($stats);

        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringContainsString('1 invalid patterns', $output);
    }

    #[Test]
    public function test_get_summary_warnings_only(): void
    {
        $stats = ['errors' => 0, 'warnings' => 2, 'optimizations' => 3];

        $output = $this->formatter->getSummary($stats);

        $this->assertStringContainsString('PASS', $output);
        $this->assertStringContainsString('2 warnings found', $output);
    }

    #[Test]
    public function test_get_summary_no_issues(): void
    {
        $stats = ['errors' => 0, 'warnings' => 0, 'optimizations' => 3];

        $output = $this->formatter->getSummary($stats);

        $this->assertStringContainsString('PASS', $output);
        $this->assertStringContainsString('No issues found', $output);
    }

    #[Test]
    public function test_format_footer(): void
    {
        $output = $this->formatter->formatFooter();

        $this->assertStringContainsString('If PHPRegex helps, a GitHub star is appreciated:', $output);
        $this->assertStringContainsString('github.com/php-regex/php-regex', $output);
    }

    #[Test]
    public function test_format_with_error_issue(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

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

        $this->assertStringContainsString('test.php:10', $output);
        $this->assertStringContainsString('/test/', $output);
        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringContainsString('Invalid regex pattern', $output);
    }

    #[Test]
    public function test_format_with_warning_issue(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

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

    #[Test]
    public function test_format_with_line_only_context(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $result = [
            'file' => 'test.php',
            'line' => 42,
            'pattern' => null,
            'issues' => [],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('42', $output);
        $this->assertStringNotContainsString('pattern unavailable', $output);
    }

    /**
     * The file field is one line: a line feed, a carriage return or a tab
     * in a file name is spelled "\xHH", so that a name cannot draw a line
     * of a report of its own.
     */
    #[Test]
    #[DataProviderExternal(ConsoleTagFormatterTest::class, 'provideFileFields')]
    public function test_format_shows_the_file_field_on_one_line(string $file, int $line, string $expected): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false));
        $report = new LintReport([[
            'file' => $file,
            'line' => $line,
            'pattern' => '/a/',
            'issues' => [new LintIssue(LintSeverity::Warning, 'm', $file, $line)],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString($file, $output);
    }

    /**
     * The location field is one line too; a message keeps its line
     * breaks, which lay it out.
     */
    #[Test]
    public function test_format_shows_the_location_on_one_line_and_keeps_the_line_breaks_of_a_message(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false));
        $report = new LintReport([[
            'file' => 'src/Foo.php',
            'line' => 1,
            'pattern' => '/a/',
            'location' => "route a\nb\tc\r\nd",
            'issues' => [new LintIssue(LintSeverity::Warning, "first\nsecond", 'src/Foo.php', 1)],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('route a\x0Ab\x09c\x0D\x0Ad', $output);
        $this->assertStringNotContainsString("route a\n", $output);
        $this->assertMatchesRegularExpression("/first\n[^\n]*second/", $output);
        $this->assertStringNotContainsString('first\x0A', $output);
    }

    #[Test]
    public function test_format_skips_invalid_optimization_entries(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $output = $this->invokePrivate($formatter, 'formatOptimizations', [
            ['optimization' => 'invalid'],
        ]);

        $this->assertIsString($output);
        $this->assertStringNotContainsString('- /', (string) $output);
    }

    #[Test]
    public function test_format_with_optimization(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $optimization = new OptimizationResult('/a+/', '/a++/', ['Made possessive']);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/a+/',
            'issues' => [],
            'optimizations' => [
                [
                    'file' => 'test.php',
                    'line' => 10,
                    'optimization' => $optimization,
                    'savings' => 1,
                ],
            ],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('TIP', $output);
        $this->assertStringContainsString('- /a+/', $output);
        $this->assertStringContainsString('+ /a++/', $output);
    }

    #[Test]
    public function test_format_writes_the_tip_badge_black_on_cyan_as_warn_is_black_on_yellow(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: true));
        $optimization = new OptimizationResult('/a+/', '/a++/', ['Made possessive']);
        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/a+/',
            'issues' => [],
            'optimizations' => [['file' => 'test.php', 'line' => 10, 'optimization' => $optimization, 'savings' => 1]],
        ];

        $output = $formatter->format(new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]));

        // Background, then foreground: cyan (46) behind black (30), bold.
        $this->assertStringContainsString("\033[46m\033[30m\033[1m TIP ", $output);
    }

    #[Test]
    public function test_format_with_issue_suggested_pattern_tip(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

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
        $this->assertStringNotContainsString('+ /(?>(a+))+/', $output);
    }

    #[Test]
    public function test_format_with_multiline_optimization_diff(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $original = "/a\n b/x";
        $optimized = "/a\n c/x";
        $optimization = new OptimizationResult($original, $optimized, ['Updated literal']);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => $original,
            'issues' => [],
            'optimizations' => [
                [
                    'file' => 'test.php',
                    'line' => 10,
                    'optimization' => $optimization,
                    'savings' => 1,
                ],
            ],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('+', $output);
        $this->assertStringContainsString('-', $output);
    }

    #[Test]
    public function test_format_without_ansi(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Invalid pattern', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('test.php:10', $output);
        $this->assertStringContainsString('/test/', $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    #[Test]
    public function test_format_with_location(): void
    {
        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'location' => 'in function preg_match',
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Invalid pattern', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('↳ in function preg_match', $output);
    }

    #[Test]
    public function test_format_extended_optimization(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $original = "/(?x)\n# This is a comment\n[a-z]+\n# Another comment\n/";
        $optimized = '/(?x)[a-z]++/';

        $optimization = new OptimizationResult($original, $optimized, ['Removed comments']);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => $original,
            'issues' => [],
            'optimizations' => [
                [
                    'file' => 'test.php',
                    'line' => 10,
                    'optimization' => $optimization,
                    'savings' => 10,
                ],
            ],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('TIP', $output);
        $this->assertStringContainsString('- /(?x)', $output);
        $this->assertStringContainsString('+ /(?x)[a-z]++/', $output);
    }

    #[Test]
    public function test_format_multiline_diff_with_changes(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        // Extended mode pattern with multiple lines that have actual changes
        $original = "/(?x)\nfoo\nbar\nbaz\n/";
        $optimized = "/(?x)\nfoo\nqux\nbaz\n/";

        $optimization = new OptimizationResult($original, $optimized, ['Changed bar to qux']);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => $original,
            'issues' => [],
            'optimizations' => [
                [
                    'file' => 'test.php',
                    'line' => 10,
                    'optimization' => $optimization,
                    'savings' => 0,
                ],
            ],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('TIP', $output);
        $this->assertStringContainsString('foo', $output);
    }

    #[Test]
    public function test_format_multiline_diff_lays_out_the_tabs_x_skips(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false));

        $output = $formatter->format(new LintReport([[
            'file' => 'test.php',
            'line' => 10,
            'pattern' => "/\n\tfoo{1}\t# one\n\t[\t]\n/x",
            'issues' => [],
            'optimizations' => [[
                'file' => 'test.php',
                'line' => 10,
                'optimization' => new OptimizationResult("/\n\tfoo{1}\t# one\n\t[\t]\n/x", "/\n\tfoo\t# one\n\t[\t]\n/x", ['Optimized pattern.']),
                'savings' => 3,
            ]],
        ]], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]));

        $this->assertStringContainsString('-     foo{1}  # one', $output);
        $this->assertStringContainsString('+     foo # one', $output);
        // In a class, x keeps the tab: it is a character, spelled.
        $this->assertStringContainsString('      [\t]', $output);
        $this->assertStringNotContainsString("\t", $output);
    }

    #[Test]
    public function test_format_multiline_diff_with_ellipsis(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        // Extended mode pattern with many lines to trigger ellipsis
        $original = "/(?x)\nline1\nline2\nline3\nline4\nline5\nline6\nline7\nline8\nline9\nline10\nchanged\nline12\nline13\nline14\nline15\n/";
        $optimized = "/(?x)\nline1\nline2\nline3\nline4\nline5\nline6\nline7\nline8\nline9\nline10\nmodified\nline12\nline13\nline14\nline15\n/";

        $optimization = new OptimizationResult($original, $optimized, ['Changed line']);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => $original,
            'issues' => [],
            'optimizations' => [
                [
                    'file' => 'test.php',
                    'line' => 10,
                    'optimization' => $optimization,
                    'savings' => 0,
                ],
            ],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('TIP', $output);
    }

    #[Test]
    public function test_format_with_file_only_context(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $result = [
            'file' => 'only.php',
            'line' => 0,
            'pattern' => null,
            'issues' => [],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);
        $output = $formatter->format($report);

        $this->assertStringContainsString('only.php', $output);
    }

    #[Test]
    public function test_format_with_pattern_unavailable_label(): void
    {
        $result = [
            'file' => '',
            'line' => 0,
            'pattern' => null,
            'issues' => [],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);
        $output = $this->formatter->format($report);

        $this->assertStringContainsString('pattern unavailable', $output);
    }

    #[Test]
    public function test_format_multiline_diff_when_no_changes(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false));
        $text = "/(?x)\nfoo\nbar\n/";

        $output = $this->invokePrivate($formatter, 'formatMultilineDiff', $text, $text);

        $this->assertIsString($output);
        $this->assertStringContainsString('foo', (string) $output);
    }

    #[Test]
    public function test_diff_lines_handles_insert_when_old_empty(): void
    {
        $ops = LineDiff::lines([], ['added']);

        $this->assertIsArray($ops);
        $this->assertArrayHasKey(0, $ops);
        /** @var array{type: string} $first */
        $first = $ops[0];
        $this->assertSame('insert', $first['type']);
    }

    #[Test]
    public function test_format_diff_change_block_handles_mismatched_counts(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false));
        $block = [
            ['type' => 'delete', 'line' => 'old-1'],
            ['type' => 'delete', 'line' => 'old-2'],
            ['type' => 'insert', 'line' => 'new-1'],
        ];

        $output = $this->invokePrivate($formatter, 'formatDiffChangeBlock', $block);

        $this->assertIsString($output);
        $this->assertStringContainsString('old-2', (string) $output);

        $block = [
            ['type' => 'delete', 'line' => 'old'],
            ['type' => 'insert', 'line' => 'new-1'],
            ['type' => 'insert', 'line' => 'new-2'],
        ];

        $output = $this->invokePrivate($formatter, 'formatDiffChangeBlock', $block);
        $this->assertIsString($output);
        $this->assertStringContainsString('new-2', (string) $output);
    }

    #[Test]
    public function test_is_extended_mode_pattern_handles_empty_and_invalid(): void
    {
        $formatter = new ConsoleFormatter();

        $this->assertFalse($this->invokePrivate($formatter, 'isExtendedModePattern', ''));
        $this->assertFalse($this->invokePrivate($formatter, 'isExtendedModePattern', '/'));
    }

    #[Test]
    public function test_format_pattern_for_display_falls_back_on_invalid_pattern(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: true));

        $output = $this->invokePrivate($formatter, 'formatPatternForDisplay', 'abc');

        $this->assertIsString($output);
        $this->assertSame('abc', $output);
    }

    #[Test]
    public function test_format_pattern_for_display_falls_back_when_highlighter_fails(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $formatter = new ConsoleFormatter($analysis, new OutputConfiguration(ansi: true));

        $output = $this->invokePrivate($formatter, 'formatPatternForDisplay', '/[/');

        $this->assertIsString($output);
        $this->assertStringContainsString('[', (string) $output);
    }

    #[Test]
    public function test_highlight_pattern_body_preserving_text_branches(): void
    {
        $highlighter = new TextPreservingHighlighter(new AnsiMarkup(true));

        $this->assertSame('', $highlighter->highlight(''));
        $this->assertSame('plain', $highlighter->highlight('plain'));

        $body = '([a]+){2,3}^$';
        $output = $highlighter->highlight($body);
        $this->assertIsString($output);
        $this->assertStringContainsString('^', $output);

        $output = $highlighter->highlight('a{2}');
        $this->assertIsString($output);
        $this->assertStringContainsString('{', $output);

        $output = $highlighter->highlight('a{b');
        $this->assertIsString($output);
        $this->assertStringContainsString('{', $output);

        $output = $highlighter->highlight('\\');
        $this->assertIsString($output);
        $this->assertStringContainsString('\\', $output);
    }

    #[Test]
    public function test_split_pattern_with_flags_handles_pairs_and_invalid(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false));

        $this->assertNull($this->invokePrivate($formatter, 'splitPatternWithFlags', ''));
        $this->assertNull($this->invokePrivate($formatter, 'splitPatternWithFlags', '/foo'));

        $parts = $this->invokePrivate($formatter, 'splitPatternWithFlags', '[foo]i');
        $this->assertIsArray($parts);
        $this->assertArrayHasKey('closingDelimiter', $parts);
        $this->assertSame(']', $parts['closingDelimiter']);

        $parts = $this->invokePrivate($formatter, 'splitPatternWithFlags', '(foo)i');
        $this->assertIsArray($parts);
        $this->assertArrayHasKey('closingDelimiter', $parts);
        $this->assertSame(')', $parts['closingDelimiter']);

        $parts = $this->invokePrivate($formatter, 'splitPatternWithFlags', '<foo>i');
        $this->assertIsArray($parts);
        $this->assertArrayHasKey('closingDelimiter', $parts);
        $this->assertSame('>', $parts['closingDelimiter']);
    }

    #[Test]
    public function test_format_multiline_diff_includes_ellipsis_for_distant_changes(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false));
        $old = "/(?x)\nline1\nline2\nline3\nline4\nline5\nline6\nline7\nline8\nline9\nline10\n/";
        $new = "/(?x)\nline1\nline2\nline3\nline4\nline5\nline6\nline7\nline8\nline9\nchanged\n/";

        $output = $this->invokePrivate($formatter, 'formatMultilineDiff', $old, $new);

        // The diff hides nothing: distant or not, every line stays visible
        // (the omission-marker assertions this test used to carry went away
        // with the elision, on the maintainer's instruction).
        $this->assertIsString($output);
        $this->assertStringContainsString('line1', (string) $output);
        $this->assertStringContainsString('line5', (string) $output);
        $this->assertStringContainsString('changed', (string) $output);
        $this->assertStringNotContainsString('omitted', (string) $output);
    }

    #[Test]
    public function test_extract_pattern_from_optimizations(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $optimization = new OptimizationResult('/original/', '/optimized/', ['Optimized']);

        // Result without direct pattern, but with optimization
        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '', // Empty pattern to trigger fallback
            'issues' => [],
            'optimizations' => [
                [
                    'file' => 'test.php',
                    'line' => 10,
                    'optimization' => $optimization,
                    'savings' => 1,
                ],
            ],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('/original/', $output);
    }

    #[Test]
    public function test_multiline_message_strips_line_prefix(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'issues' => [
                new LintIssue(LintSeverity::Error, "Error occurred\nLine 5: specific issue\nLine 10: another issue", 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('specific issue', $output);
        $this->assertStringContainsString('another issue', $output);
    }

    #[Test]
    public function test_format_with_ansi_highlighting(): void
    {
        $config = new OutputConfiguration(ansi: true);
        $formatter = new ConsoleFormatter(config: $config);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/[a-z]+\d{2,4}/',
            'issues' => [
                new LintIssue(LintSeverity::Warning, 'Pattern could be optimized', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        // Should contain ANSI escape codes
        $this->assertStringContainsString("\e[", $output);
        // Strip ANSI codes and check for pattern content
        $stripped = preg_replace('/\x1B\\[[0-9;]*m/', '', $output);
        $this->assertStringContainsString('[a-z]', (string) $stripped);
    }

    #[Test]
    public function test_format_info_issue(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '/test/',
            'issues' => [
                new LintIssue(LintSeverity::Info, 'Information message', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('INFO', $output);
    }

    #[Test]
    public function test_format_pattern_with_paired_delimiters(): void
    {
        $config = new OutputConfiguration(ansi: true);
        $formatter = new ConsoleFormatter(config: $config);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => '{test}i',
            'issues' => [
                new LintIssue(LintSeverity::Warning, 'Warning', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('test', $output);
    }

    #[Test]
    public function test_format_pattern_with_invalid_delimiter(): void
    {
        $config = new OutputConfiguration(ansi: true);
        $formatter = new ConsoleFormatter(config: $config);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => 'invalid pattern without delimiters',
            'issues' => [
                new LintIssue(LintSeverity::Error, 'Invalid pattern', 'test.php', 10),
            ],
            'optimizations' => [],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('invalid pattern without delimiters', $output);
    }

    #[Test]
    public function test_format_diff_with_identical_patterns(): void
    {
        $config = new OutputConfiguration(ansi: false);
        $formatter = new ConsoleFormatter(config: $config);

        // Extended mode with identical content (no changes)
        $original = "/(?x)\nfoo\nbar\n/";
        $optimized = "/(?x)\nfoo\nbar\n/";

        $optimization = new OptimizationResult($original, $optimized, ['No actual change']);

        $result = [
            'file' => 'test.php',
            'line' => 10,
            'pattern' => $original,
            'issues' => [],
            'optimizations' => [
                [
                    'file' => 'test.php',
                    'line' => 10,
                    'optimization' => $optimization,
                    'savings' => 0,
                ],
            ],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('TIP', $output);
    }

    #[Test]
    public function test_format_multiline_diff_returns_empty_when_no_ops(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false));

        $output = $this->invokePrivate($formatter, 'formatMultilineDiff', '', '');

        $this->assertIsString($output);
        $this->assertSame('', $output);
    }

    #[Test]
    public function test_format_pattern_for_display_falls_back_when_highlighter_missing(): void
    {
        $formatter = new ConsoleFormatter(null, new OutputConfiguration(ansi: true));

        $output = $this->invokePrivate($formatter, 'formatPatternForDisplay', '/foo/');

        $this->assertIsString($output);
        $this->assertStringContainsString('foo', (string) $output);
    }

    #[Test]
    public function test_format_pattern_for_display_preserves_comment_text_when_highlight_changes(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $formatter = new ConsoleFormatter($analysis, new OutputConfiguration(ansi: true));

        $output = $this->invokePrivate($formatter, 'formatPatternForDisplay', '/(?#comment)foo/');

        $this->assertIsString($output);
        $this->assertStringContainsString('comment', (string) $output);
    }

    private function invokePrivate(ConsoleFormatter $formatter, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($formatter);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invoke($refMethod->isStatic() ? null : $formatter, ...$args);
    }
}

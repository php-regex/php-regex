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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\LintReport;
use PHPRegex\Optimizer\OptimizationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleFormatterTest extends TestCase
{
    public function test_console_formatter_outputs_pattern_context(): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            ansi: false,
            showProgress: false,
            showOptimizations: false,
            showHints: false,
        );
        $formatter = new ConsoleFormatter(null, $config);

        $report = new LintReport(
            results: [[
                'file' => './test.php',
                'line' => 10,
                'pattern' => '/a+/',
                'issues' => [],
                'optimizations' => [],
                'problems' => [],
            ]],
            stats: ['errors' => 0, 'warnings' => 0, 'optimizations' => 0],
        );

        $output = $formatter->format($report);

        $this->assertStringContainsString('./test.php:10', $output);
        $this->assertStringContainsString('/a+/', $output);
    }

    public function test_console_formatter_outputs_quiet_summary(): void
    {
        $config = OutputConfiguration::quiet();
        $formatter = new ConsoleFormatter(null, $config);

        $report = new LintReport(
            results: [],
            stats: ['errors' => 0, 'warnings' => 0, 'optimizations' => 0],
        );

        $output = $formatter->format($report);

        $this->assertStringContainsString('PASS: No issues found', $output);
    }

    public function test_console_formatter_footer_includes_repo_link(): void
    {
        $formatter = new ConsoleFormatter();

        $footer = $formatter->formatFooter();

        $this->assertStringContainsString('https://github.com/php-regex/php-regex', $footer);
    }

    /**
     * The omission marker must state the exact number of hidden lines: a
     * bare ellipsis invites pasting a truncated rewrite.
     *
     * @return iterable<string, array{count: int, visibleBeforeOmission: array<int, string>, letters: array<int, string>, modified: array<string, string>}>
     */
    public static function provideOmissionRuns(): iterable
    {
        yield 'hidden run at the start' => [
            'count' => 9,
            'visibleBeforeOmission' => [],
            'letters' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l'],
            'modified' => ['l' => 'L'],
        ];

        yield 'hidden run at the end' => [
            'count' => 9,
            'visibleBeforeOmission' => ['aline', 'bline', 'cline'],
            'letters' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l'],
            'modified' => ['a' => 'A'],
        ];

        yield 'single hidden run between two hunks' => [
            'count' => 6,
            'visibleBeforeOmission' => [],
            'letters' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l'],
            'modified' => ['a' => 'A', 'l' => 'L'],
        ];

        yield 'a single omitted line reads in the singular' => [
            'count' => 1,
            'visibleBeforeOmission' => [],
            'letters' => ['a', 'b', 'c', 'd'],
            'modified' => ['a' => 'A'],
        ];
    }

    /**
     * @param array<int, string>    $visibleBeforeOmission
     * @param array<int, string>    $letters
     * @param array<string, string> $modified
     */
    #[DataProvider('provideOmissionRuns')]
    public function test_multiline_tip_diff_counts_omitted_lines(int $count, array $visibleBeforeOmission, array $letters, array $modified): void
    {
        $config = new OutputConfiguration(
            verbosity: OutputConfiguration::VERBOSITY_NORMAL,
            ansi: false,
            showProgress: false,
            showOptimizations: true,
            showHints: false,
        );
        $formatter = new ConsoleFormatter(null, $config);

        $lines = [];
        foreach ($letters as $index => $letter) {
            $lines[] = ($modified[$letter] ?? $letter).'line';
        }
        $originalLines = [];
        foreach ($letters as $letter) {
            $originalLines[] = $letter.'line';
        }
        $original = '/'.implode("\n", $originalLines).'/x';
        $optimized = '/'.implode("\n", $lines).'/x';

        $report = new LintReport(
            results: [[
                'file' => './test.php',
                'line' => 10,
                'pattern' => $original,
                'issues' => [[
                    'type' => 'warning',
                    'file' => './test.php',
                    'line' => 10,
                    'message' => 'Anything.',
                    'issueId' => 'regex.lint.example',
                ]],
                'optimizations' => [[
                    'file' => './test.php',
                    'line' => 10,
                    'column' => 1,
                    'fileOffset' => null,
                    'optimization' => new OptimizationResult($original, $optimized, ['Optimized pattern.']),
                    'savings' => 1,
                    'source' => 'optimizer',
                ]],
                'problems' => [],
            ]],
            stats: ['errors' => 0, 'warnings' => 1, 'optimizations' => 1],
        );

        $output = $formatter->format($report);

        $this->assertStringContainsString(1 === $count ? 'line omitted' : 'lines omitted', $output);
        $this->assertMatchesRegularExpression('/[0-9]+ lines? omitted/', $output);
        $this->assertStringContainsString(
            sprintf('... %d line%s omitted ...', $count, 1 === $count ? '' : 's'),
            $output,
        );
        $this->assertSame(1, substr_count($output, 'omitted'), $output);
        $this->assertStringContainsString('Anything.', $output);
        foreach ($visibleBeforeOmission as $visible) {
            $this->assertIsString($visible);
            $this->assertStringContainsString($visible, $output);
        }
        $this->assertStringContainsString('TIP', $output);
        $this->assertStringNotContainsString("\n         ...\n", $output);
    }
}

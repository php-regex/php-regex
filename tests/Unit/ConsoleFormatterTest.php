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
     * A multiline tip diff hides nothing: every line of the pattern stays
     * visible, so the suggestion can be read and applied whole. The
     * omission-marker assertions this provider used to carry were removed
     * with the elision itself, on the maintainer's instruction.
     *
     * @return iterable<string, array{letters: array<int, string>, modified: array<string, string>}>
     */
    public static function provideOmissionRuns(): iterable
    {
        yield 'change at the end' => [
            'letters' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l'],
            'modified' => ['l' => 'L'],
        ];

        yield 'change at the start' => [
            'letters' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l'],
            'modified' => ['a' => 'A'],
        ];

        yield 'changes at both ends' => [
            'letters' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l'],
            'modified' => ['a' => 'A', 'l' => 'L'],
        ];

        yield 'short pattern' => [
            'letters' => ['a', 'b', 'c', 'd'],
            'modified' => ['a' => 'A'],
        ];
    }

    /**
     * @param array<int, string>    $letters
     * @param array<string, string> $modified
     */
    #[DataProvider('provideOmissionRuns')]
    public function test_multiline_tip_diff_shows_every_line(array $letters, array $modified): void
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
        foreach ($letters as $letter) {
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

        // Every line of the original stays visible; nothing is omitted.
        foreach ($originalLines as $visible) {
            $this->assertStringContainsString($visible, $output);
        }
        $this->assertStringNotContainsString('omitted', $output);
        $this->assertStringContainsString('Anything.', $output);
        $this->assertStringContainsString($originalLines[0], $output);
        $this->assertSame(1, substr_count($output, 'TIP'), $output);
        $this->assertStringContainsString('TIP', $output);
        $this->assertStringNotContainsString("\n         ...\n", $output);
    }
}

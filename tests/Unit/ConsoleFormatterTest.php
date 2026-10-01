<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit;

use PhpRegex\Linter\Formatter\ConsoleFormatter;
use PhpRegex\Linter\Formatter\OutputConfiguration;
use PhpRegex\Linter\LintReport;
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

        $this->assertStringContainsString('https://github.com/php-regex/regex-parser', $footer);
    }
}

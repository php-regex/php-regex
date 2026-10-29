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

use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\ConsoleTagFormatter;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\LintSummary;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The summary line of every console report, byte for byte: the blank line
 * before it, the badge and its colours, the counts, the line end. A failing
 * run is red, a run with warnings yellow, any other run green.
 */
final class SummaryLineRenderingTest extends TestCase
{
    /**
     * @return iterable<string, array{stats: array{errors: int, warnings: int, optimizations: int, redos?: int, infos?: int, lintErrors?: int, notAnalyzed?: int}, plain: string, ansi: string, quiet: string, tags: string}>
     */
    public static function provideSummaries(): iterable
    {
        yield 'lint error' => [
            'stats' => ['errors' => 1, 'warnings' => 0, 'optimizations' => 0, 'lintErrors' => 1],
            'plain' => "\n  FAIL 1 lint errors, 0 warnings, 0 optimizations.\n",
            'ansi' => "\n  \e[41m\e[37m\e[1m FAIL \e[0m \e[31m\e[1m1 lint errors\e[0m\e[90m, 0 warnings, 0 optimizations.\e[0m\n",
            'quiet' => "FAIL: 1 lint errors, 0 warnings, 0 optimizations.\n",
            'tags' => "\n  <bg=red;fg=white;options=bold> FAIL </> <fg=red;options=bold>1 lint errors</><fg=gray>, 0 warnings, 0 optimizations.</>\n",
        ];
        yield 'warnings' => [
            'stats' => ['errors' => 0, 'warnings' => 2, 'optimizations' => 1],
            'plain' => "\n  PASS 2 warnings found, 1 optimizations available.\n",
            'ansi' => "\n  \e[43m\e[30m\e[1m PASS \e[0m \e[33m\e[1m2 warnings found\e[0m\e[90m, 1 optimizations available.\e[0m\n",
            'quiet' => "WARN: 2 warnings found, 1 optimizations available.\n",
            'tags' => "\n  <bg=yellow;fg=black;options=bold> PASS </> <fg=yellow;options=bold>2 warnings found</><fg=gray>, 1 optimizations available.</>\n",
        ];
        yield 'nothing' => [
            'stats' => ['errors' => 0, 'warnings' => 0, 'optimizations' => 3],
            'plain' => "\n  PASS No issues found, 3 optimizations available.\n",
            'ansi' => "\n  \e[42m\e[37m\e[1m PASS \e[0m \e[32m\e[1mNo issues found\e[0m\e[90m, 3 optimizations available.\e[0m\n",
            'quiet' => "PASS: No issues found, 3 optimizations available.\n",
            'tags' => "\n  <bg=green;fg=white;options=bold> PASS </> <fg=green;options=bold>No issues found</><fg=gray>, 3 optimizations available.</>\n",
        ];
        // A pattern the library gave up on is a warning, counted apart
        // inside the warnings: the run has no verdict on it.
        yield 'warnings with patterns not analyzed' => [
            'stats' => ['errors' => 0, 'warnings' => 2, 'optimizations' => 1, 'notAnalyzed' => 1],
            'plain' => "\n  PASS 2 warnings (1 not analyzed) found, 1 optimizations available.\n",
            'ansi' => "\n  \e[43m\e[30m\e[1m PASS \e[0m \e[33m\e[1m2 warnings (1 not analyzed) found\e[0m\e[90m, 1 optimizations available.\e[0m\n",
            'quiet' => "WARN: 2 warnings (1 not analyzed) found, 1 optimizations available.\n",
            'tags' => "\n  <bg=yellow;fg=black;options=bold> PASS </> <fg=yellow;options=bold>2 warnings (1 not analyzed) found</><fg=gray>, 1 optimizations available.</>\n",
        ];
        yield 'errors with patterns not analyzed' => [
            'stats' => ['errors' => 1, 'warnings' => 1, 'optimizations' => 0, 'notAnalyzed' => 1],
            'plain' => "\n  FAIL 1 invalid patterns, 1 warnings (1 not analyzed), 0 optimizations.\n",
            'ansi' => "\n  \e[41m\e[37m\e[1m FAIL \e[0m \e[31m\e[1m1 invalid patterns\e[0m\e[90m, 1 warnings (1 not analyzed), 0 optimizations.\e[0m\n",
            'quiet' => "FAIL: 1 invalid patterns, 1 warnings (1 not analyzed), 0 optimizations.\n",
            'tags' => "\n  <bg=red;fg=white;options=bold> FAIL </> <fg=red;options=bold>1 invalid patterns</><fg=gray>, 1 warnings (1 not analyzed), 0 optimizations.</>\n",
        ];
        yield 'infos and patterns not analyzed' => [
            'stats' => ['errors' => 0, 'warnings' => 1, 'optimizations' => 0, 'infos' => 2, 'notAnalyzed' => 1],
            'plain' => "\n  PASS 1 warnings (1 not analyzed), 2 infos found, 0 optimizations available.\n",
            'ansi' => "\n  \e[43m\e[30m\e[1m PASS \e[0m \e[33m\e[1m1 warnings (1 not analyzed), 2 infos found\e[0m\e[90m, 0 optimizations available.\e[0m\n",
            'quiet' => "WARN: 1 warnings (1 not analyzed), 2 infos found, 0 optimizations available.\n",
            'tags' => "\n  <bg=yellow;fg=black;options=bold> PASS </> <fg=yellow;options=bold>1 warnings (1 not analyzed), 2 infos found</><fg=gray>, 0 optimizations available.</>\n",
        ];
        yield 'infos only' => [
            'stats' => ['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'infos' => 2],
            'plain' => "\n  PASS 0 warnings, 2 infos found, 0 optimizations available.\n",
            'ansi' => "\n  \e[42m\e[37m\e[1m PASS \e[0m \e[32m\e[1m0 warnings, 2 infos found\e[0m\e[90m, 0 optimizations available.\e[0m\n",
            'quiet' => "PASS: 0 warnings, 2 infos found, 0 optimizations available.\n",
            'tags' => "\n  <bg=green;fg=white;options=bold> PASS </> <fg=green;options=bold>0 warnings, 2 infos found</><fg=gray>, 0 optimizations available.</>\n",
        ];
    }

    /**
     * @param array{errors: int, warnings: int, optimizations: int, redos?: int, infos?: int, lintErrors?: int, notAnalyzed?: int} $stats
     */
    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_console_summary_renders_byte_for_byte(array $stats, string $plain, string $ansi, string $quiet, string $tags): void
    {
        $this->assertSame($plain, (new ConsoleFormatter(null, new OutputConfiguration(ansi: false)))->getSummary($stats));
        $this->assertSame($ansi, (new ConsoleFormatter(null, new OutputConfiguration(ansi: true)))->getSummary($stats));
    }

    /**
     * @param array{errors: int, warnings: int, optimizations: int, redos?: int, infos?: int, lintErrors?: int, notAnalyzed?: int} $stats
     */
    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_quiet_report_is_the_summary_line_alone(array $stats, string $plain, string $ansi, string $quiet, string $tags): void
    {
        $this->assertSame($quiet, (new ConsoleFormatter(null, OutputConfiguration::quiet()))->format(new LintReport([], $stats)));
    }

    /**
     * @param array{errors: int, warnings: int, optimizations: int, redos?: int, infos?: int, lintErrors?: int, notAnalyzed?: int} $stats
     */
    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_framework_console_summary_renders_byte_for_byte(array $stats, string $plain, string $ansi, string $quiet, string $tags): void
    {
        $service = new AnalysisService(RegexParser::create(['cache' => null]));
        $links = new LinkFormatter(null, new RelativePathHelper('/project'));

        $this->assertSame($tags, (new ConsoleTagFormatter($service, $links, true))->format(new LintReport([], $stats)));
        $this->assertSame($tags, (new ConsoleTagFormatter($service, $links, true))->format(new LintReport([], $stats)));
    }

    /**
     * The CLI renderer's FAIL line, decorated, then the blank line before
     * its footer, which a verbose run prints.
     */
    #[Test]
    public function test_cli_renderer_fail_line_renders_byte_for_byte(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        ob_start();

        try {
            (new LintOutputRenderer())->renderSummary(new Output(true, false, errorStream: $stream), ['errors' => 1, 'warnings' => 0, 'optimizations' => 0, 'lintErrors' => 1], verbose: true);
        } finally {
            $output = (string) ob_get_clean();
            fclose($stream);
        }

        $this->assertStringStartsWith(
            "\n  \e[41m\e[37m\e[1m FAIL \e[0m \e[31m\e[1m1 lint errors\e[0m\e[90m, 0 warnings, 0 optimizations.\e[0m\n\n",
            $output,
        );
    }

    #[Test]
    public function test_json_report_carries_the_redos_count(): void
    {
        $json = json_decode((new JsonFormatter())->format(new LintReport([], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0, 'redos' => 1])), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        $this->assertSame(['errors' => 1, 'warnings' => 0, 'optimizations' => 0, 'redos_errors' => 1, 'infos' => 0, 'lint_errors' => 0, 'parser_fallbacks' => 0, 'not_analyzed' => 0], $json['stats'] ?? null);
    }

    /**
     * The error label is never empty: with nothing to name apart, the
     * errors are invalid patterns, zero included.
     */
    #[Test]
    public function test_error_label_is_never_empty(): void
    {
        $this->assertSame('0 invalid patterns', LintSummary::errors(['errors' => 0, 'warnings' => 0, 'optimizations' => 0]));
        $this->assertSame('0 invalid patterns', LintSummary::errors(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'redos' => 0, 'lintErrors' => 0]));
    }
}

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
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\LintStatsCounter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\ArgumentCarriers;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The summary line of every console report, byte for byte: the blank line
 * before it, the badge and its colours, the counts, the line end. A failing
 * run is red, a run with warnings yellow, any other run green.
 *
 * After it, one line says how many pattern arguments the run could not
 * verify, when there are some: "Not verifiable: 5 pattern arguments (3
 * dynamic, 2 around preg_quote())".
 *
 * @phpstan-import-type LintStats from LintReport
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

        $this->assertSame(['errors' => 1, 'warnings' => 0, 'optimizations' => 0, 'redos_errors' => 1, 'infos' => 0, 'lint_errors' => 0, 'parser_fallbacks' => 0, 'not_analyzed' => 0], JsonContract::issueCounts($json['stats'] ?? null));
        $this->assertSame(ArgumentCarriers::none(), ArgumentCarriers::countsOf($json['stats'] ?? null));
    }

    /**
     * Each row gives the arguments of a run by state, and the line that
     * says how many of them no rule ran on: null when there is none. The
     * arguments of the three states that hold a part known at run time
     * only are summed; a state that counts none is left out, and the two
     * states that are verified never show.
     *
     * @return iterable<string, array{counts: array<string, int>, line: string|null}>
     */
    public static function provideUnverifiedArguments(): iterable
    {
        yield 'the three figures' => [
            'counts' => ArgumentCarriers::COUNTS,
            'line' => ArgumentCarriers::LINE,
        ];
        yield 'one argument' => [
            'counts' => ['constant' => 2, 'opaque' => 1],
            'line' => 'Not verifiable: 1 pattern argument (1 dynamic)',
        ];
        yield 'one argument around a dynamic part' => [
            'counts' => ['raw_shell' => 1],
            'line' => 'Not verifiable: 1 pattern argument (1 around a dynamic part)',
        ];
        yield 'one argument around preg_quote()' => [
            'counts' => ['quoted_shell' => 1],
            'line' => 'Not verifiable: 1 pattern argument (1 around preg_quote())',
        ];
        yield 'two arguments, one in each of two states' => [
            'counts' => ['quoted_shell' => 1, 'opaque' => 1],
            'line' => 'Not verifiable: 2 pattern arguments (1 dynamic, 1 around preg_quote())',
        ];
        yield 'no dynamic argument' => [
            'counts' => ['quoted_shell' => 2, 'raw_shell' => 3],
            'line' => 'Not verifiable: 5 pattern arguments (3 around a dynamic part, 2 around preg_quote())',
        ];
        yield 'no argument around a dynamic part' => [
            'counts' => ['quoted_shell' => 2, 'opaque' => 3],
            'line' => 'Not verifiable: 5 pattern arguments (3 dynamic, 2 around preg_quote())',
        ];
        yield 'no argument around preg_quote()' => [
            'counts' => ['raw_shell' => 12, 'opaque' => 30],
            'line' => 'Not verifiable: 42 pattern arguments (30 dynamic, 12 around a dynamic part)',
        ];
        yield 'every argument verified' => [
            'counts' => ['constant' => 40, 'finite_set' => 2],
            'line' => null,
        ];
        yield 'no argument' => [
            'counts' => [],
            'line' => null,
        ];
    }

    /**
     * The console summary is written as it was, then says on one line how
     * many arguments were not verified: nothing when there is none.
     *
     * @param array<string, int> $counts
     */
    #[Test]
    #[DataProvider('provideUnverifiedArguments')]
    public function test_console_summary_says_how_many_arguments_were_not_verified(array $counts, ?string $line): void
    {
        foreach ([false, true] as $ansi) {
            $formatter = new ConsoleFormatter(null, new OutputConfiguration(ansi: $ansi));

            self::assertSaidAfterTheSummary($line, $formatter->getSummary(self::stats()), $formatter->getSummary(self::stats($counts)));
        }
    }

    /**
     * The summary of the Symfony and Laravel commands says the same, the
     * same way.
     *
     * @param array<string, int> $counts
     */
    #[Test]
    #[DataProvider('provideUnverifiedArguments')]
    public function test_framework_console_summary_says_how_many_arguments_were_not_verified(array $counts, ?string $line): void
    {
        $service = new AnalysisService(RegexParser::create(['cache' => null]));
        $links = new LinkFormatter(null, new RelativePathHelper('/project'));

        foreach ([false, true] as $decorated) {
            $formatter = new ConsoleTagFormatter($service, $links, $decorated);

            self::assertSaidAfterTheSummary($line, $formatter->format(new LintReport([], self::stats())), $formatter->format(new LintReport([], self::stats($counts))));
        }
    }

    /**
     * The line follows a failing summary and one with warnings as it
     * follows a passing one, and changes nothing of them.
     *
     * @return iterable<string, array{lintErrors: int, warnings: int, summary: string}>
     */
    public static function provideRunsWithIssues(): iterable
    {
        yield 'a failing run' => ['lintErrors' => 1, 'warnings' => 2, 'summary' => "\n  FAIL 1 lint errors, 2 warnings, 0 optimizations.\n"];
        yield 'a run with warnings' => ['lintErrors' => 0, 'warnings' => 2, 'summary' => "\n  PASS 2 warnings found, 0 optimizations available.\n"];
    }

    #[Test]
    #[DataProvider('provideRunsWithIssues')]
    public function test_the_line_follows_the_summary_of_a_run_with_issues(int $lintErrors, int $warnings, string $summary): void
    {
        $formatter = new ConsoleFormatter(null, new OutputConfiguration(ansi: false));
        $without = $formatter->getSummary(self::stats([], $lintErrors, $warnings));
        $this->assertSame($summary, $without);

        self::assertSaidAfterTheSummary(ArgumentCarriers::LINE, $without, $formatter->getSummary(self::stats(ArgumentCarriers::COUNTS, $lintErrors, $warnings)));
    }

    /**
     * Under --quiet the report is its summary line alone, whatever the
     * arguments: nothing is added.
     *
     * @param array<string, int> $counts
     */
    #[Test]
    #[DataProvider('provideUnverifiedArguments')]
    public function test_quiet_report_says_nothing_of_the_arguments(array $counts, ?string $line): void
    {
        $formatter = new ConsoleFormatter(null, OutputConfiguration::quiet());

        $this->assertSame("PASS: No issues found, 0 optimizations available.\n", $formatter->format(new LintReport([], self::stats($counts))));
        $this->assertSame("FAIL: 1 lint errors, 2 warnings, 0 optimizations.\n", $formatter->format(new LintReport([], self::stats($counts, 1, 2))));
    }

    /**
     * The JSON report counts the arguments of each state, the five states
     * always, the verified ones included.
     *
     * @param array<string, int> $counts
     */
    #[Test]
    #[DataProvider('provideUnverifiedArguments')]
    public function test_json_report_carries_the_count_of_each_state(array $counts, ?string $line): void
    {
        $json = json_decode((new JsonFormatter())->format(new LintReport([], self::stats($counts))), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'redos_errors' => 0, 'infos' => 0, 'lint_errors' => 0, 'parser_fallbacks' => 0, 'not_analyzed' => 0], JsonContract::issueCounts($json['stats'] ?? null));
        $this->assertSame([...ArgumentCarriers::none(), ...$counts], ArgumentCarriers::countsOf($json['stats'] ?? null));
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

    /**
     * The stats of a run that anchored these arguments, and reported so
     * many lint errors and warnings.
     *
     * @param array<string, int> $counts
     *
     * @phpstan-return LintStats
     */
    private static function stats(array $counts = [], int $lintErrors = 0, int $warnings = 0): array
    {
        $issues = [];
        for ($i = 0; $i < $lintErrors; $i++) {
            $issues[] = new LintIssue(LintSeverity::Error, 'lint rule', 'src/a.php', 1);
        }
        for ($i = 0; $i < $warnings; $i++) {
            $issues[] = new LintIssue(LintSeverity::Warning, 'warning', 'src/a.php', 1);
        }

        $results = [] === $issues ? [] : [['file' => 'src/a.php', 'line' => 1, 'pattern' => '/a/', 'issues' => $issues, 'optimizations' => []]];

        return LintStatsCounter::count($results, ArgumentCarriers::of($counts));
    }

    /**
     * What a summary writes for a run that anchored arguments, beside what
     * it writes without them: the same bytes first, the summary line
     * included, then the line alone, whatever its indent and its colour;
     * nothing more when there is no line to write.
     */
    private static function assertSaidAfterTheSummary(?string $line, string $without, string $withArguments): void
    {
        self::assertStringNotContainsString('Not verifiable', $without);

        if (null === $line) {
            self::assertSame($without, $withArguments);

            return;
        }

        self::assertNotSame('', $without);
        self::assertStringStartsWith($without, $withArguments, 'The summary line is written as it was, before anything else.');

        $added = (string) preg_replace(['/\e\[[0-9;]*m/', '/<(?:[a-z]+=[^<>]*|\/)>/'], '', substr($withArguments, \strlen($without)));
        self::assertSame($line, trim($added));
        self::assertStringEndsWith("\n", $added);
    }
}

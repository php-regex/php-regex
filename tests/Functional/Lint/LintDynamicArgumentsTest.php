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

namespace PHPRegex\Tests\Functional\Lint;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Tests\Support\ArgumentCarriers;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `regex lint` on pattern arguments built at run time. Every argument of
 * a call that reads a pattern ends in one state; a run counts them, and
 * says after its summary how many it could not verify. It reports none of
 * them one by one unless --report-dynamic, or reportDynamic in regex.json,
 * asks: each is then an info where the argument stands, which never moves
 * the exit code and which an inline marker silences.
 *
 * The run in this process reads the files with the parser when it is
 * installed; the run through the binary reads them with the tokenizer.
 */
final class LintDynamicArgumentsTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    /**
     * Five pattern arguments, one in each state: one pattern, one of two
     * patterns, a static pattern around a preg_quote() text, a static
     * pattern around another part built at run time, nothing static. The
     * three patterns that are known raise no issue and have no rewrite.
     */
    private const STATES = <<<'PHP'
        <?php

        function each_state(string $s, string $word, string $re, bool $strict): int
        {
            return preg_match('/^constant$/', $s)
                + preg_match($strict ? '/^strict$/' : '/^loose$/', $s)
                + preg_match('/^été' . preg_quote($word, '/') . '$/u', $s)
                + preg_match('/^' . $word . '$/', $s)
                + preg_match($re, $s);
        }

        PHP;

    /**
     * Two more arguments in a file of its own: one pattern, and nothing
     * static.
     */
    private const MORE = "<?php\n\npreg_match('/^more\$/', \$s);\npreg_match(\$other, \$s);\n";

    /**
     * Four arguments built at run time, two of them on the line 6: the
     * markers silence the two that have nothing static, by name, and
     * leave the two around a part.
     */
    private const MARKED = <<<'PHP'
        <?php

        function marked(string $s, string $word, string $re): int
        {
            return preg_match($re, $s) // @regex-ignore regex.lint.source.dynamic
                + preg_match($re, $s) + preg_match('/^' . $word . '$/', $s) // @regex-ignore regex.lint.source.dynamic
                + preg_match('/^' . preg_quote($word, '/') . '$/', $s);
        }

        PHP;

    /**
     * A pattern PCRE refuses beside an argument built at run time.
     */
    private const INVALID = "<?php\n\npreg_match('/(a/', \$s);\npreg_match(\$re, \$s);\n";

    /**
     * No pattern the run can read: two arguments built at run time.
     */
    private const DYNAMIC_ONLY = "<?php\n\npreg_match(\$re, \$s);\npreg_match('/^' . \$w . '\$/', \$s);\n";

    /**
     * Only literals: nothing the run could not verify.
     */
    private const VERIFIED = "<?php\n\npreg_match('/^a\$/', \$s);\npreg_match(\$strict ? '/^b\$/' : '/^c\$/', \$s);\n";

    private const LINE = 'Not verifiable: 3 pattern arguments (1 dynamic, 1 around a dynamic part, 1 around preg_quote())';

    private const DYNAMIC = 'The pattern is built at run time: it is not verified.';
    private const DYNAMIC_PART = 'The pattern holds a part built at run time: it is not verified.';
    private const QUOTED_PART = 'The pattern holds a preg_quote() text: it is not verified.';

    #[Test]
    public function test_a_run_sums_up_after_its_summary_the_arguments_it_could_not_verify_and_reports_none(): void
    {
        $this->enterProject(['src/states.php' => self::STATES]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $lines = self::fromTheSummary($stdout);
        $this->assertSame('PASS No issues found, 0 optimizations available.', $lines[0] ?? null, $stdout);
        $this->assertSame([self::LINE], self::saidNotVerifiable(\array_slice($lines, 1)), $stdout);
        $this->assertSame(1, substr_count($stdout, 'Not verifiable'), $stdout);
        $this->assertStringNotContainsString('it is not verified', $stdout);
        // The console report says it on the standard output alone.
        $this->assertStringNotContainsString('Not verifiable', $stderr);
    }

    /**
     * Asked for, each argument the run could not verify is an info: three
     * here, counted in the summary, which is still followed by the line
     * that sums them up. The run passes.
     */
    #[Test]
    public function test_report_dynamic_reports_each_argument_as_an_info(): void
    {
        $this->enterProject(['src/states.php' => self::STATES]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--report-dynamic']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        foreach ([self::DYNAMIC, self::DYNAMIC_PART, self::QUOTED_PART] as $message) {
            $this->assertSame(1, substr_count($stdout, $message), $message."\n".$stdout);
        }
        $lines = self::fromTheSummary($stdout);
        $this->assertSame('PASS 0 warnings, 3 infos found, 0 optimizations available.', $lines[0] ?? null, $stdout);
        $this->assertSame([self::LINE], self::saidNotVerifiable(\array_slice($lines, 1)), $stdout);
    }

    /**
     * regex.json asks for the same on every run, and --no-report-dynamic
     * takes it back for one.
     */
    #[Test]
    public function test_regex_json_asks_for_the_report_and_the_command_line_takes_it_back(): void
    {
        $this->enterProject(['src/states.php' => self::STATES, 'regex.json' => '{"reportDynamic": true}']);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(
            ['regex.lint.source.quotedPart', 'regex.lint.source.dynamicPart', 'regex.lint.source.dynamic'],
            array_column(self::issuesOf($stdout), 'issue_id'),
        );

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--no-report-dynamic']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame([], self::issuesOf($stdout));
        $this->assertSame(['constant' => 1, 'finite_set' => 1, 'quoted_shell' => 1, 'raw_shell' => 1, 'opaque' => 1], ArgumentCarriers::countsOf(JsonContract::decodeDocument($stdout)['stats'] ?? null));
    }

    /**
     * A marker on the line of an argument silences its issue like any
     * other: by name here, each marker silencing the argument with nothing
     * static of its line and leaving the argument around a part that
     * stands beside it. A silenced argument is still counted, and a marker
     * that silenced one is not reported as silencing nothing.
     */
    #[Test]
    public function test_an_inline_marker_silences_the_argument_of_its_line(): void
    {
        $this->enterProject(['src/marked.php' => self::MARKED]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--report-dynamic']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(
            [
                ['line' => 6, 'column' => self::columnIn(self::MARKED, "'/^' . \$word . '$/'"), 'severity' => 'info', 'issue_id' => 'regex.lint.source.dynamicPart'],
                ['line' => 7, 'column' => self::columnIn(self::MARKED, "'/^' . preg_quote("), 'severity' => 'info', 'issue_id' => 'regex.lint.source.quotedPart'],
            ],
            array_map(static fn (array $issue): array => ['line' => $issue['line'], 'column' => $issue['column'], 'severity' => $issue['severity'], 'issue_id' => $issue['issue_id']], self::issuesOf($stdout)),
        );
        $stats = JsonContract::asArray(JsonContract::decodeDocument($stdout)['stats'] ?? null);
        $this->assertSame(2, $stats['infos'] ?? null);
        $this->assertSame(0, $stats['errors'] ?? null);
        $this->assertSame(['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 1, 'raw_shell' => 1, 'opaque' => 2], ArgumentCarriers::countsOf($stats));
    }

    /**
     * @return iterable<string, array{file: string, exitCode: int}>
     */
    public static function provideRuns(): iterable
    {
        yield 'a run that passes' => ['file' => self::STATES, 'exitCode' => 0];
        yield 'a run that fails' => ['file' => self::INVALID, 'exitCode' => 1];
        yield 'a run that reads no pattern' => ['file' => self::DYNAMIC_ONLY, 'exitCode' => 0];
        yield 'a run with nothing built at run time' => ['file' => self::VERIFIED, 'exitCode' => 0];
    }

    /**
     * No issue of an argument is an error: the exit code is the one of the
     * run that does not ask, in every format.
     */
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_the_report_does_not_move_the_exit_code(string $file, int $exitCode): void
    {
        $this->enterProject(['src/a.php' => $file]);

        foreach (['console', 'json', 'github', 'checkstyle', 'junit'] as $format) {
            [$without, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format='.$format]);
            $this->assertSame($exitCode, $without, $format."\n".$stdout.$stderr);

            [$with, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format='.$format, '--report-dynamic']);
            $this->assertSame($exitCode, $with, $format."\n".$stdout.$stderr);
        }
    }

    /**
     * GitHub prints an info as a notice, on the line and the column of the
     * argument, counted in bytes; a run that does not ask annotates
     * nothing.
     */
    #[Test]
    public function test_github_format_annotates_each_argument_as_a_notice(): void
    {
        $this->enterProject(['src/states.php' => self::STATES]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=github', '--report-dynamic']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        foreach ([
            7 => ['regex.lint.source.quotedPart', self::QUOTED_PART],
            8 => ['regex.lint.source.dynamicPart', self::DYNAMIC_PART],
            9 => ['regex.lint.source.dynamic', self::DYNAMIC],
        ] as $line => [$issueId, $message]) {
            $this->assertMatchesRegularExpression(
                '~^::notice file=src/states\.php,line='.$line.',col=22,title=[^:]*\('.preg_quote($issueId, '~').'\)::'.preg_quote($message, '~').'(?:%0A|$)~m',
                $stdout,
            );
        }
        $this->assertSame(3, substr_count($stdout, '::notice '), $stdout);
        $this->assertStringNotContainsString('::warning ', $stdout);
        $this->assertStringNotContainsString('::error ', $stdout);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=github']);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertStringNotContainsString('::notice ', $stdout);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideMachineFormats(): iterable
    {
        yield 'json' => ['format' => 'json'];
        yield 'github' => ['format' => 'github'];
        yield 'checkstyle' => ['format' => 'checkstyle'];
        yield 'junit' => ['format' => 'junit'];
    }

    /**
     * A machine format keeps the standard output for its document: the
     * line that sums the arguments up goes to the error output, once,
     * whether the run reports them one by one or not.
     */
    #[Test]
    #[DataProvider('provideMachineFormats')]
    public function test_a_machine_format_says_on_the_error_output_what_it_could_not_verify(string $format): void
    {
        $this->enterProject(['src/states.php' => self::STATES]);

        foreach ([[], ['--report-dynamic']] as $asked) {
            [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format='.$format, ...$asked]);

            $this->assertSame(0, $exitCode, $stdout.$stderr);
            $this->assertStringNotContainsString('Not verifiable', $stdout);
            $this->assertSame([self::LINE], self::saidNotVerifiable(explode("\n", $stderr)), $stderr);
        }
    }

    /**
     * Nothing is added under --quiet, and nothing when every argument is
     * verified.
     */
    #[Test]
    #[DataProvider('provideMachineFormats')]
    public function test_a_machine_format_says_nothing_under_quiet_or_when_every_argument_is_verified(string $format): void
    {
        $this->enterProject(['src/states.php' => self::STATES, 'verified/a.php' => self::VERIFIED]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--quiet', 'lint', 'src', '--jobs=1', '--format='.$format]);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertStringNotContainsString('Not verifiable', $stdout.$stderr);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'verified', '--jobs=1', '--format='.$format]);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertStringNotContainsString('Not verifiable', $stdout.$stderr);
    }

    /**
     * A quiet console run writes nothing, asked or not, and a console run
     * whose arguments are all verified adds no line.
     */
    #[Test]
    public function test_the_console_says_nothing_under_quiet_or_when_every_argument_is_verified(): void
    {
        $this->enterProject(['src/states.php' => self::STATES, 'verified/a.php' => self::VERIFIED]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--quiet', 'lint', 'src', '--jobs=1']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stdout);
        $this->assertStringNotContainsString('Not verifiable', $stderr);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--quiet', 'lint', 'src', '--jobs=1', '--report-dynamic']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stdout);
        $this->assertStringNotContainsString('Not verifiable', $stderr);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'verified', '--jobs=1']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertStringNotContainsString('Not verifiable', $stdout.$stderr);
    }

    /**
     * A project whose pattern arguments are all built at run time holds
     * no pattern to lint, and is not an empty one: the run says how many
     * arguments it could not verify, after "No regex patterns found." on
     * the console, as the five counts in the JSON report.
     */
    #[Test]
    public function test_a_run_that_reads_no_pattern_still_counts_the_arguments(): void
    {
        $this->enterProject(['src/a.php' => self::DYNAMIC_ONLY]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $lines = self::fromTheSummary($stdout);
        $this->assertSame('PASS No regex patterns found.', $lines[0] ?? null, $stdout);
        $this->assertSame(['Not verifiable: 2 pattern arguments (1 dynamic, 1 around a dynamic part)'], self::saidNotVerifiable(\array_slice($lines, 1)), $stdout);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $this->assertSame([], $document['results'] ?? null);
        $this->assertSame(['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 1, 'opaque' => 1], ArgumentCarriers::countsOf($document['stats'] ?? null));
        // The document stays alone on the standard output.
        $this->assertStringNotContainsString('Not verifiable', $stdout);
    }

    /**
     * Asked for them, such a run reports each argument all the same: it
     * holds no pattern, and is not left as an empty run. As many issues
     * under each identifier as arguments counted in that state, on the
     * console and in the JSON report, and the run passes.
     */
    #[Test]
    public function test_report_dynamic_reports_the_arguments_of_a_run_that_reads_no_pattern(): void
    {
        $this->enterProject(['src/a.php' => self::DYNAMIC_ONLY]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--report-dynamic']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);
        $this->assertSame(
            [
                ['line' => 3, 'column' => 12, 'severity' => 'info', 'issue_id' => 'regex.lint.source.dynamic'],
                ['line' => 4, 'column' => 12, 'severity' => 'info', 'issue_id' => 'regex.lint.source.dynamicPart'],
            ],
            array_map(static fn (array $issue): array => ['line' => $issue['line'], 'column' => $issue['column'], 'severity' => $issue['severity'], 'issue_id' => $issue['issue_id']], self::issuesOf($stdout)),
        );
        $stats = JsonContract::asArray($document['stats'] ?? null);
        $this->assertSame([0, 0, 2], [$stats['errors'] ?? null, $stats['warnings'] ?? null, $stats['infos'] ?? null]);
        $this->assertSame(['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 1, 'opaque' => 1], ArgumentCarriers::countsOf($stats));

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--report-dynamic']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(1, substr_count($stdout, self::DYNAMIC), $stdout);
        $this->assertSame(1, substr_count($stdout, self::DYNAMIC_PART), $stdout);
        $this->assertSame(['Not verifiable: 2 pattern arguments (1 dynamic, 1 around a dynamic part)'], self::saidNotVerifiable(explode("\n", $stdout)), $stdout);
    }

    /**
     * A run that does not ask leaves the three checks off: the markers
     * naming one of them are not said to silence nothing, on the line of
     * an argument built at run time or on any other, and the run passes.
     */
    #[Test]
    public function test_a_marker_naming_an_issue_of_an_argument_is_left_alone_by_a_run_that_does_not_ask(): void
    {
        $this->enterProject(['src/marked.php' => self::MARKED, 'src/other.php' => "<?php\n\necho 1; // @regex-ignore regex.lint.source.quotedPart, regex.lint.source.dynamicPart\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame([], self::issuesOf($stdout));
        $stats = JsonContract::asArray(JsonContract::decodeDocument($stdout)['stats'] ?? null);
        $this->assertSame(0, $stats['errors'] ?? null);
        $this->assertSame(['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 1, 'raw_shell' => 1, 'opaque' => 2], ArgumentCarriers::countsOf($stats));

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertStringNotContainsString('silences nothing', $stdout);
        $this->assertStringNotContainsString('which no issue reports', $stdout);
    }

    /**
     * A run that asks runs them: a marker naming one of the three on a
     * line where nothing is reported under it silences nothing, and fails
     * the run as any such marker does. The argument of the line, reported
     * under its own identifier, stays an info.
     */
    #[Test]
    public function test_a_marker_naming_an_issue_its_line_does_not_report_is_unmatched_when_asked(): void
    {
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match(\$re, \$s); // @regex-ignore regex.lint.source.quotedPart\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame([], self::issuesOf($stdout));

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--report-dynamic']);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $issues = array_map(static fn (array $issue): array => ['line' => $issue['line'], 'severity' => $issue['severity'], 'issue_id' => $issue['issue_id'], 'message' => $issue['message']], self::issuesOf($stdout));
        usort($issues, static fn (array $left, array $right): int => $left['issue_id'] <=> $right['issue_id']);
        $this->assertSame(
            [
                ['line' => 3, 'severity' => 'error', 'issue_id' => 'regex.ignore.unmatched', 'message' => 'The @regex-ignore marker names regex.lint.source.quotedPart, which no issue reports on line 3. Remove the identifier.'],
                ['line' => 3, 'severity' => 'info', 'issue_id' => 'regex.lint.source.dynamic', 'message' => self::DYNAMIC],
            ],
            $issues,
        );

        // Turned off for one run, the report of the markers says nothing.
        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--report-dynamic', '--no-report-unmatched-ignores']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(['regex.lint.source.dynamic'], array_column(self::issuesOf($stdout), 'issue_id'));
    }

    /**
     * Every argument has one state, and the five counts sum to the
     * arguments, whichever strategy reads the files and however many
     * workers share them: the run of this process, one worker, and the
     * run of the binary, two workers, count alike and report alike.
     */
    #[Test]
    public function test_every_argument_has_one_state_whatever_the_workers(): void
    {
        $this->enterProject(['src/states.php' => self::STATES, 'src/more.php' => self::MORE]);
        $counts = ['constant' => 2, 'finite_set' => 1, 'quoted_shell' => 1, 'raw_shell' => 1, 'opaque' => 2];

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--report-dynamic']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $inProcess = JsonContract::decodeDocument($stdout);
        $this->assertSame($counts, ArgumentCarriers::countsOf($inProcess['stats'] ?? null));
        $this->assertSame(7, array_sum(ArgumentCarriers::countsOf($inProcess['stats'] ?? null)));
        $this->assertSame(
            ['regex.lint.source.dynamic', 'regex.lint.source.quotedPart', 'regex.lint.source.dynamicPart', 'regex.lint.source.dynamic'],
            array_column(self::issuesOf($stdout), 'issue_id'),
        );

        [$exitCode, $stdout, $stderr] = $this->runRegexProcess(['lint', 'src', '--jobs='.(ForkedWorkerPool::canFork() ? 2 : 1), '--format=json', '--report-dynamic']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $forked = JsonContract::decodeDocument($stdout);
        $this->assertSame($counts, ArgumentCarriers::countsOf($forked['stats'] ?? null));
        $this->assertSame($inProcess['results'] ?? null, $forked['results'] ?? null);
        $this->assertSame(
            ['Not verifiable: 4 pattern arguments (2 dynamic, 1 around a dynamic part, 1 around preg_quote())'],
            self::saidNotVerifiable(explode("\n", $stderr)),
            $stderr,
        );
    }

    /**
     * A file the parser refuses is read again with the tokenizer: its
     * arguments get their state there, are counted, and are reported
     * where they stand.
     */
    #[Test]
    public function test_the_arguments_of_a_file_the_parser_refuses_are_counted_and_reported(): void
    {
        $this->enterProject(['src/broken.php' => "<?php\npreg_match(\$re, \$s);\npreg_match('/^a\$/', \$s);\nfunction (\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--report-dynamic']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        $stats = JsonContract::asArray($document['stats'] ?? null);
        $this->assertSame(class_exists(ParserFactory::class) ? 1 : 0, $stats['parser_fallbacks'] ?? null);
        $this->assertSame(['constant' => 1, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 0, 'opaque' => 1], ArgumentCarriers::countsOf($stats));

        $results = JsonContract::asArray($document['results'] ?? null);
        $this->assertCount(1, $results);
        $result = JsonContract::asArray($results[0]);
        $this->assertSame(['src/broken.php', 2, 12, 17, '$re'], [$result['file'], $result['line'], $result['column'], $result['file_offset'], $result['expression']]);
        $this->assertSame(['regex.lint.source.dynamic'], array_column(JsonContract::asArray($result['issues']), 'issue_id'));
    }

    /**
     * The column and the offset of an argument count bytes: text of
     * several bytes before it on its line moves both by its bytes, in the
     * JSON report and in the annotation alike. The expression is given
     * byte for byte.
     */
    #[Test]
    public function test_the_column_of_an_argument_counts_bytes(): void
    {
        $file = "<?php\n\n\$trouvé = preg_match('/^été' . \$mot . '$/u', \$s);\n";
        $this->enterProject(['src/a.php' => $file]);
        $offset = (int) strpos($file, "'/^été'");
        $column = self::columnIn($file, "'/^été'");
        $this->assertSame(23, $column);
        // One more than the column counted in characters: "é" is two bytes.
        $this->assertSame(22, mb_strlen(substr($file, (int) strpos($file, '$trouvé'), $offset - (int) strpos($file, '$trouvé'))) + 1);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--report-dynamic']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $results = JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null);
        $this->assertCount(1, $results);
        $result = JsonContract::asArray($results[0]);
        $this->assertSame(
            [3, $column, $offset, null, "'/^été' . \$mot . '$/u'"],
            [$result['line'], $result['column'], $result['file_offset'], $result['pattern'], $result['expression']],
        );
        $this->assertSame(['regex.lint.source.dynamicPart'], array_column(JsonContract::asArray($result['issues']), 'issue_id'));

        [, $github] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=github', '--report-dynamic']);

        $this->assertStringContainsString('::notice file=src/a.php,line=3,col='.$column.',', $github);
    }

    /**
     * An argument written over several lines is reported on the line of
     * its first token, and its expression holds every line of it as the
     * source writes them.
     */
    #[Test]
    public function test_an_argument_written_over_several_lines_is_given_whole(): void
    {
        $file = "<?php\n\npreg_match(\n    '/^'\n        . \$word\n        . '$/',\n    \$s\n);\n";
        $this->enterProject(['src/a.php' => $file]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--report-dynamic']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $results = JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null);
        $this->assertCount(1, $results);
        $result = JsonContract::asArray($results[0]);
        $this->assertSame(
            [4, 5, (int) strpos($file, "'/^'"), "'/^'\n        . \$word\n        . '$/'"],
            [$result['line'], $result['column'], $result['file_offset'], $result['expression']],
        );

        // One annotation, on one line of the report.
        [, $github] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=github', '--report-dynamic']);
        $this->assertSame(1, substr_count(trim($github), "\n") + 1, $github);
        $this->assertStringStartsWith('::notice file=src/a.php,line=4,col=5,', $github);
    }

    /**
     * The issues of a JSON report, in its order.
     *
     * @return list<array<mixed>>
     */
    private static function issuesOf(string $stdout): array
    {
        $issues = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issues[] = JsonContract::asArray($issue);
            }
        }

        return $issues;
    }

    /**
     * The summary line of a console report and every line written after
     * it that is not blank, each trimmed, its runs of spaces made one.
     *
     * @return list<string>
     */
    private static function fromTheSummary(string $stdout): array
    {
        $lines = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = (string) preg_replace('/ {2,}/', ' ', trim($line));
            if (1 === preg_match('/^(?:PASS|WARN|FAIL)\b/', $line)) {
                $lines = [$line];
            } elseif ([] !== $lines && '' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The lines that say what the run could not verify, each trimmed.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function saidNotVerifiable(array $lines): array
    {
        return array_values(array_filter(array_map(trim(...), $lines), static fn (string $line): bool => str_contains($line, 'Not verifiable')));
    }

    /**
     * The column, counted in bytes from 1, of a text a source holds once.
     */
    private static function columnIn(string $source, string $text): int
    {
        self::assertSame(1, substr_count($source, $text), $text);
        $offset = (int) strpos($source, $text);

        return $offset - ((int) strrpos(substr($source, 0, $offset), "\n") + 1) + 1;
    }
}

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

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the lint command writes where: the report alone on stdout for the
 * machine formats, the target and the notes inside the console banner,
 * and the machine formats' target, notes and status lines on stderr.
 */
final class LintCommandStreamsTest extends TestCase
{
    use TemporaryProject;

    private const PHP_FILE = "<?php\n\npreg_match('/^[a-z]+$/', 'abc');\n";

    private const COMPOSER = '{"name": "acme/app", "require": {"php": "^8.3"}}';

    private const COMPOSER_BELOW_THE_FLOOR = '{"name": "acme/app", "require": {"php": "^7.4"}}';

    /**
     * Two patterns in one file: the file is read in this process (one file,
     * nothing to share), the two patterns are analysed by two workers.
     */
    private const TWO_PATTERNS = "<?php\n\npreg_match('/^[a-z]+$/', 'abc');\npreg_match('/^[0-9]+$/', '123');\n";

    /**
     * Process ids no system hands out (above the largest pid_max, 2^22 on
     * Linux, 99998 on macOS): a fork that "gives" one started no worker, so
     * the worker's channel is closed from the start, as when a worker is
     * killed. Waiting for such a pid fails at once, signalling it does
     * nothing.
     */
    private const GONE_WORKER_PIDS = [2_000_000_001, 2_000_000_002];

    /**
     * The width the banner pads every label to: the widest of Runtime,
     * Target, Processes, PCRE JIT, Backtrack and Recursion. None of
     * the projects below ships a regex.dist.json or regex.json, which
     * would add a Configuration row and widen the padding to 13.
     */
    private const BANNER_LABEL_WIDTH = 9;

    protected function setUp(): void
    {
        LintFunctionOverrides::reset();
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    #[Test]
    public function test_lint_prints_the_target_in_the_banner_for_the_console_format(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        // Rank 2: the padded Target row right between Runtime and Processes,
        // same grammar as the bridges, padding per the native table. Runtime
        // and Target are parallel compound rows; the running PCRE2 release
        // rides on the Runtime row, not on a row of its own.
        $runningPcre = PcreTarget::runtime()->pcreVersion;
        $this->assertStringContainsString(
            self::bannerRow('Runtime', 'PHP '.\PHP_VERSION.', PCRE2 '.$runningPcre)
            .self::bannerRow('Target', 'PHP 8.3, PCRE2 10.42 (composer.json require.php)')
            .self::bannerRow('Processes', '1'),
            $stdout,
        );
        $this->assertStringNotContainsString("\n".str_pad('PCRE', self::BANNER_LABEL_WIDTH).' : ', $stdout);
        $this->assertStringNotContainsString('Target:', $stderr);
        // A status line, written with the progress on stderr: stdout keeps
        // the banner and the report.
        $this->assertStringContainsString('Scanned 1 files, found 1 patterns.', $stderr);
        $this->assertStringNotContainsString('Scanned 1 files', $stdout);
    }

    /**
     * The console footer gives the time, the memory and the processes. It
     * names no cache: the binary keeps none between runs, and the workers'
     * caches are not the parent's, whose count read 0 hits, 0 misses.
     *
     * Processes counts the workers the run started, at most --jobs: two
     * files and two patterns keep two workers busy, one file with one
     * pattern is read and analysed in this process alone, whatever --jobs
     * allows. Without pcntl no worker starts.
     *
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideJobs')]
    public function test_lint_console_footer_names_no_cache(array $files, string $jobs, string $processes): void
    {
        $this->enterProject($files);

        [$exitCode, $stdout] = $this->runLint(['src', '--format=console', '--jobs='.$jobs, '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/^  Time: \S+  Memory: \S+ MB  Processes: '.$processes.'$/m', $stdout);
        $this->assertStringNotContainsString('Cache:', $stdout);
        // The banner shows what the run planned: --jobs.
        $this->assertStringContainsString("\n".self::bannerRow('Processes', $jobs), $stdout);
    }

    /**
     * @return iterable<string, array{files: array<string, string>, jobs: string, processes: string}>
     */
    public static function provideJobs(): iterable
    {
        $twoFiles = ['src/a.php' => self::PHP_FILE, 'src/b.php' => self::PHP_FILE];

        yield 'one process' => ['files' => $twoFiles, 'jobs' => '1', 'processes' => '1'];
        yield 'two processes' => ['files' => $twoFiles, 'jobs' => '2', 'processes' => \function_exists('pcntl_fork') ? '2' : '1'];
        yield 'two allowed, one file with one pattern' => ['files' => ['src/a.php' => self::PHP_FILE], 'jobs' => '2', 'processes' => '1'];
        yield 'eight allowed, two files with a pattern each' => ['files' => $twoFiles, 'jobs' => '8', 'processes' => \function_exists('pcntl_fork') ? '2' : '1'];
    }

    /**
     * No PHP file to read: the scan's counter shows 0 found and its line
     * is ended, no other step starts, and the run passes.
     */
    #[Test]
    public function test_lint_ends_the_scan_line_when_no_file_is_found_with_ansi(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/readme.txt' => 'no PHP here']);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=4', '--no-redos'], ansi: true);

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/Finding files\n[^\e]*\r +0 found +\d{2}:\d{2}\n/', $stderr);
        $this->assertStringEndsWith("\n", $stderr, 'The scan\'s line is left open: '.json_encode($stderr));
        $this->assertStringNotContainsString('Analyzing patterns', $stderr);
        $this->assertStringNotContainsString("\r", $stdout);
    }

    /**
     * Without pcntl no worker can start: the default the banner and the
     * footer show is one process, whatever the machine counts.
     */
    #[Test]
    public function test_lint_shows_one_process_by_default_without_pcntl(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE, 'src/b.php' => self::PHP_FILE]);
        LintFunctionOverrides::$missingFunctions = ['pcntl_fork'];

        [$exitCode, $stdout] = $this->runLint(['src', '--format=console', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("\n".self::bannerRow('Processes', '1'), $stdout);
        $this->assertMatchesRegularExpression('/^  Time: \S+  Memory: \S+ MB  Processes: 1$/m', $stdout);
    }

    /**
     * The console run names its steps on stderr, the optimizations a step
     * of their own unless --no-optimize turns them off.
     *
     * @param list<string> $options
     * @param list<string> $steps
     */
    #[Test]
    #[DataProvider('provideSteps')]
    public function test_lint_names_its_steps_on_stderr(array $options, array $steps, string $absent): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos', ...$options]);

        $this->assertSame(0, $exitCode);
        $offset = 0;
        foreach ($steps as $step) {
            $position = strpos($stderr, $step, $offset);
            $this->assertNotFalse($position, \sprintf('"%s" is not on stderr, after the steps before it: %s', $step, $stderr));
            $offset = $position + \strlen($step);
        }
        $this->assertStringNotContainsString($absent, $stderr);
        $this->assertStringNotContainsString('Scanning files', $stdout);
        $this->assertStringNotContainsString('Analyzing patterns', $stdout);
        $this->assertStringNotContainsString('Suggesting optimizations', $stdout);
    }

    /**
     * @return iterable<string, array{options: list<string>, steps: list<string>, absent: string}>
     */
    public static function provideSteps(): iterable
    {
        yield 'with the optimizations' => [
            'options' => [],
            'steps' => ['[1/3] Scanning files', '[2/3] Analyzing patterns', '[3/3] Suggesting optimizations'],
            'absent' => '/2]',
        ];
        yield 'with --no-optimize' => [
            'options' => ['--no-optimize'],
            'steps' => ['[1/2] Scanning files', '[2/2] Analyzing patterns'],
            'absent' => 'Suggesting optimizations',
        ];
    }

    /**
     * The progress frames, redrawn with a carriage return, are drawn on
     * stderr: a coloured report redirected to a file holds none.
     */
    #[Test]
    public function test_lint_keeps_the_progress_frames_off_stdout_with_ansi(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE, 'src/b.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos'], ansi: true);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString("\r", $stdout);
        $this->assertStringContainsString("\r", $stderr, 'The progress is drawn on stderr under ANSI.');
        $this->assertStringContainsString('2/2', $stderr);
    }

    /**
     * A worker that ends without its results (a fatal error, out of memory,
     * killed) stops the run: exit code 1, the failure on stderr, naming the
     * file the worker was reading, and no report on stdout.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_lint_reports_a_worker_lost_during_the_analysis(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::TWO_PATTERNS]);
        foreach (self::GONE_WORKER_PIDS as $pid) {
            LintFunctionOverrides::queuePcntlForkResult($pid);
        }

        [$exitCode, $stdout, $stderr] = self::withinTimeLimit(fn (): array => $this->runLint(['src', '--format=console', '--jobs=2', '--no-redos']));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Parallel analysis failed: a worker exited unexpectedly while reading ', $stderr);
        $this->assertStringContainsString('a.php', $stderr);
        $this->assertStringNotContainsString('Parallel analysis failed', $stdout);
        $this->assertStringNotContainsString('Time: ', $stdout, 'No footer: the run did not end.');
    }

    /**
     * The same failure under --format=json is the error envelope, at the
     * new stage analyze.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_lint_reports_a_worker_lost_during_the_analysis_as_json(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::TWO_PATTERNS]);
        foreach (self::GONE_WORKER_PIDS as $pid) {
            LintFunctionOverrides::queuePcntlForkResult($pid);
        }

        [$exitCode, $stdout] = self::withinTimeLimit(fn (): array => $this->runLint(['src', '--format=json', '--jobs=2', '--no-redos']));

        $payload = json_decode($stdout, true);
        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload, 'stdout is not JSON: '.$stdout);
        $this->assertSame('analyze', $payload['stage'] ?? null);
        $this->assertIsString($payload['error'] ?? null);
        $this->assertStringContainsString('Parallel analysis failed: a worker exited unexpectedly while reading ', (string) $payload['error']);
    }

    /**
     * On a terminal the progress line is redrawn in place, after a carriage
     * return, and left open until its step ends: a run stopped mid-step
     * ends that line before it prints its error, which starts a line of its
     * own instead of trailing the last frame.
     *
     * @param array<string, string> $files
     * @param list<string>          $options
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    #[DataProvider('provideWorkerFailures')]
    public function test_lint_prints_a_worker_failure_on_a_line_of_its_own_with_ansi(array $files, array $options, string $error): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER] + $files);
        foreach (self::GONE_WORKER_PIDS as $pid) {
            LintFunctionOverrides::queuePcntlForkResult($pid);
        }

        [$exitCode, , $stderr] = self::withinTimeLimit(fn (): array => $this->runLint(['src', '--format=console', '--no-redos', ...$options], ansi: true));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("\r", $stderr, 'No progress frame was drawn: nothing to end.');
        $this->assertStringContainsString('Error: '.$error, $stderr);
        $this->assertMatchesRegularExpression('/(?:\A|\n)Error: '.preg_quote($error, '/').'/', $stderr, 'The error trails the last progress frame on its line: '.json_encode($stderr));
        $lastFrame = strrpos($stderr, "\r");
        $errorAt = strpos($stderr, 'Error: ');
        $this->assertNotFalse($errorAt);
        $this->assertLessThan($errorAt, $lastFrame, 'A progress frame is drawn after the error.');
        $this->assertStringContainsString("\n", substr($stderr, (int) $lastFrame, $errorAt - (int) $lastFrame), 'No line ends between the last progress frame and the error.');
    }

    /**
     * @return iterable<string, array{files: array<string, string>, options: list<string>, error: string}>
     */
    public static function provideWorkerFailures(): iterable
    {
        yield 'a worker lost during the analysis' => [
            'files' => ['src/a.php' => self::TWO_PATTERNS],
            'options' => ['--jobs=2'],
            'error' => 'Parallel analysis failed: a worker exited unexpectedly while reading ',
        ];
        yield 'a worker lost during the collection' => [
            'files' => ['src/a.php' => self::PHP_FILE, 'src/b.php' => self::PHP_FILE],
            'options' => ['--jobs=2'],
            'error' => 'Failed to collect patterns: Parallel collection failed: a worker exited unexpectedly while reading ',
        ];
    }

    /**
     * A collection that fails in this process, on a directory it cannot
     * read, ends the scan's line before its error too.
     */
    #[Test]
    public function test_lint_prints_a_collection_failure_on_a_line_of_its_own_with_ansi(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'locked/a.php' => self::PHP_FILE]);
        chmod($directory.'/locked', 0o000);

        try {
            [$exitCode, , $stderr] = $this->runLint(['locked', '--format=console', '--jobs=1', '--no-redos'], ansi: true);
        } finally {
            chmod($directory.'/locked', 0o700);
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Error: Failed to collect patterns', $stderr);
        $this->assertMatchesRegularExpression('/(?:\A|\n)Error: Failed to collect patterns/', $stderr, 'The error trails the scan\'s line: '.json_encode($stderr));
    }

    #[Test]
    public function test_lint_prints_the_notices_in_the_banner_for_the_console_format(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Note: No composer.json', $stdout);
        $this->assertStringNotContainsString('Note:', $stderr);
    }

    #[Test]
    public function test_lint_prints_the_flag_target_in_the_banner_for_the_console_format(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runLint(
            ['src', '--format=console', '--jobs=1', '--no-redos'],
            phpVersion: '8.1',
            pcreVersion: '10.42',
        );

        $this->assertSame(0, $exitCode);
        // Each version named by its own flag: the compound source.
        $this->assertStringContainsString(
            "\n".self::bannerRow('Target', 'PHP 8.1, PCRE2 10.42 (--php-version; --pcre-version)'),
            $stdout,
        );
    }

    #[Test]
    public function test_lint_prints_the_clamped_floor_and_its_notice_in_the_banner_for_the_console_format(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER_BELOW_THE_FLOOR, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            "\n".self::bannerRow('Target', 'PHP 8.2, PCRE2 10.40 (composer.json require.php)'),
            $stdout,
        );
        $this->assertStringContainsString(
            'Note: composer.json require.php allows PHP 7.4, older than the PHP 8.2 this library supports: judging for PHP 8.2.',
            $stdout,
        );
        $this->assertStringNotContainsString('Note:', $stderr);
    }

    #[Test]
    public function test_lint_prints_no_console_banner_when_quiet(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos', '--quiet']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stderr);
        // The banner header: the word PHPRegex alone cannot be asserted
        // absent, the report footer says "If PHPRegex helps" too.
        $this->assertStringNotContainsString('by Younes ENNAJI', $stdout);
    }

    #[Test]
    public function test_lint_prints_no_console_banner_for_the_global_quiet_layer(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos'], globalQuiet: true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_lint_prints_nothing_on_stderr_when_quiet(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, , $stderr] = $this->runLint(['src', '--format=github', '--jobs=1', '--no-redos', '--quiet']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_lint_prints_the_composer_notice_on_stderr(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Note: No composer.json', $stderr);
        $this->assertStringNotContainsString('Target:', $stderr);
        $this->assertIsArray(json_decode($stdout, true));
    }

    #[Test]
    #[DataProvider('provideMachineFormats')]
    public function test_lint_prints_the_target_and_notes_on_stderr_for_machine_formats(string $format): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format='.$format, '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/^Target: PHP \d+\.\d+(\.[1-9]\d*)?, PCRE2 [\d.]+ \(running PHP\)$/m', $stderr);
        $this->assertStringContainsString('Note: No composer.json', $stderr);
        $this->assertLessThan(
            strpos($stderr, 'Target:'),
            strpos($stderr, 'Note:'),
            'the notes print before the target line',
        );
        $this->assertStringNotContainsString('Target:', $stdout);
        $this->assertStringNotContainsString('Note:', $stdout);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideMachineFormats(): iterable
    {
        yield 'github' => ['format' => 'github'];
        yield 'checkstyle' => ['format' => 'checkstyle'];
        yield 'junit' => ['format' => 'junit'];
    }

    #[Test]
    public function test_lint_keeps_status_lines_out_of_a_json_report(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint([
            'src', '--format=json', '--jobs=1', '--no-redos',
            '--output='.$directory.'/build/report.json',
            '--generate-baseline='.$directory.'/baseline.json',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray(json_decode($stdout, true), 'stdout is not JSON: '.$stdout);
        $this->assertStringContainsString('Output also written to: '.$directory.'/build/report.json', $stderr);
        $this->assertStringContainsString('Baseline generated at', $stderr);
        $this->assertIsArray(json_decode((string) file_get_contents($directory.'/build/report.json'), true));
    }

    /**
     * The console report shown in colour is written to the file without
     * its escape sequences: the file holds the same report, as plain text.
     */
    #[Test]
    public function test_lint_strips_ansi_codes_from_a_console_report_written_to_a_file(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => "<?php\n\npreg_match('/[aa]/', 'a');\n"]);

        [, $stdout] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos', '--output='.$directory.'/build/report.txt'], ansi: true);

        $written = (string) file_get_contents($directory.'/build/report.txt');
        // The report on stdout carries escape sequences the file does not.
        $this->assertStringNotContainsString($written, $stdout, 'The console report is not in colour: nothing to strip.');
        $this->assertStringNotContainsString("\e[", $written);
        $this->assertStringContainsString('src/a.php', $written);
        $this->assertStringContainsString($written, (string) preg_replace(['/\e\[[0-9;]*m/', '/^  /m'], '', $stdout));
        $this->assertStringContainsString('Output also written to: '.$directory.'/build/report.txt', $stdout);
    }

    /**
     * The terminal's two-space margin stays on screen: in the file every
     * report starts at the first column, and the lines below it keep their
     * depth relative to it.
     */
    #[Test]
    public function test_lint_writes_a_console_report_to_a_file_without_the_terminal_margin(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => "<?php\n\npreg_match('/[aa]/', 'a');\n"]);

        [, $stdout] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos', '--output='.$directory.'/build/report.txt']);

        $written = (string) file_get_contents($directory.'/build/report.txt');
        $this->assertStringStartsWith('src/a.php:3:', $written);
        $this->assertMatchesRegularExpression('/^  WARN /m', $written);
        $this->assertMatchesRegularExpression('/^    → \/\[aa\]\/$/m', $written);
        $this->assertMatchesRegularExpression('/^  src\/a\.php:3:/m', $stdout, 'The terminal keeps its margin.');
    }

    /**
     * An --output file that cannot be written is refused before the run, as
     * a --generate-baseline file is: a report that never reaches its file
     * does not pass as a clean run.
     */
    #[Test]
    #[DataProvider('provideUnwritableOutputFiles')]
    public function test_lint_refuses_an_output_file_it_cannot_write(string $output): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE, 'taken' => '']);

        [$jsonExitCode, $stdout] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos', '--output='.$directory.'/'.$output]);
        [$consoleExitCode, $consoleStdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos', '--output='.$directory.'/'.$output]);

        $this->assertSame(2, $jsonExitCode);
        $this->assertSame(['error' => 'Output file not writable: '.$directory.'/'.$output, 'stage' => 'usage'], json_decode($stdout, true));
        $this->assertSame(2, $consoleExitCode);
        $this->assertSame('Error: Output file not writable: '.$directory.'/'.$output."\n", $stderr);
        $this->assertStringNotContainsString('Scanning files', $consoleStdout);
    }

    /**
     * @return iterable<string, array{output: string}>
     */
    public static function provideUnwritableOutputFiles(): iterable
    {
        yield 'a file in the way of its directory' => ['output' => 'taken/report.json'];
        yield 'a file deeper below a file' => ['output' => 'taken/build/report.json'];
        yield 'a directory' => ['output' => 'src'];
    }

    /**
     * The directories of the --output file that do not exist yet are made.
     */
    #[Test]
    public function test_lint_makes_the_missing_directories_of_the_output_file(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, , $stderr] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos', '--output='.$directory.'/build/reports/lint/report.json']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertIsArray(json_decode((string) file_get_contents($directory.'/build/reports/lint/report.json'), true));
    }

    /**
     * A writable file that is not a regular one, a device or a pipe, takes
     * the report as a file does: /dev/null throws it away.
     */
    #[Test]
    public function test_lint_writes_the_output_to_a_device(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, , $stderr] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos', '--output=/dev/null']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame("Output also written to: /dev/null\n", $stderr);
    }

    #[Test]
    public function test_lint_reports_a_collection_failure_as_json(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'locked/a.php' => self::PHP_FILE]);
        chmod($directory.'/locked', 0o000);

        try {
            [$exitCode, $stdout] = $this->runLint(['locked', '--format=json', '--jobs=1', '--no-redos']);
        } finally {
            chmod($directory.'/locked', 0o700);
        }

        $payload = json_decode($stdout, true);
        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload, 'stdout is not JSON: '.$stdout);
        $this->assertIsString($payload['error'] ?? null);
        $this->assertStringContainsString('Failed to collect patterns', (string) $payload['error']);
        $this->assertSame('collect', $payload['stage'] ?? null);
    }

    /**
     * Runs the lint, failing instead of hanging when it waits for a worker
     * that never answers.
     *
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     */
    private static function withinTimeLimit(\Closure $run): mixed
    {
        $async = pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function (): never {
            throw new \RuntimeException('The lint did not end within 30 seconds: it waits for a worker that is gone.');
        });
        pcntl_alarm(30);

        try {
            return $run();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            pcntl_async_signals($async);
        }
    }

    /**
     * One banner table row, padded the way the renderer pads it: the label
     * padded to the table's widest label, then " : ", then the value, then
     * the row's newline.
     */
    private static function bannerRow(string $label, string $value): string
    {
        return str_pad($label, self::BANNER_LABEL_WIDTH).' : '.$value."\n";
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runLint(array $args, ?string $phpVersion = null, ?string $pcreVersion = null, bool $globalQuiet = false, bool $ansi = false): array
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        ob_start();

        try {
            $exitCode = $command->run(
                new Input('lint', $args, new GlobalOptions(false, false, false, true, $phpVersion, null, $pcreVersion), []),
                new Output($ansi, $globalQuiet, errorStream: $stream),
            );
        } finally {
            $stdout = (string) ob_get_clean();
        }

        rewind($stream);

        return [$exitCode, $stdout, (string) stream_get_contents($stream)];
    }
}

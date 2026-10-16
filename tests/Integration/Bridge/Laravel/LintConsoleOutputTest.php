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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint on the console: the report is written once, through the
 * console styles, and a run that stops prints its error line, never a
 * JSON document, with the exit code of the stage that failed.
 */
final class LintConsoleOutputTest extends TestCase
{
    use TemporaryProject;

    /**
     * Two patterns in one file: the file is read in this process (one file,
     * nothing to share), the two patterns are analysed by two workers.
     */
    private const TWO_PATTERNS = "<?php\n\npreg_match('/^[a-z]+$/', \$subject);\npreg_match('/^[0-9]+$/', \$subject);\n";

    #[Before]
    protected function resetLintFunctions(): void
    {
        LintFunctionOverrides::reset();
    }

    #[After]
    protected function restoreLintFunctions(): void
    {
        LintFunctionOverrides::reset();
    }

    #[Test]
    public function test_console_report_is_written_once(): void
    {
        $project = $this->makeProject(['src/Pattern.php' => "<?php\n\npreg_match('/(a/', \$subject);\n"]);

        $status = Artisan::call('regex:lint', $this->arguments($project.'/src'));

        $this->assertSame(1, $status);
        $output = Artisan::output();
        $this->assertSame(1, substr_count($output, 'Expected ) at end of input'), $output);
        $this->assertSame(1, substr_count($output, '1 invalid patterns, 0 warnings, 0 optimizations.'), $output);
    }

    /**
     * A file read with the tokenizer because the PHP parser could not is no
     * pattern of its own.
     */
    #[Test]
    public function test_console_pattern_count_leaves_parser_fallbacks_out(): void
    {
        $project = $this->makeProject(['src/Broken.php' => "<?php\npreg_match('/a+/', \$s);\nfunction (\n"]);

        $status = Artisan::call('regex:lint', $this->arguments($project.'/src'));

        $this->assertSame(0, $status);
        $this->assertStringContainsString('found 1 patterns.', Artisan::output());
    }

    /**
     * The run names its three steps, the optimizations a step of their own.
     */
    #[Test]
    public function test_console_names_the_three_steps(): void
    {
        $project = $this->makeProject(['src/Pattern.php' => "<?php\n\npreg_match('/^[a-z]+$/', \$subject);\n"]);

        $status = Artisan::call('regex:lint', $this->arguments($project.'/src'));
        $output = Artisan::output();

        $this->assertSame(0, $status, $output);
        $scanning = strpos($output, '[1/3] Scanning files');
        $analyzing = strpos($output, '[2/3] Analyzing patterns');
        $suggesting = strpos($output, '[3/3] Suggesting optimizations');
        $this->assertNotFalse($scanning, $output);
        $this->assertNotFalse($analyzing, $output);
        $this->assertNotFalse($suggesting, $output);
        $this->assertLessThan($analyzing, $scanning);
        $this->assertLessThan($suggesting, $analyzing);
        $this->assertStringNotContainsString('[2/2]', $output);
    }

    /**
     * A worker that ends without its results (a fatal error, out of memory,
     * killed) stops the run with exit code 1 and says so, naming the file
     * it was reading. The pids handed back are ones no system gives out
     * (above the largest pid_max): no worker runs, and its channel is
     * closed from the start, as when a worker is killed.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_console_failure_names_a_worker_lost_during_the_analysis(): void
    {
        $project = $this->makeProject(['src/Patterns.php' => self::TWO_PATTERNS]);
        LintFunctionOverrides::queuePcntlForkResult(2_000_000_001);
        LintFunctionOverrides::queuePcntlForkResult(2_000_000_002);

        $status = self::withinTimeLimit(fn (): int => Artisan::call('regex:lint', ['--jobs' => '2'] + $this->arguments($project.'/src')));
        $output = Artisan::output();

        $this->assertSame(1, $status, $output);
        $this->assertSame(1, substr_count($output, 'Parallel analysis failed: a worker exited unexpectedly while reading '), $output);
        $this->assertStringContainsString('Patterns.php', $output);
        $this->assertStringNotContainsString('Time:', $output, 'No footer: the run did not end.');
    }

    /**
     * Without pcntl no worker can start: the default the banner and the
     * footer show is one process, whatever the machine counts.
     */
    #[Test]
    public function test_console_defaults_to_one_process_without_pcntl(): void
    {
        $project = $this->makeProject(['src/Patterns.php' => self::TWO_PATTERNS]);
        LintFunctionOverrides::$missingFunctions = ['pcntl_fork'];
        $arguments = $this->arguments($project.'/src');
        unset($arguments['--jobs']);

        $status = Artisan::call('regex:lint', $arguments);
        $output = Artisan::output();

        $this->assertSame(0, $status, $output);
        $this->assertMatchesRegularExpression('/^Processes : 1$/m', $output);
        $this->assertMatchesRegularExpression('/\bProcesses: 1$/m', $output);
        // The workers parse the patterns: a cache count of this process
        // alone would read almost nothing on a parallel run.
        $this->assertMatchesRegularExpression('/^  Time: \S+ \| Memory: \S+ MB \| Processes: 1$/m', $output);
    }

    /**
     * Undecorated output (a CI log) gets no new line while the run waits:
     * the clock is redrawn only on a terminal. Each worker here waits 1.2 s
     * before its first pattern, so a bar redrawn while it waits would print
     * its "0/2" line again once the elapsed time reads one second
     * ("1 s" on recent Symfony releases, "1 sec" on older ones).
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_console_adds_no_line_while_waiting_on_an_undecorated_output(): void
    {
        $project = $this->makeProject(['src/Patterns.php' => self::TWO_PATTERNS]);
        $marker = $project.'/slow-worker-waited';
        $this->app?->instance('php-regex.cache', self::cacheSlowInWorkers($marker, 1_200_000));

        $startedAt = microtime(true);
        $status = Artisan::call('regex:lint', ['--jobs' => '2'] + $this->arguments($project.'/src'));
        $duration = microtime(true) - $startedAt;
        $output = Artisan::output();

        $this->assertSame(0, $status, $output);
        $this->assertGreaterThanOrEqual(1.2, $duration, 'The workers did not wait: nothing was tested.');
        $this->assertStringContainsString('[2/3] Analyzing patterns', $output);
        // Symfony 6.4 writes "< 1 sec" for no time at all, 7.x "< 1 ms".
        $this->assertDoesNotMatchRegularExpression('/\b0\/2\b.*(?:(?<!< )\b[1-9]\d* (?:s|secs?|min)\b|\b\d\d:0[1-9]\b)/', $output, $output);
    }

    /**
     * @param \Closure(self): void $breakRun
     */
    #[Test]
    #[DataProvider('provideStoppedRuns')]
    public function test_console_failure_prints_the_error_line(\Closure $breakRun, int $exitCode, string $error): void
    {
        $breakRun($this);

        $status = Artisan::call('regex:lint', $this->arguments('src'));

        $this->assertSame($exitCode, $status);
        $output = Artisan::output();
        $this->assertSame(1, substr_count($output, $error), $output);
        $this->assertNull(json_decode($output, true), $output);
    }

    /**
     * @return iterable<string, array{breakRun: \Closure(self): void, exitCode: int, error: string}>
     */
    public static function provideStoppedRuns(): iterable
    {
        yield 'a configuration the lint cannot use' => [
            'breakRun' => static function (self $test): void {
                config(['php-regex.php_version' => ['8.2']]);
            },
            'exitCode' => 2,
            'error' => 'Invalid config/php-regex.php: ',
        ];
        yield 'a collection that fails' => [
            'breakRun' => static function (self $test): void {
                $test->bindFailingCollection();
            },
            'exitCode' => 1,
            'error' => 'Failed to collect patterns: boom',
        ];
    }

    /**
     * A lint whose only source throws while collecting.
     */
    public function bindFailingCollection(): void
    {
        $failing = new class implements PatternSourceInterface {
            public function getName(): string
            {
                return 'fail';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $analysis = $this->app?->make('php-regex.analysis');
        $this->assertInstanceOf(AnalysisService::class, $analysis);
        $this->app?->instance('php-regex.lint', new LintService($analysis, new PatternSourceCollection([$failing])));
    }

    /**
     * A parse cache that, in a forked worker only, waits before its first
     * answer, then always misses. The marker file keeps the wait to the
     * first step that forks.
     */
    private static function cacheSlowInWorkers(string $marker, int $microseconds): CacheInterface
    {
        return new class(getmypid(), $marker, $microseconds) implements CacheInterface {
            public function __construct(
                private readonly int|false $parent,
                private readonly string $marker,
                private readonly int $microseconds,
            ) {}

            public function generateKey(string $seed): string
            {
                return hash('xxh128', $seed);
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): ?RegexNode
            {
                if (getmypid() !== $this->parent && !file_exists($this->marker)) {
                    usleep($this->microseconds);
                    @touch($this->marker);
                }

                return null;
            }
        };
    }

    /**
     * Runs the command, failing instead of hanging when it waits for a
     * worker that never answers.
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
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('php-regex.cache.directory', null);
        $app['config']->set('php-regex.cache.store', null);
        $app['config']->set('php-regex.runtime_pcre_validation', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(string $path): array
    {
        return [
            'paths' => [$path],
            '--format' => 'console',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ];
    }
}

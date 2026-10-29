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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Exception\LintException;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The engine the linter shares its work with: long-lived forked workers,
 * chunks handed out on demand, progress streamed back while they work, and
 * the results put back in the order of the items.
 *
 * Every run is under a deadline: a test that waits for a worker that will
 * never answer fails, it does not hang.
 */
#[RequiresPhpExtension('pcntl')]
final class ForkedWorkerPoolTest extends TestCase
{
    private const DEADLINE = 10;

    /**
     * Where the workers' tempnam() creates their payload files during the
     * test: a directory of its own, so files another process writes to the
     * shared temp directory at the same time are never counted.
     */
    private string $tempDirectory = '';

    /**
     * What the progress recorder received, in order.
     *
     * @var list<array{method: string, count: int|null, at: float, file: string|null}>
     */
    private array $events = [];

    protected function setUp(): void
    {
        LintFunctionOverrides::reset();
        $this->events = [];
        $this->tempDirectory = sys_get_temp_dir().'/regexparser_pool_test_'.bin2hex(random_bytes(6));
        mkdir($this->tempDirectory);
        LintFunctionOverrides::$tempnamDirectory = $this->tempDirectory;
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
        foreach ($this->tempFiles() as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDirectory);

        // A test that failed may leave a worker behind (a deadline hit
        // mid-run): reap it here, so the next test, whatever the order,
        // does not count it as its own.
        if (\function_exists('pcntl_waitpid')) {
            $until = microtime(true) + 1.0;
            while (-1 !== ($pid = pcntl_waitpid(-1, $status, \WNOHANG)) && ($pid > 0 || microtime(true) < $until)) {
                if (0 === $pid) {
                    usleep(10_000);
                }
            }
        }
    }

    /**
     * The chunks holding the first items sleep the longest, so they finish
     * last; the results still come back in the order of the items. Three
     * workers and six chunks: the chunks are handed out as workers free up.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_returns_the_results_in_input_order_whatever_the_completion_order(): void
    {
        $items = range(0, 11);
        $pool = new ForkedWorkerPool(chunkFactor: 1, maxChunkSize: 2);

        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => $pool->run(
            $items,
            3,
            /** @param list<int> $chunk */
            static function (array $chunk, \Closure $done): array {
                usleep((11 - $chunk[0]) * 15_000);
                $done(\count($chunk));

                return array_map(static fn (int $item): string => 'r'.$item, $chunk);
            },
            null,
            'Parallel analysis failed',
            [],
            static fn (int $item): string => 'file'.$item.'.php',
        ));

        $this->assertSame(array_map(static fn (int $item): string => 'r'.$item, $items), $results);
        $this->assertNothingLeftBehind();
    }

    /**
     * What a worker gives comes back through unserialize(), which rebuilds
     * the classes it is allowed to.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_rebuilds_the_allowed_classes_of_the_results(): void
    {
        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
            ['2024-01-01', '2025-06-15', '2026-10-13'],
            2,
            /** @param list<string> $chunk */
            static fn (array $chunk, \Closure $done): array => array_map(static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day.' 00:00:00 UTC'), $chunk),
            null,
            'Parallel analysis failed',
            [\DateTimeImmutable::class],
            static fn (string $day): string => $day,
        ));

        $this->assertEquals([
            new \DateTimeImmutable('2024-01-01 00:00:00 UTC'),
            new \DateTimeImmutable('2025-06-15 00:00:00 UTC'),
            new \DateTimeImmutable('2026-10-13 00:00:00 UTC'),
        ], $results);
        $this->assertNothingLeftBehind();
    }

    /**
     * One worker sleeps on the first chunk; the other works through the
     * rest. Its progress shows up before the slow chunk ends, not after the
     * last worker is reaped, and every item is counted exactly once.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_reports_the_progress_of_every_item_as_it_is_done(): void
    {
        $pool = new ForkedWorkerPool(chunkFactor: 1, maxChunkSize: 1);
        $progress = $this->recorder();
        $start = microtime(true);

        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => $pool->run(
            range(0, 5),
            2,
            static function (array $chunk, \Closure $done): array {
                foreach ($chunk as $item) {
                    // Long enough that forking the process running the
                    // whole suite, a second on the macOS runners, ends first.
                    if (0 === $item) {
                        usleep(3_000_000);
                    }
                    $done(1);
                }

                return $chunk;
            },
            $progress,
            'Parallel analysis failed',
            [],
            static fn (int $item): string => 'file'.$item.'.php',
        ));

        $this->assertSame(range(0, 5), $results);
        $advances = $this->calls('advance');
        $this->assertNotSame([], $advances);
        $this->assertSame(6, array_sum(array_map(static fn (array $call): int => (int) $call['count'], $advances)));
        $this->assertLessThan($start + 3.0, $advances[0]['at'], 'The first progress waited for the slowest chunk.');
    }

    /**
     * Nothing to report for 300 ms: the clock is still redrawn.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_ticks_the_clock_while_a_worker_is_silent(): void
    {
        $progress = $this->recorder();

        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
            ['a', 'b'],
            2,
            static function (array $chunk, \Closure $done): array {
                usleep(300_000);
                $done(\count($chunk));

                return $chunk;
            },
            $progress,
            'Parallel analysis failed',
            [],
            static fn (string $item): string => $item.'.php',
        ));

        $this->assertSame(['a', 'b'], $results);
        $ticks = $this->calls('tick');
        $advances = $this->calls('advance');
        $this->assertNotSame([], $ticks, 'The clock was never redrawn while the workers slept.');
        $this->assertNotSame([], $advances);
        $this->assertLessThan($advances[0]['at'], $ticks[0]['at'], 'No tick before the first progress.');
    }

    /**
     * No worker reports for a while: the progress is told the first file of
     * the chunk in flight the longest, once, so that a pattern holding the
     * run is named while it holds it.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_names_the_file_a_silent_worker_is_on(): void
    {
        $progress = $this->recorder();
        $pool = new ForkedWorkerPool(chunkFactor: 1, maxChunkSize: 1, stallAfter: 0.2);

        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => $pool->run(
            ['fast', 'slow'],
            2,
            static function (array $chunk, \Closure $done): array {
                if (['slow'] === $chunk) {
                    usleep(700_000);
                }
                $done(\count($chunk));

                return $chunk;
            },
            $progress,
            'Parallel analysis failed',
            [],
            static fn (string $item): string => $item.'.php',
        ));

        $this->assertSame(['fast', 'slow'], $results);
        $stalls = $this->calls('stalled');
        // A busy machine may start the workers slowly enough that the fast
        // one is named first, before its report; the slow one is named once,
        // last, while it holds the run.
        $this->assertContains(array_column($stalls, 'file'), [['slow.php'], ['fast.php', 'slow.php']]);
        $this->assertCount(1, array_keys(array_column($stalls, 'file'), 'slow.php', true));
        $this->assertNotSame([], $stalls);
        $last = $stalls[\count($stalls) - 1];
        $this->assertSame('slow.php', $last['file']);
        $this->assertSame(0, $last['count'], 'Under a second without a report.');
    }

    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_names_no_file_while_the_workers_report(): void
    {
        $progress = $this->recorder();

        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool(chunkFactor: 1, maxChunkSize: 1))->run(
            ['a', 'b'],
            2,
            static function (array $chunk, \Closure $done): array {
                usleep(300_000);
                $done(\count($chunk));

                return $chunk;
            },
            $progress,
            'Parallel analysis failed',
            [],
            static fn (string $item): string => $item.'.php',
        ));

        $this->assertSame(['a', 'b'], $results);
        $this->assertSame([], $this->calls('stalled'));
    }

    /**
     * @return iterable<string, array{items: int, workers: int, started: int}>
     */
    public static function provideStartedWorkers(): iterable
    {
        yield 'more workers than chunks' => ['items' => 3, 'workers' => 8, 'started' => 3];
        yield 'more chunks than workers' => ['items' => 10, 'workers' => 2, 'started' => 2];
    }

    #[Test]
    #[RequiresFunction('pcntl_fork')]
    #[DataProvider('provideStartedWorkers')]
    public function test_run_tells_the_progress_how_many_workers_it_started(int $items, int $workers, int $started): void
    {
        $progress = $this->recorder();
        $input = range(1, $items);

        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
            $input,
            $workers,
            /** @param list<int> $chunk */
            static fn (array $chunk, \Closure $done): array => array_map(static fn (int $item): int => $item * 2, $chunk),
            $progress,
            'Parallel analysis failed',
            [],
            static fn (int $item): string => 'file'.$item.'.php',
        ));

        $this->assertSame(array_map(static fn (int $item): int => $item * 2, $input), $results);
        $this->assertSame([$started], array_map(static fn (array $call): ?int => $call['count'], $this->calls('workers')));
        $this->assertNothingLeftBehind();
    }

    /**
     * The callers run a single item themselves; the engine still gives the
     * right result when it gets one.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_with_a_single_item_returns_its_result(): void
    {
        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
            ['only'],
            4,
            /** @param list<string> $chunk */
            static fn (array $chunk, \Closure $done): array => array_map(strtoupper(...), $chunk),
            null,
            'Parallel analysis failed',
            [],
            static fn (string $item): string => $item.'.php',
        ));

        $this->assertSame(['ONLY'], $results);
        $this->assertNothingLeftBehind();
    }

    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_without_items_returns_no_results(): void
    {
        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
            [],
            4,
            static fn (array $chunk, \Closure $done): array => $chunk,
            null,
            'Parallel analysis failed',
            [],
            static fn (string $item): string => $item,
        ));

        $this->assertSame([], $results);
        $this->assertNothingLeftBehind();
    }

    /**
     * Ten items, two workers, five items per chunk: x.php:1 x.php:2 y.php:1
     * z.php:1 y.php:2, then five lines of a second file. The message names
     * the first file of the chunk that failed and counts the other distinct
     * files in it.
     *
     * @return iterable<string, array{second: string, prefix: string, failOn: string, message: string}>
     */
    public static function provideWorkerFailure(): iterable
    {
        yield 'chunk spanning three files' => [
            'second' => 'w.php',
            'prefix' => 'Parallel analysis failed',
            'failOn' => 'y.php:1',
            'message' => 'Parallel analysis failed: RuntimeException: Boom (in x.php and 2 more)',
        ];

        yield 'chunk of a single file' => [
            'second' => 'w.php',
            'prefix' => 'Parallel collection failed',
            'failOn' => 'w.php:3',
            'message' => 'Parallel collection failed: RuntimeException: Boom (in w.php)',
        ];

        yield 'non-ASCII file name, kept as it is' => [
            'second' => 'données/éxpressions.php',
            'prefix' => 'Parallel analysis failed',
            'failOn' => 'données/éxpressions.php:2',
            'message' => 'Parallel analysis failed: RuntimeException: Boom (in données/éxpressions.php)',
        ];
    }

    #[Test]
    #[RequiresFunction('pcntl_fork')]
    #[DataProvider('provideWorkerFailure')]
    public function test_run_reports_a_worker_that_threw_with_the_files_of_its_chunk(string $second, string $prefix, string $failOn, string $message): void
    {
        $pool = new ForkedWorkerPool(chunkFactor: 1);
        $items = $this->failureItems($second);

        $error = $this->failureOf(static fn (): ?array => $pool->run(
            $items,
            2,
            static function (array $chunk, \Closure $done) use ($failOn): array {
                if (\in_array($failOn, $chunk, true)) {
                    throw new \RuntimeException('Boom');
                }
                $done(\count($chunk));

                return $chunk;
            },
            null,
            $prefix,
            [],
            self::fileOf(...),
        ));

        $this->assertSame($message, $error->getMessage());
        $this->assertNothingLeftBehind();
    }

    /**
     * @return iterable<string, array{second: string, failOn: string, message: string}>
     */
    public static function provideWorkerDeath(): iterable
    {
        yield 'chunk spanning three files' => [
            'second' => 'w.php',
            'failOn' => 'y.php:1',
            'message' => 'Parallel analysis failed: a worker exited unexpectedly while reading x.php and 2 more',
        ];

        yield 'chunk of a single file' => [
            'second' => 'w.php',
            'failOn' => 'w.php:3',
            'message' => 'Parallel analysis failed: a worker exited unexpectedly while reading w.php',
        ];

        yield 'non-ASCII file name, kept as it is' => [
            'second' => 'données/éxpressions.php',
            'failOn' => 'données/éxpressions.php:2',
            'message' => 'Parallel analysis failed: a worker exited unexpectedly while reading données/éxpressions.php',
        ];
    }

    /**
     * A worker that dies mid-chunk (a fatal error, the OOM killer, SIGKILL)
     * sends no payload: the message names the files it was reading.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    #[RequiresFunction('posix_kill')]
    #[DataProvider('provideWorkerDeath')]
    public function test_run_reports_a_worker_that_died_with_the_files_of_its_chunk(string $second, string $failOn, string $message): void
    {
        $pool = new ForkedWorkerPool(chunkFactor: 1);
        $items = $this->failureItems($second);

        $error = $this->failureOf(static fn (): ?array => $pool->run(
            $items,
            2,
            static function (array $chunk, \Closure $done) use ($failOn): array {
                if (\in_array($failOn, $chunk, true)) {
                    posix_kill(posix_getpid(), \SIGKILL);
                }
                $done(\count($chunk));

                return $chunk;
            },
            null,
            'Parallel analysis failed',
            [],
            self::fileOf(...),
        ));

        $this->assertSame($message, $error->getMessage());
        $this->assertNothingLeftBehind();
    }

    /**
     * A worker writes its payload file only once told to stop. When it
     * cannot create one, its results cannot reach the parent: the run
     * fails, and leaves nothing behind.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_fails_when_a_worker_cannot_create_its_payload_file(): void
    {
        // Each forked worker inherits this queue: its own tempnam() fails.
        // It also proves the workers create their payload through the
        // tempnam() this test watches.
        LintFunctionOverrides::queueTempnam(false);

        $error = $this->failureOf(static fn (): ?array => (new ForkedWorkerPool())->run(
            ['a', 'b'],
            2,
            static fn (array $chunk, \Closure $done): array => $chunk,
            null,
            'Parallel analysis failed',
            [],
            static fn (string $item): string => $item.'.php',
        ));

        $this->assertStringStartsWith('Parallel analysis failed: ', $error->getMessage());
        $this->assertNothingLeftBehind();
    }

    /**
     * A worker that names a payload file its parent cannot read (written
     * nowhere: its directory does not exist) hands back nothing the parent
     * can trust: the run fails, rather than return no results for its
     * chunks, and leaves nothing behind.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_fails_when_a_worker_payload_cannot_be_read(): void
    {
        // Each forked worker inherits this queue: its own tempnam() gives a
        // path no file can be written to, and sends it all the same.
        LintFunctionOverrides::queueTempnam($this->tempDirectory.'/missing/regexparser_payload');

        $error = $this->failureOf(static fn (): ?array => (new ForkedWorkerPool())->run(
            ['a', 'b'],
            2,
            static fn (array $chunk, \Closure $done): array => $chunk,
            null,
            'Parallel analysis failed',
            [],
            static fn (string $item): string => $item.'.php',
        ));

        $this->assertSame('Parallel analysis failed: Invalid worker output.', $error->getMessage());
        $this->assertNothingLeftBehind();
    }

    /**
     * A chunk gives a list of results; anything else cannot be put back in
     * the order of the items, so the run fails rather than drop it.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_rejects_a_chunk_result_that_is_not_a_list(): void
    {
        $error = $this->failureOf(static fn (): ?array => (new ForkedWorkerPool())->run(
            ['a', 'b'],
            2,
            static fn (array $chunk, \Closure $done): string => 'not a list',
            null,
            'Parallel collection failed',
            [],
            static fn (string $item): string => $item.'.php',
        ));

        $this->assertSame('Parallel collection failed: Invalid worker output.', $error->getMessage());
        $this->assertNothingLeftBehind();
    }

    /**
     * What the progress throws stops the run as it is; the workers are
     * still reaped and their payload files removed.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_leaves_nothing_behind_when_the_progress_throws(): void
    {
        $progress = new class implements LintProgress {
            public function step(string $step, ?int $total): void {}

            public function advance(int $count = 1): void
            {
                throw new \LogicException('The display failed.');
            }

            public function tick(): void {}

            public function workers(int $count): void {}

            public function stalled(string $file, int $seconds): void {}

            public function finish(): void {}
        };

        try {
            $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
                range(1, 6),
                3,
                static function (array $chunk, \Closure $done): array {
                    $done(\count($chunk));

                    return $chunk;
                },
                $progress,
                'Parallel analysis failed',
                [],
                static fn (int $item): string => 'file'.$item.'.php',
            ));
            $this->fail('The run did not fail.');
        } catch (\LogicException $e) {
            $this->assertSame('The display failed.', $e->getMessage());
        }

        $this->assertNothingLeftBehind();
    }

    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_returns_null_when_no_channel_can_be_opened(): void
    {
        LintFunctionOverrides::queueStreamSocketPair(false);

        $results = $this->withDeadline(self::DEADLINE, $this->runOfSixItems(...));

        $this->assertNull($results);
        $this->assertNothingLeftBehind();
    }

    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_returns_null_when_the_first_fork_fails(): void
    {
        LintFunctionOverrides::queuePcntlForkResult(-1);

        $results = $this->withDeadline(self::DEADLINE, $this->runOfSixItems(...));

        $this->assertNull($results);
        $this->assertNothingLeftBehind();
    }

    /**
     * One worker really started, then the second fork fails: the run gives
     * up (the caller goes serial) and reaps the worker it started.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_returns_null_and_reaps_the_started_worker_when_a_later_fork_fails(): void
    {
        LintFunctionOverrides::$pcntlForkFailAfter = 1;

        $results = $this->withDeadline(self::DEADLINE, $this->runOfSixItems(...));

        $this->assertNull($results);
        $this->assertNothingLeftBehind();
    }

    /**
     * A worker sent SIGTERM the moment it is forked (the next fork failed,
     * the run ends) leaves its parent alive. PHP's own handler re-raises
     * the signal with kill(getpid()), and on macOS a process just forked
     * can still read its parent's pid there: the parent died with it, one
     * run in a few dozen. Run in a PHP process of its own, many times.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_a_worker_ended_as_it_starts_leaves_its_parent_alive(): void
    {
        $script = \sprintf(<<<'PHP_WRAP'
            require %s;
            for ($run = 0; $run < 150; $run++) {
                PHPRegex\Tests\Support\LintFunctionOverrides::reset();
                PHPRegex\Tests\Support\LintFunctionOverrides::$pcntlForkFailAfter = 1;
                $results = (new PHPRegex\Linter\Parallel\ForkedWorkerPool())->run(
                    [1, 2],
                    2,
                    static fn (array $chunk, Closure $done): array => $chunk,
                    null,
                    'Parallel analysis failed',
                    [],
                    static fn (int $item): string => 'file'.$item.'.php',
                );
                if (null !== $results) {
                    echo 'the run did not give up';

                    exit(1);
                }
            }
            echo 'alive';
            PHP_WRAP, var_export(\dirname(__DIR__, 2).'/bootstrap.php', true));

        $this->assertSame('alive', $this->runPhp($script));
    }

    /**
     * The first "worker" is a process id no process has: it never answers
     * on its channel. When the next fork fails the run gives up at once,
     * without waiting for it.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_does_not_wait_for_a_silent_worker_when_a_later_fork_fails(): void
    {
        LintFunctionOverrides::queuePcntlForkResult(99_999_901);
        LintFunctionOverrides::queuePcntlForkResult(-1);
        $start = microtime(true);

        $results = $this->withDeadline(3, $this->runOfSixItems(...));

        $this->assertNull($results);
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertNothingLeftBehind();
    }

    /**
     * @return iterable<string, array{function: string}>
     */
    public static function provideForkFunctions(): iterable
    {
        yield 'pcntl_fork missing' => ['function' => 'pcntl_fork'];
        yield 'pcntl_waitpid missing' => ['function' => 'pcntl_waitpid'];
        yield 'stream_socket_pair missing' => ['function' => 'stream_socket_pair'];
        yield 'stream_select missing' => ['function' => 'stream_select'];
        yield 'pcntl_signal missing' => ['function' => 'pcntl_signal'];
        yield 'pcntl_sigprocmask missing' => ['function' => 'pcntl_sigprocmask'];
    }

    #[Test]
    #[DataProvider('provideForkFunctions')]
    public function test_can_fork_is_false_when_a_function_it_needs_is_missing(string $function): void
    {
        LintFunctionOverrides::$missingFunctions = [$function];

        $this->assertFalse(ForkedWorkerPool::canFork());
    }

    #[Test]
    #[RequiresFunction('pcntl_fork')]
    #[RequiresFunction('pcntl_waitpid')]
    public function test_can_fork_is_true_when_everything_it_needs_is_available(): void
    {
        $this->assertTrue(ForkedWorkerPool::canFork());
    }

    /**
     * A worker inherits the output buffers of its parent: it drops them, so
     * what the parent buffered is not written once more when the worker
     * ends. Run in a PHP process of its own, where no test support stands
     * in for a function.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_a_worker_does_not_write_out_what_its_parent_buffered(): void
    {
        $script = \sprintf(<<<'PHP_WRAP'
            require %s;
            ob_start();
            echo "buffered by the parent";
            $results = (new PHPRegex\Linter\Parallel\ForkedWorkerPool())->run(
                [1, 2],
                2,
                static fn (array $chunk, Closure $done): array => $chunk,
                null,
                'Parallel analysis failed',
                [],
                static fn (int $item): string => 'file'.$item.'.php',
            );
            ob_end_clean();
            echo serialize($results);
            PHP_WRAP, var_export($this->autoload(), true));

        $this->assertSame(serialize([1, 2]), $this->runPhp($script));
    }

    /**
     * A worker ends without running what its parent registered to run at
     * shutdown, nor its destructors: those belong to the parent process (a
     * logger flush, a connection close), and loading the classes they need
     * from a phar in several workers at once reads corrupted bytes (the
     * suite saw "Unterminated comment" in a PHPStan class).
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_a_worker_does_not_run_its_parents_shutdown_functions(): void
    {
        $marker = sys_get_temp_dir().'/regexparser_pool_marker_'.uniqid('', true);
        $script = \sprintf(<<<'PHP_WRAP'
            require %s;
            $parent = getmypid();
            register_shutdown_function(static function () use ($parent): void {
                if (getmypid() !== $parent) {
                    file_put_contents(%s, 'shutdown ran in a worker', FILE_APPEND);
                }
            });
            $guard = new class($parent) {
                public function __construct(private int $parent) {}
                public function __destruct() { if (getmypid() !== $this->parent) { file_put_contents(%2$s, 'destructor ran in a worker', FILE_APPEND); } }
            };
            $results = (new PHPRegex\Linter\Parallel\ForkedWorkerPool())->run(
                [1, 2],
                2,
                static fn (array $chunk, Closure $done): array => $chunk,
                null,
                'Parallel analysis failed',
                [],
                static fn (int $item): string => 'file'.$item.'.php',
            );
            echo serialize($results);
            PHP_WRAP, var_export($this->autoload(), true), var_export($marker, true));

        $stdout = $this->runPhp($script);
        $ran = is_file($marker);
        @unlink($marker);

        $this->assertSame(serialize([1, 2]), $stdout, 'The workers still hand their results back.');
        $this->assertFalse($ran, 'A shutdown function or a destructor of the parent ran in a worker.');
    }

    /**
     * A phar the parent holds open (a Phar object, a stream on one of its
     * files) is read through one descriptor that every worker inherits,
     * with one file offset for all: workers loading its classes at once
     * moved each other's offset and compiled each other's bytes, and died
     * on a parse error (the suite saw it on macOS with PHPStan's phar
     * loaded before a parallel lint). Each worker loads every class of the
     * phar here, in an order of its own.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    #[RequiresPhpExtension('phar')]
    public function test_workers_load_classes_from_a_phar_their_parent_holds_open(): void
    {
        $directory = sys_get_temp_dir().'/regexparser_pool_phar_'.bin2hex(random_bytes(6));
        mkdir($directory);
        $script = \sprintf(<<<'PHP_WRAP'
            require %s;
            $path = %s.'/classes.phar';
            $phar = new Phar($path);
            $phar->startBuffering();
            for ($i = 0; $i < 24; $i++) {
                $phar->addFromString('C'.$i.'.php', "<?php\nnamespace PharClasses;\n/*".str_repeat('x', 32 * 1024)."*/\nfinal class C".$i." { public const ID = ".$i."; }\n");
            }
            $phar->setStub('<?php __HALT_COMPILER();');
            $phar->stopBuffering();
            spl_autoload_register(static function (string $class) use ($path): void {
                if (str_starts_with($class, 'PharClasses\\')) {
                    require 'phar://'.$path.'/'.substr($class, 12).'.php';
                }
            });
            $held = fopen('phar://'.$path.'/C0.php', 'r');
            try {
                echo serialize((new PHPRegex\Linter\Parallel\ForkedWorkerPool())->run(
                    [0, 1, 2, 3],
                    4,
                    static function (array $chunk, Closure $done): array {
                        $ids = [];
                        for ($i = 0; $i < 24; $i++) {
                            $ids[] = ('PharClasses\\C'.(($i + 7 * $chunk[0]) %% 24))::ID;
                        }
                        sort($ids);

                        return [$ids === range(0, 23)];
                    },
                    null,
                    'Parallel analysis failed',
                    [],
                    static fn (int $item): string => 'file'.$item.'.php',
                ));
            } catch (PHPRegex\Linter\Exception\LintException $e) {
                echo substr($e->getMessage(), 0, 200);
            }
            PHP_WRAP, var_export($this->autoload(), true), var_export($directory, true));

        try {
            $stdout = $this->runPhp($script, ['phar.readonly=0', 'sys_temp_dir='.$directory]);
            $left = glob($directory.'/*') ?: [];
        } finally {
            @unlink($directory.'/classes.phar');
            @rmdir($directory);
        }

        $this->assertSame(serialize([true, true, true, true]), $stdout);
        $this->assertSame([$directory.'/classes.phar'], $left, 'The run left a file behind in the temp directory.');
    }

    /**
     * The parent loads its classes under the same lock while its workers
     * run: a class and its parent class, loaded one inside the other, and a
     * class no loader knows. Once the run is over, its class loaders are
     * the ones it had.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_the_parent_loads_classes_while_its_workers_run(): void
    {
        $namespace = 'PHPRegex\Tests\Unit\Lint\Loaded'.bin2hex(random_bytes(6));
        $definitions = [
            $namespace.'\Child' => 'class Child extends Base {}',
            $namespace.'\Base' => 'class Base {}',
        ];
        $define = static function (string $class) use ($namespace, $definitions): void {
            if (isset($definitions[$class])) {
                eval('namespace '.$namespace.'; '.$definitions[$class]);
            }
        };
        spl_autoload_register($define);
        $before = spl_autoload_functions();
        $found = [];

        try {
            $progress = new class(static function () use ($namespace, &$found): void {
                $found = [class_exists($namespace.'\Child'), class_exists($namespace.'\Missing')];
            }) implements LintProgress {

                /**
                 * @param \Closure(): void $onWorkers
                 */
                public function __construct(private readonly \Closure $onWorkers) {}

                public function step(string $step, ?int $total): void {}

                public function advance(int $count = 1): void {}

                public function tick(): void {}

                public function workers(int $count): void
                {
                    ($this->onWorkers)();
                }

                public function stalled(string $file, int $seconds): void {}

                public function finish(): void {}
            };

            $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
                [1, 2],
                2,
                static fn (array $chunk, \Closure $done): array => $chunk,
                $progress,
                'Parallel analysis failed',
                [],
                static fn (int $item): string => 'file'.$item.'.php',
            ));
            $after = spl_autoload_functions();
        } finally {
            spl_autoload_unregister($define);
        }

        $this->assertSame([1, 2], $results);
        $this->assertSame([true, false], $found, 'The class and its parent load, the unknown class does not.');
        $this->assertSame(get_parent_class($namespace.'\Child'), $namespace.'\Base');
        $this->assertSame($before, $after, 'The run left a class loader of its own behind.');
        $this->assertNothingLeftBehind();
    }

    /**
     * Without a lock file (the temp directory is full or read-only) the run
     * goes on, its classes loaded as they come.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_goes_on_without_the_lock_file(): void
    {
        LintFunctionOverrides::$lockFileFails = true;
        $before = spl_autoload_functions();

        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
            [1, 2, 3],
            2,
            static fn (array $chunk, \Closure $done): array => $chunk,
            null,
            'Parallel analysis failed',
            [],
            static fn (int $item): string => 'file'.$item.'.php',
        ));

        $this->assertSame([1, 2, 3], $results);
        $this->assertSame($before, spl_autoload_functions());
        $this->assertNothingLeftBehind();
    }

    /**
     * A failure ends the run without waiting for a busy worker to finish its
     * chunk, whatever the parent does with SIGTERM: a handler of its own is
     * not the worker's, and a SIGTERM the parent blocks is let through in
     * the worker.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    #[DataProvider('provideParentSigterm')]
    public function test_run_ends_a_busy_worker_whatever_the_parent_does_with_sigterm(string $parent): void
    {
        $async = pcntl_async_signals();
        if ('handles' === $parent) {
            pcntl_async_signals(true);
            pcntl_signal(\SIGTERM, static function (): void {});
        } else {
            pcntl_sigprocmask(\SIG_BLOCK, [\SIGTERM]);
        }
        $pool = new ForkedWorkerPool(chunkFactor: 1);
        $started = microtime(true);

        try {
            $error = $this->failureOf(static fn (): ?array => $pool->run(
                ['fails', 'busy'],
                2,
                static function (array $chunk, \Closure $done): array {
                    if (['fails'] === $chunk) {
                        usleep(200_000);

                        throw new \RuntimeException('Boom');
                    }
                    sleep(4);
                    $done(\count($chunk));

                    return $chunk;
                },
                null,
                'Parallel analysis failed',
                [],
                static fn (string $item): string => $item.'.php',
            ));
        } finally {
            pcntl_sigprocmask(\SIG_UNBLOCK, [\SIGTERM]);
            pcntl_signal(\SIGTERM, \SIG_DFL);
            pcntl_async_signals($async);
        }

        $this->assertSame('Parallel analysis failed: RuntimeException: Boom (in fails.php)', $error->getMessage());
        $this->assertLessThan(3.0, microtime(true) - $started, 'The run waited for the busy worker.');
        $this->assertNothingLeftBehind();
    }

    /**
     * @return iterable<string, array{parent: string}>
     */
    public static function provideParentSigterm(): iterable
    {
        yield 'the parent handles SIGTERM' => ['parent' => 'handles'];
        yield 'the parent blocks SIGTERM' => ['parent' => 'blocks'];
    }

    /**
     * A worker told to stop may be slow to write its payload: the parent,
     * ending the run on another worker's failure, still reads its name past
     * the socket timeout, and removes the file.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_reads_a_slow_payload_name_past_the_socket_timeout(): void
    {
        $timeout = (string) \ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '1');
        $pool = new ForkedWorkerPool(chunkFactor: 1);

        try {
            $error = $this->failureOf(static fn (): ?array => $pool->run(
                ['fails', 'slow'],
                2,
                static function (array $chunk, \Closure $done): array {
                    if (['fails'] === $chunk) {
                        usleep(300_000);

                        throw new \RuntimeException('Boom');
                    }
                    // Its payload file, written once it is told to stop,
                    // takes longer than the socket timeout to name.
                    LintFunctionOverrides::$tempnamDelayMicroseconds = 2_000_000;
                    $done(\count($chunk));

                    return $chunk;
                },
                null,
                'Parallel analysis failed',
                [],
                static fn (string $item): string => $item.'.php',
            ));
        } finally {
            ini_set('default_socket_timeout', $timeout);
        }

        $this->assertSame('Parallel analysis failed: RuntimeException: Boom (in fails.php)', $error->getMessage());
        $this->assertNothingLeftBehind();
    }

    /**
     * A process with many files open (a long-lived host, an IDE plugin)
     * opens the channels past FD_SETSIZE (1024), which select() cannot
     * watch: stream_select() gives false on every call. The run must still
     * end, with the results or with null (the caller then runs serially),
     * not spin for ever counting each failed wait as a silent one.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    #[RequiresFunction('posix_setrlimit')]
    public function test_run_does_not_spin_when_the_channels_cannot_be_selected(): void
    {
        $limit = $this->raiseOpenFileLimit(2048);
        $handles = [];

        try {
            for ($i = 0; $i < 1100; $i++) {
                $handle = @fopen(__FILE__, 'r');
                if (false === $handle) {
                    break;
                }
                $handles[] = $handle;
            }
            $this->assertCount(1100, $handles, 'Not enough files could be opened to push the channels past FD_SETSIZE.');

            $results = $this->withDeadline(5, static fn (): ?array => (new ForkedWorkerPool())->run(
                range(0, 9),
                2,
                static fn (array $chunk, \Closure $done): array => $chunk,
                null,
                'Parallel analysis failed',
                [],
                static fn (int $item): string => 'file'.$item.'.php',
            ));
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
            $this->restoreOpenFileLimit($limit);
        }

        $this->assertContains($results, [range(0, 9), null]);
        $this->assertNothingLeftBehind();
    }

    /**
     * The parent stalls on its first progress redraw longer than
     * default_socket_timeout (a terminal paused with Ctrl-S, a full stderr
     * pipe). A worker waiting for its next chunk must keep waiting: a
     * channel read that times out is not the parent gone.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_survives_a_parent_stalled_longer_than_the_socket_timeout(): void
    {
        $progress = new class implements LintProgress {
            private bool $stalled = false;

            public function step(string $step, ?int $total): void {}

            public function advance(int $count = 1): void
            {
                if (!$this->stalled) {
                    $this->stalled = true;
                    sleep(2);
                }
            }

            public function tick(): void {}

            public function workers(int $count): void {}

            public function stalled(string $file, int $seconds): void {}

            public function finish(): void {}
        };
        $timeout = ini_set('default_socket_timeout', '1');

        try {
            $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool(chunkFactor: 1, maxChunkSize: 5))->run(
                range(0, 99),
                2,
                static function (array $chunk, \Closure $done): array {
                    usleep(10_000);
                    $done(\count($chunk));

                    return $chunk;
                },
                $progress,
                'Parallel analysis failed',
                [],
                static fn (int $item): string => 'file'.$item.'.php',
            ));
        } finally {
            ini_set('default_socket_timeout', false === $timeout ? '60' : $timeout);
        }

        $this->assertSame(range(0, 99), $results);
        $this->assertNothingLeftBehind();
    }

    /**
     * Every chunk throws. The first worker hands its failure back at once;
     * the others are slower to write their payload (a slow disk): each has
     * created its file and not named it yet when the run fails on the
     * first. Their files are still removed.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_leaves_no_payload_file_when_several_workers_fail_at_once(): void
    {
        $error = $this->failureOf(static fn (): ?array => (new ForkedWorkerPool(chunkFactor: 1, maxChunkSize: 2))->run(
            range(0, 7),
            4,
            self::workThatThrowsOnEveryChunk(),
            null,
            'Parallel analysis failed',
            [],
            static fn (int $item): string => 'file'.$item.'.php',
        ));

        $this->assertSame('Parallel analysis failed: RuntimeException: Boom (in file0.php and 1 more)', $error->getMessage());
        $this->assertNothingLeftBehind();
    }

    /**
     * A worker count no machine has (a typo, a value read from elsewhere)
     * times the chunk factor no longer fits an int: the run still shares
     * the items out, one worker per chunk at most.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_run_with_more_workers_than_an_int_can_multiply(): void
    {
        // Four items, so four workers at most: ten forks of the process
        // running the whole suite outlast the deadline on the macOS runners.
        $results = $this->withDeadline(self::DEADLINE, static fn (): ?array => (new ForkedWorkerPool())->run(
            range(0, 3),
            \PHP_INT_MAX,
            static fn (array $chunk, \Closure $done): array => $chunk,
            null,
            'Parallel analysis failed',
            [],
            static fn (int $item): string => 'file'.$item.'.php',
        ));

        $this->assertSame(range(0, 3), $results);
        $this->assertNothingLeftBehind();
    }

    /**
     * Throws on every chunk: the one holding item 0 after 200 ms, by when
     * the others are writing their payload file; the others at once, with
     * a slow tempnam() in their own worker.
     *
     * @return \Closure(list<int>, \Closure(int): void): list<int>
     */
    private static function workThatThrowsOnEveryChunk(): \Closure
    {
        return static function (array $chunk, \Closure $done): array {
            if (\in_array(0, $chunk, true)) {
                usleep(200_000);
            } else {
                // Set in this worker only.
                LintFunctionOverrides::$tempnamDelayMicroseconds = 1_000_000;
            }

            throw new \RuntimeException('Boom');
        };
    }

    /**
     * Raises the soft limit of open files to at least $wanted when the hard
     * limit allows it; the limit it found, to restore, or null.
     */
    private function raiseOpenFileLimit(int $wanted): ?int
    {
        if (!\function_exists('posix_getrlimit') || !\function_exists('posix_setrlimit')) {
            return null;
        }

        $limits = posix_getrlimit();
        $soft = \is_array($limits) ? $limits['soft openfiles'] ?? null : null;
        $hard = \is_array($limits) ? $limits['hard openfiles'] ?? null : null;
        if (!is_numeric($soft) || (int) $soft >= $wanted) {
            return null;
        }

        // The hard limit is kept as it is: lowering it cannot be undone.
        $ceiling = is_numeric($hard) ? (int) $hard : \POSIX_RLIMIT_INFINITY;
        posix_setrlimit(\POSIX_RLIMIT_NOFILE, is_numeric($hard) ? min($wanted, $ceiling) : $wanted, $ceiling);

        return (int) $soft;
    }

    private function restoreOpenFileLimit(?int $soft): void
    {
        if (null === $soft) {
            return;
        }

        $limits = posix_getrlimit();
        $hard = \is_array($limits) ? $limits['hard openfiles'] ?? null : null;
        posix_setrlimit(\POSIX_RLIMIT_NOFILE, $soft, is_numeric($hard) ? (int) $hard : \POSIX_RLIMIT_INFINITY);
    }

    /**
     * @return list<int>|null
     */
    private function runOfSixItems(): ?array
    {
        return (new ForkedWorkerPool())->run(
            range(1, 6),
            3,
            static fn (array $chunk, \Closure $done): array => $chunk,
            null,
            'Parallel analysis failed',
            [],
            static fn (int $item): string => 'file'.$item.'.php',
        );
    }

    /**
     * @return list<string>
     */
    private function failureItems(string $second): array
    {
        return [
            'x.php:1', 'x.php:2', 'y.php:1', 'z.php:1', 'y.php:2',
            $second.':1', $second.':2', $second.':3', $second.':4', $second.':5',
        ];
    }

    private static function fileOf(string $item): string
    {
        return substr($item, 0, (int) strrpos($item, ':'));
    }

    /**
     * @param \Closure(): mixed $run
     */
    private function failureOf(\Closure $run): LintException
    {
        try {
            $this->withDeadline(self::DEADLINE, $run);
        } catch (LintException $e) {
            return $e;
        }

        $this->fail('The run did not fail.');
    }

    /**
     * Runs under an alarm: a run that waits for ever is interrupted and
     * fails the test instead of hanging the suite.
     *
     * @template R
     *
     * @param \Closure(): R $run
     *
     * @return R
     */
    private function withDeadline(int $seconds, \Closure $run): mixed
    {
        $async = pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function () use ($seconds): never {
            throw new \RuntimeException(\sprintf('The run did not return within %d s.', $seconds));
        });
        pcntl_alarm($seconds);

        try {
            return $run();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            pcntl_async_signals($async);
        }
    }

    /**
     * No payload file left behind, and no worker left to reap: every child
     * was waited for.
     */
    private function assertNothingLeftBehind(): void
    {
        $this->assertSame([], $this->tempFiles(), 'A temp file was left behind.');
        $this->assertSame(-1, pcntl_waitpid(-1, $status, \WNOHANG), 'A worker was left unreaped.');
    }

    /**
     * @return list<string>
     */
    private function tempFiles(): array
    {
        return glob($this->tempDirectory.'/*') ?: [];
    }

    /**
     * A progress that records every call it gets, with the time it got it.
     */
    private function recorder(): LintProgress
    {
        $record = function (string $method, ?int $count, ?string $file = null): void {
            $this->events[] = ['method' => $method, 'count' => $count, 'at' => microtime(true), 'file' => $file];
        };

        return new class($record) implements LintProgress {
            /**
             * @param \Closure(string, ?int, ?string=): void $record
             */
            public function __construct(private readonly \Closure $record) {}

            public function step(string $step, ?int $total): void
            {
                ($this->record)('step', $total);
            }

            public function advance(int $count = 1): void
            {
                ($this->record)('advance', $count);
            }

            public function tick(): void
            {
                ($this->record)('tick', null);
            }

            public function workers(int $count): void
            {
                ($this->record)('workers', $count);
            }

            public function stalled(string $file, int $seconds): void
            {
                ($this->record)('stalled', $seconds, $file);
            }

            public function finish(): void
            {
                ($this->record)('finish', null);
            }
        };
    }

    /**
     * @return list<array{method: string, count: int|null, at: float, file: string|null}>
     */
    private function calls(string $method): array
    {
        return array_values(array_filter($this->events, static fn (array $event): bool => $method === $event['method']));
    }

    private function autoload(): string
    {
        return \dirname(__DIR__, 3).'/vendor/autoload.php';
    }

    /**
     * @param list<string> $ini settings for the process, as "name=value"
     */
    private function runPhp(string $script, array $ini = []): string
    {
        $settings = [];
        foreach ($ini as $setting) {
            $settings[] = '-d';
            $settings[] = $setting;
        }

        $process = proc_open([\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', ...$settings, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout;
    }
}

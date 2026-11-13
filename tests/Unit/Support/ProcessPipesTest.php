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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A child that fills its standard error before it writes its standard
 * output waits for a reader of the error stream: read one stream to its
 * end before the other, and the two processes wait on each other.
 */
final class ProcessPipesTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_a_child_filling_its_error_stream_first_is_read_to_the_end(): void
    {
        // Four times what a pipe buffers on Linux and macOS (64 KiB).
        $code = 'fwrite(STDERR, str_repeat("e", 262144)); fwrite(STDOUT, str_repeat("o", 262144)); fwrite(STDERR, "end");';
        $process = proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        [$stdout, $stderr] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process));
        $this->assertSame(str_repeat('o', 262144), $stdout);
        $this->assertSame(str_repeat('e', 262144).'end', $stderr);
    }

    /**
     * A signal that lands while the pipes are waited on interrupts the
     * wait: the reading goes on to the end of both streams.
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    #[RequiresFunction('pcntl_alarm')]
    public function test_a_wait_a_signal_interrupts_still_reads_to_the_end(): void
    {
        $process = proc_open([\PHP_BINARY, '-r', 'sleep(2); echo "out"; fwrite(STDERR, "err");'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $async = pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function (): void {}, false);
        pcntl_alarm(1);

        try {
            $read = ProcessPipes::drain($pipes);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            pcntl_async_signals($async);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process));
        $this->assertSame(['out', 'err'], $read);
    }

    /**
     * Pipes opened past FD_SETSIZE (1024) cannot be waited on where PHP
     * waits through select(): every wait fails. Reading one stream after the
     * other could leave both processes waiting, so the drain says it failed
     * instead of giving back part of the output. Where PHP waits through
     * poll(), the drain reads them to the end.
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    #[RequiresFunction('posix_setrlimit')]
    public function test_pipes_past_fd_setsize_are_read_or_fail_the_drain(): void
    {
        $limits = posix_getrlimit();
        $soft = \is_array($limits) && is_numeric($limits['soft openfiles'] ?? null) ? (int) $limits['soft openfiles'] : null;
        $hard = \is_array($limits) && is_numeric($limits['hard openfiles'] ?? null) ? (int) $limits['hard openfiles'] : \POSIX_RLIMIT_INFINITY;
        if (null !== $soft && $soft < 2048) {
            posix_setrlimit(\POSIX_RLIMIT_NOFILE, min(2048, $hard), $hard);
        }
        $handles = [];
        $process = null;
        $pipes = [];

        try {
            for ($i = 0; $i < 1100; $i++) {
                $handle = @fopen(__FILE__, 'r');
                if (false === $handle) {
                    break;
                }
                $handles[] = $handle;
            }
            $this->assertCount(1100, $handles, 'Not enough files could be opened to push the pipes past FD_SETSIZE.');
            $process = proc_open([\PHP_BINARY, '-r', 'echo "out";'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);

            // Whether this PHP waits on pipes past FD_SETSIZE: some do,
            // through poll(); the others fail every wait there.
            $probe = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (false !== @stream_select($probe, $write, $except, 0)) {
                $this->assertSame(['out', ''], ProcessPipes::drain($pipes));

                return;
            }

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('The output of the child cannot be waited on');

            ProcessPipes::drain($pipes);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            if (\is_resource($process)) {
                proc_close($process);
            }
            foreach ($handles as $handle) {
                fclose($handle);
            }
            if (null !== $soft && $soft < 2048) {
                posix_setrlimit(\POSIX_RLIMIT_NOFILE, $soft, $hard);
            }
        }
    }

    #[Test]
    public function test_a_child_writing_nothing_gives_two_empty_streams(): void
    {
        $process = proc_open([\PHP_BINARY, '-r', ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        $this->assertSame(['', ''], ProcessPipes::drain($pipes));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
    }
}

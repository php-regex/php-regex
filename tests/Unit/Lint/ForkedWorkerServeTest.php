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

use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a worker says on its channel, read in this process: the loop a
 * forked worker runs, called here on one end of a socket pair, the parent's
 * lines written on the other end beforehand. Each chunk done is reported,
 * with the progress made before it, and the results are handed back in a
 * payload file named last.
 */
#[RequiresFunction('pcntl_sigprocmask')]
#[RequiresFunction('stream_socket_pair')]
final class ForkedWorkerServeTest extends TestCase
{
    private string $tempDirectory = '';

    protected function setUp(): void
    {
        LintFunctionOverrides::reset();
        $this->tempDirectory = sys_get_temp_dir().'/regexparser_serve_test_'.bin2hex(random_bytes(6));
        mkdir($this->tempDirectory);
        // tempnam() answers the real path, past any link of the temp directory.
        $this->tempDirectory = (string) realpath($this->tempDirectory);
        LintFunctionOverrides::$tempnamDirectory = $this->tempDirectory;
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
        foreach (glob($this->tempDirectory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDirectory);
    }

    #[Test]
    public function test_serve_reports_each_chunk_as_its_progress_comes_then_names_the_payload(): void
    {
        // A report interval of zero: every step of progress is written as it comes.
        $written = $this->serve(new ForkedWorkerPool(reportInterval: 0.0), "1\n0\nstop\n", [['a', 'b'], ['c']], static function (array $chunk, \Closure $done): array {
            foreach ($chunk as $_) {
                $done(1);
            }

            return array_map(strtoupper(...), $chunk);
        });

        $this->assertSame(1, preg_match('/\Ap 1\nd 1\np 1\np 1\nd 0\nf (\S+)\n\z/', $written, $match), $written);
        $this->assertSame(['ok' => true, 'results' => [1 => ['C'], 0 => ['A', 'B']]], $this->payload($match[1]));
    }

    #[Test]
    public function test_serve_reports_the_progress_held_back_with_the_chunk_done(): void
    {
        // An interval no chunk lasts: the progress is held until the chunk is done.
        $written = $this->serve(new ForkedWorkerPool(reportInterval: 3600.0), "0\nstop\n", [['a', 'b', 'c']], static function (array $chunk, \Closure $done): array {
            $done(2);
            $done(1);

            return $chunk;
        });

        $this->assertSame(1, preg_match('/\Ap 3\nd 0\nf (\S+)\n\z/', $written, $match), $written);
        $this->assertSame(['ok' => true, 'results' => [['a', 'b', 'c']]], $this->payload($match[1]));
    }

    #[Test]
    public function test_serve_reports_no_progress_for_a_chunk_that_made_none(): void
    {
        $written = $this->serve(new ForkedWorkerPool(reportInterval: 3600.0), "0\nstop\n", [['a']], static fn (array $chunk, \Closure $done): array => []);

        $this->assertSame(1, preg_match('/\Ad 0\nf (\S+)\n\z/', $written, $match), $written);
        $this->assertSame(['ok' => true, 'results' => [[]]], $this->payload($match[1]));
    }

    #[Test]
    public function test_serve_hands_back_the_failure_of_a_chunk_and_runs_no_other(): void
    {
        $ran = [];
        $written = $this->serve(new ForkedWorkerPool(), "0\n1\nstop\n", [['a'], ['b']], static function (array $chunk) use (&$ran): array {
            $ran[] = $chunk;

            throw new \DomainException('chunk '.$chunk[0].' failed');
        });

        $this->assertSame([['a']], $ran);
        $this->assertSame(1, preg_match('/\Af (\S+)\n\z/', $written, $match), $written);
        $this->assertSame(['ok' => false, 'class' => \DomainException::class, 'message' => 'chunk a failed'], $this->payload($match[1]));
    }

    #[Test]
    public function test_serve_writes_nothing_once_its_parent_is_gone(): void
    {
        $written = $this->serve(new ForkedWorkerPool(), '', [['a']], static fn (array $chunk): array => $chunk);

        $this->assertSame('', $written);
        $this->assertSame([], glob($this->tempDirectory.'/*'));
    }

    #[Test]
    public function test_serve_names_no_payload_file_it_could_not_create(): void
    {
        LintFunctionOverrides::queueTempnam(false);

        $written = $this->serve(new ForkedWorkerPool(), "0\nstop\n", [['a']], static fn (array $chunk): array => $chunk);

        $this->assertSame("d 0\n", $written);
        $this->assertSame([], glob($this->tempDirectory.'/*'));
    }

    /**
     * Writes $lines to the worker's end and closes it, runs the worker loop
     * on the other end, and returns what the worker wrote. The loop blocks
     * SIGTERM before it creates the payload file: the mask is put back.
     *
     * @param list<list<string>>                                        $chunks
     * @param \Closure(list<string>, \Closure(int): void): list<string> $work
     */
    private function serve(ForkedWorkerPool $pool, string $lines, array $chunks, \Closure $work): string
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$parent, $worker] = $pair;
        fwrite($parent, $lines);
        stream_socket_shutdown($parent, \STREAM_SHUT_WR);

        $mask = [];
        pcntl_sigprocmask(\SIG_BLOCK, [\SIGTERM], $mask);
        $wasBlocked = \is_array($mask) && \in_array(\SIGTERM, $mask, true);

        try {
            (new \ReflectionMethod(ForkedWorkerPool::class, 'serve'))->invoke($pool, $worker, $chunks, $work);
        } finally {
            if (!$wasBlocked) {
                pcntl_sigprocmask(\SIG_UNBLOCK, [\SIGTERM]);
            }
        }

        fclose($worker);
        $written = (string) stream_get_contents($parent);
        fclose($parent);

        return $written;
    }

    private function payload(string $path): mixed
    {
        $this->assertStringStartsWith($this->tempDirectory.'/'.ForkedWorkerPool::PAYLOAD_PREFIX, $path);

        return unserialize((string) file_get_contents($path));
    }
}

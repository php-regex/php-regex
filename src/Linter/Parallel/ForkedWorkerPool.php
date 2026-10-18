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

namespace PHPRegex\Linter\Parallel;

use PHPRegex\Linter\LintException;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * Shares the linter's work out between long-lived forked workers.
 *
 * The items are cut into small chunks, handed out one at a time as the
 * workers free up, so no worker idles while another works through a long
 * tail. Each worker talks to its parent over a socket pair, one ASCII line
 * per message:
 *
 *  - parent to worker: "<chunk index>", or "stop";
 *  - worker to parent: "p <n>" (n more items done; at most once per report
 *    interval, and at the end of each chunk), "d <chunk index>" (the chunk
 *    is done), "f <path>" (its payload file, written once told to stop or
 *    once a chunk threw).
 *
 * The worker holds the items through the fork: only chunk indexes travel.
 * It creates its payload file only when it stops, so a parent killed midway
 * leaves no file behind. A worker ended early is sent SIGTERM, which it holds
 * from just before it creates its payload file to its end: either it dies
 * with no file, or its parent reads the name and removes the file.
 *
 * The channels never time out: a parent stalled on its output (a terminal
 * paused with Ctrl-S) is not a parent gone, and a worker waiting for its
 * next chunk keeps waiting.
 *
 * Library code returns; a worker is the one exception. Once its payload is
 * written it must end, not return into the code of its parent (which would
 * go on linting, twice), so it exits here: the only "exit" in the library.
 *
 * @internal
 */
final class ForkedWorkerPool
{
    /**
     * The read and write timeout of the channels, in seconds: about 23
     * days, as long as poll() can wait (its milliseconds fit a C int).
     */
    private const NO_TIMEOUT = 2_000_000;

    /**
     * The process id of each worker, by worker number.
     *
     * @var array<int, int>
     */
    private array $pids = [];

    /**
     * The parent's end of each worker's channel, while it is open.
     *
     * @var array<int, resource>
     */
    private array $channels = [];

    /**
     * What each worker sent that is not read as a line yet.
     *
     * @var array<int, string>
     */
    private array $buffers = [];

    /**
     * The chunk each worker is on.
     *
     * @var array<int, int>
     */
    private array $inFlight = [];

    /**
     * The workers told to stop: they write a payload file.
     *
     * @var array<int, true>
     */
    private array $stopping = [];

    /**
     * The workers whose payload file was read.
     *
     * @var array<int, true>
     */
    private array $handedBack = [];

    private int $nextChunk = 0;

    /**
     * When a worker last reported progress.
     */
    private float $reportedAt = 0.0;

    /**
     * Whether the progress was told which file the run is held on since
     * the last report.
     */
    private bool $stallNamed = false;

    /**
     * @param float $stallAfter the seconds without a report after which the progress is told which file holds the run
     */
    public function __construct(
        private readonly int $chunkFactor = 16,
        private readonly int $maxChunkSize = 2000,
        private readonly float $reportInterval = 0.1,
        private readonly float $stallAfter = 10.0,
    ) {}

    /**
     * Whether workers can be forked here: the CLI, with pcntl, the signal
     * calls a worker makes, and the stream functions the channels need.
     */
    public static function canFork(): bool
    {
        return 'cli' === \PHP_SAPI
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_sigprocmask')
            && function_exists('stream_socket_pair')
            && function_exists('stream_select');
    }

    /**
     * Runs the work on every item across the workers. The progress
     * advances as the workers report, and its clock ticks while they are
     * silent.
     *
     * @template T
     * @template R
     *
     * @param list<T>                                         $items
     * @param \Closure(list<T>, \Closure(int): void): list<R> $work           runs one chunk; calls $done(n) as n items finish
     * @param list<class-string>                              $allowedClasses for unserialize()
     * @param \Closure(T): string                             $describe       names an item (its file) for failure messages
     *
     * @throws LintException a worker threw, or exited without its payload
     *
     * @return list<R>|null the results of every chunk, in the order of the items; null when the workers could not be started (the caller runs serially)
     */
    public function run(array $items, int $workers, \Closure $work, ?LintProgress $progress, string $failurePrefix, array $allowedClasses, \Closure $describe): ?array
    {
        if ([] === $items) {
            return [];
        }

        // More workers than items would idle; capped first, the count times
        // the chunk factor cannot overflow.
        $workers = max(1, min($workers, \count($items)));
        $chunks = array_chunk($items, max(1, min($this->maxChunkSize, intdiv(\count($items), $workers * $this->chunkFactor))));
        $seconds = (int) $this->reportInterval;
        $microseconds = (int) (($this->reportInterval - $seconds) * 1_000_000);
        $resultsByChunk = [];

        $this->reset();

        try {
            if (!$this->start(min($workers, \count($chunks)), $chunks, $work) || !$this->selectable()) {
                return null;
            }

            $progress?->workers(\count($this->pids));
            foreach (array_keys($this->channels) as $worker) {
                $this->dispatch($worker, \count($chunks));
            }

            while ([] !== $this->channels) {
                $read = $this->channels;
                $write = null;
                $except = null;
                // A signal interrupts the wait with false, which leaves the
                // channels untouched: counted as a wait that read nothing.
                // The channels were found selectable, and only fewer of them
                // are waited on as the run goes: false is never a failure
                // that would come back on every wait.
                if (0 === (int) @stream_select($read, $write, $except, $seconds, $microseconds)) {
                    $progress?->tick();
                    if (null !== $progress) {
                        $this->nameStall($progress, $chunks, $describe);
                    }

                    continue;
                }

                foreach (array_keys($read) as $worker) {
                    $this->receive($worker, $chunks, $failurePrefix, $describe);

                    while (null !== ($line = $this->nextLine($worker))) {
                        if (str_starts_with($line, 'p ')) {
                            $this->reportedAt = microtime(true);
                            $this->stallNamed = false;
                            $progress?->advance((int) substr($line, 2));
                        } elseif (str_starts_with($line, 'd ')) {
                            $this->dispatch($worker, \count($chunks));
                        } elseif (str_starts_with($line, 'f ')) {
                            $resultsByChunk += $this->payload($worker, substr($line, 2), $chunks, $failurePrefix, $allowedClasses, $describe);
                        }
                    }
                }
            }
        } finally {
            $this->shutdown();
        }

        ksort($resultsByChunk);
        $results = [];
        foreach ($resultsByChunk as $chunkResults) {
            foreach ($chunkResults as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function reset(): void
    {
        $this->pids = [];
        $this->channels = [];
        $this->buffers = [];
        $this->inFlight = [];
        $this->stopping = [];
        $this->handedBack = [];
        $this->nextChunk = 0;
        $this->reportedAt = microtime(true);
        $this->stallNamed = false;
    }

    /**
     * No worker reported for $stallAfter seconds: the progress is told,
     * once until the next report, the first file of the chunk in flight
     * the longest, the lowest index, as the chunks go out in order.
     *
     * @template T
     *
     * @param list<list<T>>       $chunks
     * @param \Closure(T): string $describe
     */
    private function nameStall(LintProgress $progress, array $chunks, \Closure $describe): void
    {
        $silent = microtime(true) - $this->reportedAt;
        if ($this->stallNamed || $silent < $this->stallAfter || [] === $this->inFlight) {
            return;
        }

        $this->stallNamed = true;
        $progress->stalled($describe($chunks[min($this->inFlight)][0]), (int) $silent);
    }

    /**
     * Forks the workers, each with its channel. False when a channel or a
     * fork failed: the workers already started are left to the shutdown,
     * which ends them.
     *
     * @template T
     * @template R
     *
     * @param list<list<T>>                                   $chunks
     * @param \Closure(list<T>, \Closure(int): void): list<R> $work
     */
    private function start(int $count, array $chunks, \Closure $work): bool
    {
        for ($worker = 0; $worker < $count; $worker++) {
            $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            if (false === $pair) {
                return false;
            }

            [$parentEnd, $workerEnd] = $pair;
            $pid = pcntl_fork();
            if (-1 === $pid) {
                fclose($parentEnd);
                fclose($workerEnd);

                return false;
            }

            if (0 === $pid) {
                // Unreachable from a test: only the forked worker runs this
                // line, and its coverage ends with it.
                $this->work($parentEnd, $workerEnd, $chunks, $work);
            }

            // The parent keeps its own end only: once the worker is gone,
            // nothing holds the other end open, and the channel reads EOF.
            fclose($workerEnd);
            stream_set_timeout($parentEnd, self::NO_TIMEOUT);
            $this->pids[$worker] = $pid;
            $this->channels[$worker] = $parentEnd;
            $this->buffers[$worker] = '';
        }

        return true;
    }

    /**
     * Whether the channels can be waited on. select() cannot watch a
     * descriptor numbered past FD_SETSIZE (a process with many files open):
     * stream_select() then gives false at once, on every call, and the
     * caller runs serially. A signal interrupts one wait, not three in a
     * row.
     */
    private function selectable(): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $read = $this->channels;
            $write = null;
            $except = null;
            if (false !== @stream_select($read, $write, $except, 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hands the worker the next chunk, or tells it to stop.
     */
    private function dispatch(int $worker, int $chunkCount): void
    {
        unset($this->inFlight[$worker]);
        if ($this->nextChunk < $chunkCount) {
            $this->inFlight[$worker] = $this->nextChunk;
            $message = $this->nextChunk++."\n";
        } else {
            $this->stopping[$worker] = true;
            $message = "stop\n";
        }

        // A worker already gone breaks the pipe: the write fails, and its
        // channel reads EOF, which the loop reports.
        @fwrite($this->channels[$worker], $message);
    }

    /**
     * Reads what the worker sent into its buffer. At EOF the worker is
     * gone: reaped, and reported when it left without its payload.
     *
     * @template T
     *
     * @param list<list<T>>       $chunks
     * @param \Closure(T): string $describe
     */
    private function receive(int $worker, array $chunks, string $failurePrefix, \Closure $describe): void
    {
        $data = fread($this->channels[$worker], 8192);
        if (false !== $data && '' !== $data) {
            $this->buffers[$worker] .= $data;

            return;
        }

        $this->close($worker);
        if (isset($this->handedBack[$worker])) {
            return;
        }

        $chunk = $this->inFlight[$worker] ?? null;

        throw new LintException(null === $chunk
            ? $failurePrefix.': a worker exited without handing back its results'
            : \sprintf('%s: a worker exited unexpectedly while reading %s', $failurePrefix, self::describeChunk($chunks[$chunk], $describe)));
    }

    /**
     * Takes the next full line out of the worker's buffer, so a line not
     * handled yet stays there for the shutdown.
     */
    private function nextLine(int $worker): ?string
    {
        $end = strpos($this->buffers[$worker], "\n");
        if (false === $end) {
            return null;
        }

        $line = substr($this->buffers[$worker], 0, $end);
        $this->buffers[$worker] = substr($this->buffers[$worker], $end + 1);

        return $line;
    }

    /**
     * Reads a worker's payload file and removes it: the results of its
     * chunks by chunk index, or the failure its chunk threw.
     *
     * @template T
     *
     * @param list<list<T>>       $chunks
     * @param list<class-string>  $allowedClasses
     * @param \Closure(T): string $describe
     *
     * @return array<int, array<mixed>>
     */
    private function payload(int $worker, string $path, array $chunks, string $failurePrefix, array $allowedClasses, \Closure $describe): array
    {
        $this->handedBack[$worker] = true;
        $payload = @unserialize((string) @file_get_contents($path), ['allowed_classes' => $allowedClasses]);
        @unlink($path);

        $chunk = $this->inFlight[$worker] ?? null;
        $class = \is_array($payload) ? $payload['class'] ?? null : null;
        $message = \is_array($payload) ? $payload['message'] ?? null : null;
        if (\is_string($class) && \is_string($message) && null !== $chunk) {
            throw new LintException(\sprintf('%s: %s: %s (in %s)', $failurePrefix, $class, $message, self::describeChunk($chunks[$chunk], $describe)));
        }

        // Each chunk gives a list: anything else cannot be put back in the
        // order of the items.
        $results = \is_array($payload) && true === ($payload['ok'] ?? null) ? $payload['results'] ?? null : null;
        $valid = \is_array($results);
        $byChunk = [];
        foreach (\is_array($results) ? $results : [] as $index => $chunkResults) {
            if (\is_int($index) && \is_array($chunkResults)) {
                $byChunk[$index] = $chunkResults;
            } else {
                $valid = false;
            }
        }

        if (!$valid) {
            throw new LintException($failurePrefix.': Invalid worker output.');
        }

        return $byChunk;
    }

    /**
     * "x.php", or "x.php and 2 more": the first file of the chunk, and how
     * many other files it holds.
     *
     * @template T
     *
     * @param list<T>             $chunk
     * @param \Closure(T): string $describe
     */
    private static function describeChunk(array $chunk, \Closure $describe): string
    {
        $files = array_unique(array_map($describe, $chunk));
        $first = (string) array_shift($files);

        return [] === $files ? $first : \sprintf('%s and %d more', $first, \count($files));
    }

    private function close(int $worker): void
    {
        fclose($this->channels[$worker]);
        unset($this->channels[$worker]);
        // Its channel at EOF, the worker has exited: the wait does not
        // block, and a process id no process has gives -1 at once.
        pcntl_waitpid($this->pids[$worker], $status);
    }

    /**
     * Ends every worker still running, however the run ended. A worker not
     * told to stop is sent SIGTERM: in the middle of a chunk it has no
     * payload file yet, and one writing its failure holds the signal until
     * it has named its file. A worker told to stop is let write its payload
     * file. Every file named is removed, and every worker reaped.
     */
    private function shutdown(): void
    {
        foreach ($this->channels as $worker => $channel) {
            // A worker waiting for its next chunk reads EOF, and exits.
            stream_socket_shutdown($channel, \STREAM_SHUT_WR);
            if (!isset($this->stopping[$worker]) && function_exists('posix_kill')) {
                posix_kill($this->pids[$worker], \SIGTERM);
            }

            // Read up to EOF: the worker is gone, its file named if it had one.
            $rest = $this->buffers[$worker].stream_get_contents($channel);
            if (1 === LibraryPcre::match('/^f (.+)$/m', $rest, $match)) {
                @unlink($match[1]);
            }

            $this->close($worker);
        }

        $this->reset();
    }

    /**
     * What a worker does until it ends: runs each chunk it is handed,
     * reports its progress, then writes its payload file. It never returns.
     *
     * @template T
     * @template R
     *
     * @param resource                                        $parentEnd
     * @param resource                                        $channel
     * @param list<list<T>>                                   $chunks
     * @param \Closure(list<T>, \Closure(int): void): list<R> $work
     */
    private function work($parentEnd, $channel, array $chunks, \Closure $work): never
    {
        // The parent's ends, of this channel and of the channels forked
        // before it: held open here, they would keep this worker from
        // reading EOF when the parent is gone.
        fclose($parentEnd);
        foreach ($this->channels as $sibling) {
            fclose($sibling);
        }

        // What the parent buffered would be written once more when the
        // worker ends.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // The parent ends this worker with SIGTERM, whatever handler of its
        // own it inherited, blocked or not; the channel waits as long as the
        // parent does.
        pcntl_signal(\SIGTERM, \SIG_DFL);
        stream_set_timeout($channel, self::NO_TIMEOUT);

        try {
            $this->serve($channel, $chunks, $work);
        } catch (\Throwable) {
            // Nothing to hand back: the parent reads EOF and reports it.
        }

        // The shutdown functions and destructors the worker inherited belong
        // to its parent: they would flush, close or notify on its behalf, and
        // load their classes in every worker at once, which reads corrupted
        // bytes from a phar. The worker ends without them; its parent reads
        // the channel, never the exit code.
        if (function_exists('posix_kill') && function_exists('posix_getpid')) {
            posix_kill(posix_getpid(), \SIGKILL);
        }

        exit(0);
    }

    /**
     * @template T
     * @template R
     *
     * @param resource                                        $channel
     * @param list<list<T>>                                   $chunks
     * @param \Closure(list<T>, \Closure(int): void): list<R> $work
     */
    private function serve($channel, array $chunks, \Closure $work): void
    {
        $results = [];
        $reported = microtime(true);

        while (true) {
            $line = fgets($channel);
            // The parent is gone: nobody would read the payload.
            if (false === $line) {
                return;
            }

            if ("stop\n" === $line) {
                $payload = ['ok' => true, 'results' => $results];

                break;
            }

            $index = (int) $line;
            $pending = 0;
            $done = function (int $count) use ($channel, &$pending, &$reported): void {
                $pending += $count;
                if (microtime(true) - $reported >= $this->reportInterval) {
                    @fwrite($channel, 'p '.$pending."\n");
                    $pending = 0;
                    $reported = microtime(true);
                }
            };

            try {
                $results[$index] = $work($chunks[$index], $done);
            } catch (\Throwable $e) {
                $payload = ['ok' => false, 'class' => $e::class, 'message' => $e->getMessage()];

                break;
            }

            @fwrite($channel, ($pending > 0 ? 'p '.$pending."\n" : '').'d '.$index."\n");
        }

        // Serialized first: what cannot be serialized throws before a file
        // exists. From the file created to its name sent, SIGTERM waits: the
        // worker never dies holding a file its parent cannot name. It is
        // never let through again: the worker ends by SIGKILL, or by exit
        // without posix.
        $data = serialize($payload);
        pcntl_sigprocmask(\SIG_BLOCK, [\SIGTERM]);
        $path = tempnam(sys_get_temp_dir(), 'regexparser_');
        if (false !== $path) {
            file_put_contents($path, $data);
            fwrite($channel, 'f '.$path."\n");
        }
    }
}

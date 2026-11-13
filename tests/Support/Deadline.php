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

namespace PHPRegex\Tests\Support;

/**
 * A run under an alarm: one that waits for ever is interrupted and fails
 * the test instead of hanging the suite.
 *
 * PHP reads a stream again when a signal interrupts the read, so the alarm
 * alone never ends a read that blocks: its handler runs once the read
 * returns. A watchdog started beside the run ends the other children of
 * the process when the deadline passes, which closes the channels a read
 * may wait on; the read returns, and the handler throws.
 */
final class Deadline
{
    private function __construct() {}

    /**
     * @template R
     *
     * @param \Closure(): R $run
     *
     * @return R
     */
    public static function within(int $seconds, \Closure $run): mixed
    {
        // One process, holding none of the run's output: ended, it is gone.
        $null = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $watchdog = proc_open([\PHP_BINARY, '-n', '-r', \sprintf('sleep(%d); exec("pkill -KILL -P %d");', $seconds, getmypid())], $null, $pipes);
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
            if (\is_resource($watchdog)) {
                proc_terminate($watchdog, \SIGKILL);
                proc_close($watchdog);
            }
        }
    }
}

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

use PHPRegex\Parser\Text\Path;

/**
 * The standard output and the standard error of a child, read together: a
 * child that fills one pipe waits for its reader, so reading the other one
 * to its end first would leave both processes waiting. On Windows a pipe
 * of proc_open() cannot be waited on, and a read blocks until bytes come:
 * the two streams are read one after the other there.
 */
final class ProcessPipes
{
    private function __construct() {}

    /**
     * Reads pipes 1 and 2 to their end; the caller closes them.
     *
     * @param array<int, resource> $pipes the pipes proc_open() gave
     *
     * @return array{0: string, 1: string} the standard output, then the standard error
     */
    public static function drain(array $pipes): array
    {
        if (Path::onWindows()) {
            return [(string) stream_get_contents($pipes[1]), (string) stream_get_contents($pipes[2])];
        }

        $read = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $failures = 0;
        while ([] !== $open) {
            $ready = array_values($open);
            $write = $except = null;
            // A signal that lands during the wait interrupts it: wait again.
            // A wait that keeps failing, pipes past FD_SETSIZE, fails the
            // drain: reading one stream after the other could leave both
            // processes waiting, and part of the output is no output.
            if (false === @stream_select($ready, $write, $except, 1)) {
                if (++$failures < 10) {
                    continue;
                }

                throw new \RuntimeException('The output of the child cannot be waited on: stream_select() failed ten times in a row.');
            }
            $failures = 0;

            foreach ($open as $index => $pipe) {
                if (!\in_array($pipe, $ready, true)) {
                    continue;
                }

                $chunk = fread($pipe, 65536);
                if (false !== $chunk && '' !== $chunk) {
                    $read[$index] .= $chunk;
                } elseif (feof($pipe)) {
                    unset($open[$index]);
                }
            }
        }

        return [$read[1], $read[2]];
    }
}

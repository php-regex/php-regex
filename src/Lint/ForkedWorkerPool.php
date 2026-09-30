<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Lint;

/**
 * Forks the children the linter shares its work with.
 *
 * Library code returns; the child of a fork is the one exception. Once its
 * work is written out it must end, not return into the code of its parent
 * (which would go on linting, twice), so it exits here: the only "exit" in
 * the library.
 *
 * @internal
 */
final class ForkedWorkerPool
{
    /**
     * Forks a child that runs the work, hands what it gives (or the failure
     * it throws) to $write, and ends. The parent gets the process id of the
     * child, or -1 when no child could be forked.
     *
     * @param \Closure(): mixed                                                                              $work
     * @param \Closure(array{ok: bool, result?: mixed, error?: array{message: string, class: string}}): void $write
     */
    public function fork(\Closure $work, \Closure $write): int
    {
        $pid = pcntl_fork();
        if (0 !== $pid) {
            return $pid;
        }

        // The child. No test reaches this line: its coverage ends with it.
        exit($this->runChild($work, $write));
    }

    /**
     * What the child does before it ends: the exit code it ends with, 0
     * when the work gave a result, 1 when it threw.
     *
     * @param \Closure(): mixed                                                                              $work
     * @param \Closure(array{ok: bool, result?: mixed, error?: array{message: string, class: string}}): void $write
     */
    public function runChild(\Closure $work, \Closure $write): int
    {
        try {
            $payload = ['ok' => true, 'result' => $work()];
        } catch (\Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                ],
            ];
        }

        $write($payload);

        return $payload['ok'] ? 0 : 1;
    }
}

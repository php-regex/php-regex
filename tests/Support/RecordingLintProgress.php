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

use PHPRegex\Linter\LintProgress;

/**
 * A progress that writes down every call it is given, in order, and answers
 * what a test asks of them: the steps started, what each step advanced, the
 * workers announced, the steps finished.
 *
 * It doubles as a watchdog: the run calls tick() while it waits on its
 * workers, so a run that waits longer than the budget fails the test
 * instead of hanging the suite.
 */
final class RecordingLintProgress implements LintProgress
{
    /**
     * @var list<array{string, list<int|string|null>}>
     */
    private array $calls = [];

    private readonly float $startedAt;

    public function __construct(private readonly float $budgetSeconds = 60.0)
    {
        $this->startedAt = microtime(true);
    }

    public function step(string $step, ?int $total): void
    {
        $this->calls[] = ['step', [$step, $total]];
    }

    public function advance(int $count = 1): void
    {
        $this->calls[] = ['advance', [$count]];
    }

    public function tick(): void
    {
        $this->calls[] = ['tick', []];

        if (microtime(true) - $this->startedAt > $this->budgetSeconds) {
            throw new \RuntimeException(\sprintf('The run is still waiting after %.0f seconds: it looks stuck.', $this->budgetSeconds));
        }
    }

    public function workers(int $count): void
    {
        $this->calls[] = ['workers', [$count]];
    }

    public function finish(): void
    {
        $this->calls[] = ['finish', []];
    }

    /**
     * Every call, in order, ticks included.
     *
     * @return list<array{string, list<int|string|null>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * The step() calls, in order: name and total.
     *
     * @return list<array{string, ?int}>
     */
    public function steps(): array
    {
        $steps = [];
        foreach ($this->calls as [$method, $args]) {
            if ('step' === $method) {
                $steps[] = [(string) $args[0], null === $args[1] ? null : (int) $args[1]];
            }
        }

        return $steps;
    }

    /**
     * The sum of what was advanced while the step of that name was the
     * current one (every step of that name, if it started more than once).
     */
    public function advancedIn(string $step): int
    {
        $current = null;
        $sum = 0;
        foreach ($this->calls as [$method, $args]) {
            if ('step' === $method) {
                $current = (string) $args[0];
            } elseif ('advance' === $method && $current === $step) {
                $sum += (int) $args[0];
            }
        }

        return $sum;
    }

    /**
     * The sum of every advance, inside a step or not.
     */
    public function advancedTotal(): int
    {
        $sum = 0;
        foreach ($this->calls as [$method, $args]) {
            if ('advance' === $method) {
                $sum += (int) $args[0];
            }
        }

        return $sum;
    }

    /**
     * The sum of what was advanced before any step started.
     */
    public function advancedOutsideSteps(): int
    {
        $sum = 0;
        foreach ($this->calls as [$method, $args]) {
            if ('step' === $method) {
                break;
            }
            if ('advance' === $method) {
                $sum += (int) $args[0];
            }
        }

        return $sum;
    }

    /**
     * The worker counts announced, in order.
     *
     * @return list<int>
     */
    public function workerCounts(): array
    {
        $counts = [];
        foreach ($this->calls as [$method, $args]) {
            if ('workers' === $method) {
                $counts[] = (int) $args[0];
            }
        }

        return $counts;
    }

    /**
     * The worker counts announced while the step of that name was the
     * current one.
     *
     * @return list<int>
     */
    public function workerCountsIn(string $step): array
    {
        $current = null;
        $counts = [];
        foreach ($this->calls as [$method, $args]) {
            if ('step' === $method) {
                $current = (string) $args[0];
            } elseif ('workers' === $method && $current === $step) {
                $counts[] = (int) $args[0];
            }
        }

        return $counts;
    }

    public function finishedCount(): int
    {
        return \count($this->finishedSteps());
    }

    /**
     * For each finish() call, the name of the step it ended (null when no
     * step had started).
     *
     * @return list<string|null>
     */
    public function finishedSteps(): array
    {
        $current = null;
        $finished = [];
        foreach ($this->calls as [$method, $args]) {
            if ('step' === $method) {
                $current = (string) $args[0];
            } elseif ('finish' === $method) {
                $finished[] = $current;
            }
        }

        return $finished;
    }

    /**
     * Each step that started, with what it advanced: the shape two runs of
     * the same work share whatever the advance granularity.
     *
     * @return list<array{string, ?int, int}> name, total, sum of its advances
     */
    public function stepSums(): array
    {
        $sums = [];
        $current = null;
        foreach ($this->calls as [$method, $args]) {
            if ('step' === $method) {
                if (null !== $current) {
                    $sums[] = $current;
                }
                $current = [(string) $args[0], null === $args[1] ? null : (int) $args[1], 0];
            } elseif ('advance' === $method && null !== $current) {
                $current[2] += (int) $args[0];
            }
        }
        if (null !== $current) {
            $sums[] = $current;
        }

        return $sums;
    }
}

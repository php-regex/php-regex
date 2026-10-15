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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\Output;
use PHPRegex\Linter\LintProgress;

/**
 * The lint run's steps on the console: a status line per step, then its
 * progress, both on the error stream. The steps of the scan share one line.
 * Shown or not, it counts the files linted and the most workers a step
 * started.
 *
 * @internal
 */
final class LintConsoleProgress implements LintProgress
{
    private const SCAN_LABELS = [
        LintProgress::STEP_FILES => 'Finding files',
        LintProgress::STEP_DECLARATIONS => 'Declarations',
        LintProgress::STEP_FUNCTIONS => 'Functions',
        LintProgress::STEP_PATTERNS => 'Patterns',
    ];

    private int $files = 0;

    private int $workers = 1;

    /**
     * Whether the scan's line is drawn and not finished yet.
     */
    private bool $scanning = false;

    /**
     * @param bool $show          whether the steps are shown
     * @param bool $optimizations whether the run suggests optimizations, a step of its own
     */
    public function __construct(
        private readonly Output $output,
        private readonly bool $show,
        private readonly bool $optimizations,
    ) {}

    public function step(string $step, ?int $total): void
    {
        if (LintProgress::STEP_PATTERNS === $step) {
            $this->files = $total ?? 0;
        }

        if (!$this->show) {
            return;
        }

        if (isset(self::SCAN_LABELS[$step])) {
            if (LintProgress::STEP_FILES === $step) {
                $this->status(1, 'Scanning files');
            }
            if (0 !== $total) {
                $this->output->progressLabel(self::SCAN_LABELS[$step]);
                $this->output->progressStart($total);
                $this->scanning = true;
            }

            return;
        }

        $this->endScan();
        if (LintProgress::STEP_OPTIMIZATIONS === $step) {
            $this->status(3, 'Suggesting optimizations');
        } else {
            $this->status(2, 'Analyzing patterns');
        }
        $this->output->progressStart($total);
    }

    /**
     * A step not shown never started the output's progress: nothing is drawn.
     */
    public function advance(int $count = 1): void
    {
        $this->output->progressAdvance($count);
    }

    public function tick(): void
    {
        $this->output->progressTick();
    }

    public function workers(int $count): void
    {
        $this->workers = max($this->workers, $count);
    }

    /**
     * A step not shown never started the output's progress: nothing is drawn.
     */
    public function stalled(string $file, int $seconds): void
    {
        $this->output->progressNote('Still on '.$file);
    }

    /**
     * The scan's steps leave their line to the next one: the scan ends it.
     */
    public function finish(): void
    {
        if ($this->show && !$this->scanning) {
            $this->output->progressFinish();
        }
    }

    /**
     * Ends the scan's line, once the patterns are collected.
     */
    public function endScan(): void
    {
        if ($this->scanning) {
            $this->scanning = false;
            $this->output->progressFinish();
        }
    }

    /**
     * Ends the line of a step that failed, as far as it got.
     */
    public function abandon(): void
    {
        $this->scanning = false;
        $this->output->progressAbandon();
    }

    /**
     * The files the scan read for their patterns.
     */
    public function files(): int
    {
        return $this->files;
    }

    /**
     * The most workers a step started, 1 when every step ran in this
     * process.
     */
    public function processes(): int
    {
        return $this->workers;
    }

    private function status(int $step, string $name): void
    {
        $this->output->writeError('  '.$this->output->dim(\sprintf('[%d/%d] %s', $step, $this->optimizations ? 3 : 2, $name))."\n");
    }
}

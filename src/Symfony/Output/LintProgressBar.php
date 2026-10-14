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

namespace PHPRegex\Symfony\Output;

use PHPRegex\Linter\LintProgress;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The lint progress drawn with console progress bars: a bar per step, the
 * scanning sub-steps named on a line above theirs, so that every bar starts
 * in the same column. The clock is redrawn between two advances on a
 * terminal only, so a log gets no line per tick.
 *
 * @internal
 */
final class LintProgressBar implements LintProgress
{
    private const BAR_WIDTH = 28;
    private const MESSAGE_PAD_LENGTH = 15;

    private const LABELS = [
        self::STEP_FILES => 'Finding files',
        self::STEP_DECLARATIONS => 'Declarations',
        self::STEP_FUNCTIONS => 'Functions',
        self::STEP_PATTERNS => 'Patterns',
    ];

    /**
     * The bar of the current step, until it finishes.
     */
    private ?ProgressBar $bar = null;

    /**
     * The last bar drawn, while its line is not ended.
     */
    private ?ProgressBar $line = null;

    private string $step = '';

    private int $count = 0;

    private ?int $total = null;

    private int $files = 0;

    private int $workers = 1;

    /**
     * Where the bars are drawn: the error output of a console.
     */
    private readonly SymfonyStyle $errors;

    private readonly bool $decorated;

    /**
     * @param bool $optimizations whether the run suggests optimizations, a step of its own
     */
    public function __construct(private readonly SymfonyStyle $io, private readonly bool $optimizations)
    {
        $this->errors = $io->getErrorStyle();
        $this->decorated = $this->errors->isDecorated();
    }

    public function step(string $step, ?int $total): void
    {
        $this->bar?->finish();
        $this->bar = null;
        $this->step = $step;
        $this->count = 0;
        $this->total = $total;

        $main = self::STEP_ANALYSIS === $step || self::STEP_OPTIMIZATIONS === $step;
        if ($main) {
            $this->endLine();
            $this->io->newLine();
            $this->io->writeln(self::STEP_ANALYSIS === $step
                ? '  <fg=gray>['.$this->stepNumber(2).'] Analyzing patterns</>'
                : '  <fg=gray>['.$this->stepNumber(3).'] Suggesting optimizations</>');
        }

        // A count with no total is redrawn batch after batch: a log would
        // get a line for each, so it is drawn on a terminal only.
        if (0 === $total || (null === $total && !$this->decorated)) {
            return;
        }

        // The scanning sub-steps share their lines: on a terminal the next
        // replaces the last, name and bar, in a log it starts lines of its own.
        if (!$main && null !== $this->line) {
            if ($this->decorated) {
                $this->line->clear();
            } else {
                $this->errors->newLine();
            }
        }

        // A log gets the name once, a terminal with each frame of the bar.
        if (!$this->decorated && isset(self::LABELS[$step])) {
            $this->errors->writeln('   '.self::LABELS[$step]);
        }

        $this->bar = $this->createBar($total);
        $this->line = $this->bar;
    }

    public function advance(int $count = 1): void
    {
        $this->count += $count;
        if (null === $this->bar) {
            return;
        }

        // The message first: the bar may redraw as it advances.
        $this->bar->setMessage($this->message());
        $this->bar->advance($count);
    }

    public function tick(): void
    {
        if ($this->decorated) {
            $this->bar?->display();
        }
    }

    public function workers(int $count): void
    {
        $this->workers = max($this->workers, $count);
    }

    public function finish(): void
    {
        if (self::STEP_FILES === $this->step) {
            $this->files = $this->count;
        }

        if (null !== $this->bar) {
            $this->bar->setMessage($this->message());
            $this->bar->finish();
            $this->bar = null;
        }

        if (self::STEP_ANALYSIS === $this->step || self::STEP_OPTIMIZATIONS === $this->step) {
            $this->endLine();
        }
    }

    /**
     * Ends the line the last bar stands on, if any is left open.
     */
    public function endLine(): void
    {
        if (null !== $this->line) {
            $this->errors->newLine();
            $this->line = null;
        }
    }

    /**
     * The files the run found to read.
     */
    public function files(): int
    {
        return $this->files;
    }

    /**
     * The most workers a step started, 1 when none was.
     */
    public function workersStarted(): int
    {
        return $this->workers;
    }

    private function createBar(?int $total): ProgressBar
    {
        $bar = $this->io->createProgressBar($total ?? 0);
        $format = null === $total ? ' %message% %elapsed:6s%' : ' %message% [%bar%] %percent:3s%% %elapsed:6s%';
        // The name stands above the bar, redrawn with it.
        if ($this->decorated && isset(self::LABELS[$this->step])) {
            $format = "   %label%\n".$format;
            $bar->setMessage(self::LABELS[$this->step], 'label');
        }
        $bar->setFormat($format);
        $bar->setBarWidth(self::BAR_WIDTH);
        $bar->setProgressCharacter('▓');
        $bar->setEmptyBarCharacter('░');
        $bar->setMessage($this->message());
        $bar->start();

        return $bar;
    }

    private function message(): string
    {
        return str_pad(null === $this->total ? $this->count.' found' : $this->count.'/'.$this->total, self::MESSAGE_PAD_LENGTH, ' ', \STR_PAD_LEFT);
    }

    private function stepNumber(int $step): string
    {
        return $step.'/'.($this->optimizations ? 3 : 2);
    }
}

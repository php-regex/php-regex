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

namespace PHPRegex\Laravel\Output;

use Illuminate\Console\OutputStyle;
use PHPRegex\Linter\LintProgress;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The progress of regex:lint, drawn with a console progress bar.
 *
 * The scan steps (files found, declarations, functions, patterns) share a
 * bar named on the line above it, both redrawn in place on a terminal, so
 * that every bar starts in the same column; the analysis and the
 * optimizations each get a heading and a bar of their own. The clock is
 * redrawn while the run waits on a terminal only: a log gets no line more.
 *
 * @internal
 */
final class LintProgressBar implements LintProgress
{
    private const BAR_WIDTH = 28;
    private const COUNT_PAD_LENGTH = 15;
    private const FORMAT_BAR = ' %message% [%bar%] %percent:3s%% %elapsed:6s%';
    private const FORMAT_COUNTER = ' %message% %elapsed:6s%';

    /**
     * The sub-steps of the scan, by step.
     */
    private const LABELS = [
        self::STEP_FILES => 'Finding files',
        self::STEP_DECLARATIONS => 'Declarations',
        self::STEP_FUNCTIONS => 'Functions',
        self::STEP_PATTERNS => 'Patterns',
    ];

    /**
     * The steps that follow the scan: their number and their heading.
     */
    private const HEADINGS = [
        self::STEP_ANALYSIS => [2, 'Analyzing patterns'],
        self::STEP_OPTIMIZATIONS => [3, 'Suggesting optimizations'],
    ];

    /**
     * Where the bar is drawn: the error output of a console.
     */
    private readonly OutputInterface $stream;

    private ProgressBar $bar;

    /**
     * The step is drawn and not finished.
     */
    private bool $running = false;

    /**
     * The step has a line: not one of nothing, nor, in a log, a counter whose
     * every redraw would add a line.
     */
    private bool $drawn = false;

    /**
     * The last line of the bar is not ended yet.
     */
    private bool $lineOpen = false;

    private string $step = '';

    private string $label = '';

    private ?int $total = null;

    private int $count = 0;

    private int $filesFound = 0;

    private int $workersStarted = 1;

    public function __construct(private readonly OutputStyle $output, private readonly int $steps)
    {
        $stream = $output->getOutput();
        $this->stream = $stream instanceof ConsoleOutputInterface ? $stream->getErrorOutput() : $stream;
        $this->bar = $this->createBar();
    }

    public function step(string $step, ?int $total): void
    {
        $this->step = $step;
        $this->total = $total;
        $this->count = 0;

        if (isset(self::HEADINGS[$step])) {
            [$number, $heading] = self::HEADINGS[$step];
            $this->close();
            $this->output->newLine();
            $this->output->writeln(\sprintf('  <fg=gray>[%d/%d] %s</>', $number, $this->steps, $heading));
            $this->label = '';
            $this->bar = $this->createBar();
        } else {
            // On a terminal the next scan step takes over the line in place;
            // in a log it starts a line of its own.
            if (!$this->stream->isDecorated()) {
                $this->close();
            }
            $this->label = self::LABELS[$step] ?? $step;
        }

        $this->drawn = 0 !== $total && (null !== $total || $this->stream->isDecorated());
        if (!$this->drawn) {
            return;
        }

        // The name stands above the bar: in a log written once, on a
        // terminal redrawn with each frame.
        $format = null === $total ? self::FORMAT_COUNTER : self::FORMAT_BAR;
        if ('' !== $this->label) {
            if ($this->stream->isDecorated()) {
                $format = "   %label%\n".$format;
                $this->bar->setMessage($this->label, 'label');
            } else {
                $this->stream->writeln('   '.$this->label);
            }
        }
        $this->bar->setFormat($format);
        $this->bar->setMessage($this->message());
        $this->bar->start($total ?? 0);
        $this->running = true;
        $this->lineOpen = true;
    }

    public function advance(int $count = 1): void
    {
        $this->count += $count;
        if (!$this->drawn) {
            return;
        }

        $this->bar->setMessage($this->message());
        $this->bar->advance($count);
    }

    public function tick(): void
    {
        if ($this->running && $this->stream->isDecorated()) {
            $this->bar->display();
        }
    }

    public function workers(int $count): void
    {
        $this->workersStarted = max($this->workersStarted, $count);
    }

    public function finish(): void
    {
        if (self::STEP_FILES === $this->step) {
            $this->filesFound = $this->count;
        }

        if (!$this->drawn) {
            return;
        }

        $this->count = $this->total ?? $this->count;
        $this->bar->setMessage($this->message());
        $this->bar->finish();
        $this->running = false;
    }

    /**
     * Ends the line the bar is drawn on, before anything else is written.
     */
    public function close(): void
    {
        if ($this->lineOpen) {
            $this->stream->writeln('');
            $this->lineOpen = false;
        }
    }

    /**
     * The files the scan found.
     */
    public function filesFound(): int
    {
        return $this->filesFound;
    }

    /**
     * The most workers any step of the run started, 1 when none forked.
     */
    public function workersStarted(): int
    {
        return $this->workersStarted;
    }

    private function createBar(): ProgressBar
    {
        $bar = $this->output->createProgressBar();
        $bar->setBarWidth(self::BAR_WIDTH);
        $bar->setProgressCharacter('▓');
        $bar->setEmptyBarCharacter('░');

        return $bar;
    }

    private function message(): string
    {
        $count = null === $this->total ? $this->count.' found' : $this->count.'/'.$this->total;

        return str_pad($count, self::COUNT_PAD_LENGTH, ' ', \STR_PAD_LEFT);
    }
}

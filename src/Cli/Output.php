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

namespace PHPRegex\Cli;

/**
 * @internal
 */
final class Output
{
    public const RESET = "\033[0m";
    public const RED = "\033[31m";
    public const GREEN = "\033[32m";
    public const YELLOW = "\033[33m";
    public const BLUE = "\033[34m";
    public const MAGENTA = "\033[35m";
    public const CYAN = "\033[36m";
    public const WHITE = "\033[37m";
    public const GRAY = "\033[90m";
    public const BLACK = "\033[30m";
    public const BOLD = "\033[1m";
    public const BG_RED = "\033[41m";
    public const BG_GREEN = "\033[42m";
    public const BG_YELLOW = "\033[43m";
    public const BG_BLUE = "\033[44m";
    public const BG_CYAN = "\033[46m";
    public const BG_GRAY = "\033[100m";

    private const PROGRESS_BAR_WIDTH = 28;

    /**
     * Null while the total is not known.
     */
    private ?int $progressTotal = 0;

    private int $progressCurrent = 0;

    private bool $progressActive = false;

    private float $progressStartedAt = 0.0;

    private ?string $progressLabel = null;

    /**
     * The name standing on the line above the bar, once drawn.
     */
    private ?string $progressLabelDrawn = null;

    /**
     * The width of the line last drawn, which the next one overwrites.
     */
    private int $progressLineWidth = 0;

    private bool $documentWritten = false;

    /**
     * @param resource|null $errorStream where writeError() and the progress write; STDERR by default
     */
    public function __construct(
        private bool $ansi,
        private bool $quiet,
        private readonly string $progressBarFull = '#',
        private readonly string $progressBarEmpty = '-',
        private $errorStream = null,
    ) {}

    public function isAnsi(): bool
    {
        return $this->ansi;
    }

    public function setAnsi(bool $ansi): void
    {
        $this->ansi = $ansi;
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    public function setQuiet(bool $quiet): void
    {
        $this->quiet = $quiet;
    }

    public function write(string $text): void
    {
        if (!$this->quiet) {
            echo $text;
        }
    }

    /**
     * Write a document a program reads, a JSON report: quiet mode silences
     * the status lines around it, never the document itself.
     */
    public function writeDocument(string $text): void
    {
        $this->documentWritten = true;
        echo $text;
    }

    /**
     * Whether a document was written: a run prints one at most.
     */
    public function hasWrittenDocument(): bool
    {
        return $this->documentWritten;
    }

    /**
     * Write to the error stream, which stays apart from a report on stdout.
     * Quiet mode silences stdout only: an error still reaches the user.
     */
    public function writeError(string $text): void
    {
        $stream = $this->errorStream();
        if (\is_resource($stream)) {
            fwrite($stream, $text);
        }
    }

    public function color(string $text, string $color): string
    {
        return $this->ansi ? $color.$text.self::RESET : $text;
    }

    public function success(string $text): string
    {
        return $this->color($text, self::GREEN);
    }

    public function error(string $text): string
    {
        return $this->color($text, self::RED);
    }

    public function warning(string $text): string
    {
        return $this->color($text, self::YELLOW);
    }

    public function info(string $text): string
    {
        return $this->color($text, self::BLUE);
    }

    public function bold(string $text): string
    {
        return $this->color($text, self::BOLD);
    }

    public function dim(string $text): string
    {
        return $this->color($text, self::GRAY);
    }

    public function badge(string $text, string $fg, string $bg): string
    {
        if (!$this->ansi) {
            return \sprintf('%-4s', $text);
        }

        return $this->color(' '.$text.' ', $bg.$fg.self::BOLD);
    }

    /**
     * Starts a progress line, drawn on the error stream; a null total is
     * not known yet: the line counts what was found, with no bar. Started
     * while another is drawn, it takes that line over.
     */
    public function progressStart(?int $total): void
    {
        if ($this->shouldSkipProgress($total)) {
            return;
        }

        $this->initializeProgress($total);
    }

    /**
     * Names what the bar counts, on the line above it, from its next frame
     * on until the progress finishes.
     */
    public function progressLabel(string $label): void
    {
        $this->progressLabel = $label;
    }

    public function progressAdvance(int $step = 1): void
    {
        if (!$this->progressActive) {
            return;
        }

        $this->progressCurrent += $step;
        if (null !== $this->progressTotal) {
            $this->progressCurrent = min($this->progressTotal, $this->progressCurrent);
        }
        $this->renderProgress();
    }

    /**
     * Redraws the time spent, the count unchanged.
     */
    public function progressTick(): void
    {
        if ($this->progressActive) {
            $this->renderProgress();
        }
    }

    public function progressFinish(): void
    {
        if ($this->progressActive) {
            if (null !== $this->progressTotal) {
                $this->progressCurrent = $this->progressTotal;
            }
            $this->renderProgress(true);
            $this->progressActive = false;
        }

        $this->progressLabel = null;
        $this->progressLabelDrawn = null;
    }

    /**
     * Ends the line where the progress stopped, the work not done: what is
     * written next starts a line of its own.
     */
    public function progressAbandon(): void
    {
        if ($this->progressActive) {
            $this->writeProgress("\n");
            $this->progressActive = false;
        }

        $this->progressLabel = null;
        $this->progressLabelDrawn = null;
    }

    private function shouldSkipProgress(?int $total): bool
    {
        return (null !== $total && $total <= 0) || $this->quiet;
    }

    private function initializeProgress(?int $total): void
    {
        if (!$this->progressActive) {
            $this->progressLineWidth = 0;
        }

        $this->progressTotal = $total;
        $this->progressCurrent = 0;
        $this->progressActive = $this->ansi;
        $this->progressStartedAt = microtime(true);

        if ($this->progressActive) {
            $this->renderProgress();
        }
    }

    private function renderProgress(bool $finish = false): void
    {
        $elapsed = $this->formatElapsed((int) (microtime(true) - $this->progressStartedAt));

        if (null === $this->progressTotal) {
            $line = \sprintf(' %s %8s', str_pad($this->progressCurrent.' found', 15, ' ', \STR_PAD_LEFT), $elapsed);
        } else {
            $line = \sprintf(' %s [%s] %3d%% %8s', $this->formatProgressStatus($this->progressTotal), $this->buildProgressBar($this->progressTotal), $this->calculateProgressPercent($this->progressTotal), $elapsed);
        }

        // The name stands on a line of its own above the bar, so that the
        // bar starts where an unnamed step's does. A new name erases the
        // bar's line, goes up to erase the last name's, and draws both.
        $head = '';
        if ($this->progressLabel !== $this->progressLabelDrawn) {
            $head = null === $this->progressLabelDrawn ? "\r\033[2K" : "\r\033[2K\033[1A\r\033[2K";
            if (null !== $this->progressLabel) {
                $head .= '   '.$this->progressLabel."\n";
            }
            $this->progressLabelDrawn = $this->progressLabel;
            $this->progressLineWidth = 0;
        }

        // A shorter line than the one it replaces clears what is left of it.
        $width = mb_strlen($line);
        $line .= str_repeat(' ', max(0, $this->progressLineWidth - $width));
        $this->progressLineWidth = $width;

        $this->writeProgress($head."\r".$line.($finish ? "\n\n" : ''));
    }

    private function buildProgressBar(int $total): string
    {
        $total = max(1, $total);
        $current = min($this->progressCurrent, $total);
        $filled = (int) floor(($current / $total) * self::PROGRESS_BAR_WIDTH);

        return str_repeat($this->progressBarFull, $filled)
            .str_repeat($this->progressBarEmpty, self::PROGRESS_BAR_WIDTH - $filled);
    }

    private function calculateProgressPercent(int $total): int
    {
        $total = max(1, $total);
        $current = min($this->progressCurrent, $total);

        return (int) round(($current / $total) * 100);
    }

    private function formatProgressStatus(int $total): string
    {
        $total = max(1, $total);
        $current = min($this->progressCurrent, $total);

        return str_pad($current.'/'.$total, 15, ' ', \STR_PAD_LEFT);
    }

    /**
     * The progress shares the error stream with the status lines: stdout
     * keeps the report alone.
     */
    private function writeProgress(string $text): void
    {
        $stream = $this->errorStream();
        if (\is_resource($stream)) {
            fwrite($stream, $text);
            fflush($stream);
        }
    }

    /**
     * @return resource|null
     */
    private function errorStream()
    {
        return $this->errorStream ?? (\defined('STDERR') ? \STDERR : null);
    }

    private function formatElapsed(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return \sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }

        return \sprintf('%02d:%02d', $minutes, $secs);
    }
}

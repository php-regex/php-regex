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

namespace RegexParser\Cli;

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

    private int $progressTotal = 0;

    private int $progressCurrent = 0;

    private bool $progressActive = false;

    private int $progressStartedAt = 0;

    /**
     * @param resource|null $errorStream where writeError() writes; STDERR by default
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
     * Write to the error stream, which stays apart from a report on stdout.
     * Quiet mode silences stdout only: an error still reaches the user.
     */
    public function writeError(string $text): void
    {
        $stream = $this->errorStream ?? (\defined('STDERR') ? \STDERR : null);
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

    public function progressStart(int $total): void
    {
        if ($this->shouldSkipProgress($total)) {
            return;
        }

        $this->initializeProgress($total);
    }

    public function progressAdvance(int $step = 1): void
    {
        if (!$this->progressActive) {
            return;
        }

        $this->progressCurrent = min($this->progressTotal, $this->progressCurrent + $step);
        $this->renderProgress();
    }

    public function progressFinish(): void
    {
        if (!$this->progressActive) {
            return;
        }

        $this->progressCurrent = $this->progressTotal;
        $this->renderProgress(true);
        $this->progressActive = false;
    }

    private function shouldSkipProgress(int $total): bool
    {
        return $total <= 0 || $this->quiet;
    }

    private function initializeProgress(int $total): void
    {
        $this->progressTotal = $total;
        $this->progressCurrent = 0;
        $this->progressActive = $this->ansi;
        $this->progressStartedAt = (int) time();

        if ($this->progressActive) {
            $this->renderProgress();
        }
    }

    private function renderProgress(bool $finish = false): void
    {
        $bar = $this->buildProgressBar();
        $percent = $this->calculateProgressPercent();
        $elapsed = $this->formatElapsed((int) (time() - $this->progressStartedAt));
        $status = $this->formatProgressStatus();

        $line = \sprintf(' %s [%s] %3d%% %8s', $status, $bar, $percent, $elapsed);

        $this->write("\r".$line);

        if ($finish) {
            $this->write("\n\n");
        }

        $this->flushOutput();
    }

    private function buildProgressBar(): string
    {
        $total = max(1, $this->progressTotal);
        $current = min($this->progressCurrent, $total);
        $filled = (int) floor(($current / $total) * self::PROGRESS_BAR_WIDTH);

        return str_repeat($this->progressBarFull, $filled)
            .str_repeat($this->progressBarEmpty, self::PROGRESS_BAR_WIDTH - $filled);
    }

    private function calculateProgressPercent(): int
    {
        $total = max(1, $this->progressTotal);
        $current = min($this->progressCurrent, $total);

        return (int) round(($current / $total) * 100);
    }

    private function formatProgressStatus(): string
    {
        $total = max(1, $this->progressTotal);
        $current = min($this->progressCurrent, $total);

        return str_pad($current.'/'.$total, 15, ' ', \STR_PAD_LEFT);
    }

    private function flushOutput(): void
    {
        if (\function_exists('fflush')) {
            fflush(\STDOUT);
        }
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

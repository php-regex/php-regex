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

namespace PHPRegex\Linter\Formatter;

use PHPRegex\Linter\LintReport;

/**
 * The parts `regex lint`'s console report and the bridges' write alike,
 * whatever their markup: an issue under its badge, its hint, the badge of a
 * suggested rewrite, and the line that sums the run up.
 *
 * @phpstan-import-type LintStats from LintReport
 *
 * @internal
 */
final class ConsoleLayout
{
    public const ARROW = "\u{21B3}";

    public function __construct(private readonly ConsoleMarkup $markup) {}

    /**
     * The badge of an issue at a severity level: anything below a warning
     * is an info.
     */
    public function issueBadge(string $level): string
    {
        return $this->markup->badge(match ($level) {
            'error' => ConsoleBadge::Fail,
            'warning' => ConsoleBadge::Warn,
            default => ConsoleBadge::Info,
        });
    }

    /**
     * The first line of a message beside its badge; each line under it
     * muted, the first behind an arrow, with each "Line N:" blanked out so
     * the caret under it keeps its column.
     */
    public function issue(string $badge, string $message): string
    {
        $lines = explode("\n", $message);
        $first = array_shift($lines);

        $output = '    '.$badge.' '.$this->markup->style($this->markup->escape($first), ConsoleStyle::Plain).\PHP_EOL;
        foreach ($lines as $index => $line) {
            $output .= '         '.$this->markup->muted(0 === $index ? self::ARROW : ' ', $this->markup->escape(ConsoleResultText::withoutLineLabels($line))).\PHP_EOL;
        }

        return $output;
    }

    /**
     * The fix or the evidence under an issue, behind an arrow.
     */
    public function hint(string $hint): string
    {
        return '         '.$this->markup->style(self::ARROW.' '.$this->markup->escape($hint), ConsoleStyle::Muted).\PHP_EOL;
    }

    /**
     * The badge above a suggested rewrite.
     */
    public function tip(): string
    {
        return '    '.$this->markup->badge(ConsoleBadge::Tip).\PHP_EOL;
    }

    /**
     * The run summed up after a blank line: what fails it, else what it
     * found and the rewrites it suggests.
     *
     * @phpstan-param LintStats $stats
     */
    public function summary(array $stats): string
    {
        [$badge, $lead, $leadStyle, $rest] = match (true) {
            $stats['errors'] > 0 => [ConsoleBadge::Fail, LintSummary::errors($stats), ConsoleStyle::ErrorBold, ', '.LintSummary::failureCounts($stats).'.'],
            $stats['warnings'] > 0 => [ConsoleBadge::PassWithWarnings, LintSummary::passCounts($stats), ConsoleStyle::WarningBold, \sprintf(', %d optimizations available.', $stats['optimizations'])],
            default => [ConsoleBadge::Pass, LintSummary::passCounts($stats), ConsoleStyle::SuccessBold, \sprintf(', %d optimizations available.', $stats['optimizations'])],
        };

        return \PHP_EOL.'  '.$this->markup->badge($badge).' '.$this->markup->style($lead, $leadStyle).$this->markup->style($rest, ConsoleStyle::Muted).\PHP_EOL;
    }
}

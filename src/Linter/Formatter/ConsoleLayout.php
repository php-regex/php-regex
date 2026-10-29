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

use PHPRegex\Linter\Advice\RedosHints;
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

    /**
     * The column the text of a hint starts at, after its arrow.
     */
    private const HINT_COLUMN = 11;

    /**
     * The column the text of a suggestion starts at, after its dash.
     */
    private const ITEM_COLUMN = 15;

    /**
     * The column a message starts at, after its badge.
     */
    private const MESSAGE_COLUMN = 9;

    /**
     * The hint line that is never folded: a pattern excerpt reads whole.
     */
    private const RISKY_PART = 'The risky part is: ';

    public function __construct(private readonly ConsoleMarkup $markup, private readonly int $width = ConsoleWidth::DEFAULT) {}

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
     * The first line of a message beside its badge, folded into the width
     * under it; each line under it muted, the first behind an arrow, with
     * each "Line N:" blanked out so the caret under it keeps its column.
     */
    public function issue(string $badge, string $message): string
    {
        $lines = explode("\n", $message);
        $first = array_shift($lines);

        $output = '';
        foreach (TextWrap::lines($first, $this->width - self::MESSAGE_COLUMN) as $index => $line) {
            $output .= (0 === $index ? '    '.$badge.' ' : str_repeat(' ', self::MESSAGE_COLUMN)).$this->markup->style($this->markup->escape($line), ConsoleStyle::Plain).\PHP_EOL;
        }
        foreach ($lines as $index => $line) {
            $output .= '         '.$this->markup->muted(0 === $index ? self::ARROW : ' ', $this->markup->escape(ConsoleResultText::withoutLineLabels($line))).\PHP_EOL;
        }

        return $output;
    }

    /**
     * The fix or the evidence under an issue: each line behind an arrow,
     * folded into the width with its continuation under its text; the
     * rewrites to try once under one heading, as dashed items; the risky
     * part whole, whatever its width.
     *
     * @param list<string> $hints
     */
    public function hints(array $hints): string
    {
        $output = '';
        $count = \count($hints);
        for ($i = 0; $i < $count; $i++) {
            $hint = $hints[$i];
            if (str_starts_with($hint, RedosHints::SUGGESTION)) {
                $output .= $this->hintLines([rtrim(RedosHints::SUGGESTION)]);
                for (; $i < $count && str_starts_with($hints[$i], RedosHints::SUGGESTION); $i++) {
                    $output .= $this->itemLines(TextWrap::lines(substr($hints[$i], \strlen(RedosHints::SUGGESTION)), $this->width - self::ITEM_COLUMN));
                }
                $i--;

                continue;
            }

            $output .= $this->hintLines(str_starts_with($hint, self::RISKY_PART) ? [$hint] : TextWrap::lines($hint, $this->width - self::HINT_COLUMN));
        }

        return $output;
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

    /**
     * One hint: its first line behind the arrow, the others under its text.
     *
     * @param list<string> $lines
     */
    private function hintLines(array $lines): string
    {
        $output = '';
        foreach ($lines as $index => $line) {
            $output .= 0 === $index
                ? '         '.$this->markup->style(self::ARROW.' '.$this->markup->escape($line), ConsoleStyle::Muted).\PHP_EOL
                : str_repeat(' ', self::HINT_COLUMN).$this->markup->style($this->markup->escape($line), ConsoleStyle::Muted).\PHP_EOL;
        }

        return $output;
    }

    /**
     * One rewrite to try: its first line behind a dash, the others under
     * its text.
     *
     * @param list<string> $lines
     */
    private function itemLines(array $lines): string
    {
        $output = '';
        foreach ($lines as $index => $line) {
            $output .= 0 === $index
                ? str_repeat(' ', self::ITEM_COLUMN - 2).$this->markup->style('- '.$this->markup->escape($line), ConsoleStyle::Muted).\PHP_EOL
                : str_repeat(' ', self::ITEM_COLUMN).$this->markup->style($this->markup->escape($line), ConsoleStyle::Muted).\PHP_EOL;
        }

        return $output;
    }
}

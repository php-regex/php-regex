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

namespace PHPRegex\Symfony\Analyzer\Formatter;

use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Symfony\Analyzer\AnalysisNotice;
use PHPRegex\Symfony\Analyzer\CheckOutcome;
use PHPRegex\Symfony\Analyzer\IssueDetail;
use PHPRegex\Symfony\Analyzer\ReportSection;
use PHPRegex\Symfony\Analyzer\SecurityReport;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
final readonly class ConsoleReportFormatter
{
    private const ARROW_LABEL = "\u{21B3}";
    private const BADGE_CRIT = '<bg=red;fg=white;options=bold> CRIT </>';
    private const BADGE_FAIL = '<bg=red;fg=white;options=bold> FAIL </>';
    private const BADGE_WARN = '<bg=yellow;fg=black;options=bold> WARN </>';
    private const BADGE_PASS = '<bg=green;fg=white;options=bold> PASS </>';
    private const SECTION_BADGE = '<bg=blue;fg=white;options=bold> %s </>';
    private const SUBSECTION_BADGE = '<fg=cyan;options=bold>%s</>';

    public function render(SecurityReport $report, SymfonyStyle $io, bool $showBanner = true, bool $debug = false): void
    {
        if ($showBanner) {
            $this->renderBanner($io);
        }

        foreach ($report->sections as $section) {
            $this->renderSection($io, $section, $debug);
        }

        if ($showBanner) {
            $this->renderFooter($io);
        }
    }

    public function renderBanner(SymfonyStyle $io): void
    {
        $io->writeln('<fg=cyan;options=bold>PHPRegex</> <fg=yellow>'.Regex::VERSION.'</> by Younes ENNAJI');
        $io->newLine();
    }

    public function renderFooter(SymfonyStyle $io): void
    {
        $message = 'If PHPRegex helps, a GitHub star is appreciated: ';
        $io->writeln('  <fg=gray>'.$message.'https://github.com/php-regex/regex-parser</>');
        $io->newLine();
    }

    private function renderSection(SymfonyStyle $io, ReportSection $section, bool $debug): void
    {
        $this->renderSectionHeader($io, $section->title);
        $io->newLine();

        $this->renderNotices($io, $section->warnings);
        $this->renderMeta($io, $section->meta);
        $this->renderNotices($io, $section->summary);

        foreach ($section->issues as $issue) {
            $io->writeln('  '.$this->badge($issue->severity).' <fg=white>'.$issue->title.'</>');

            foreach ($issue->details as $detail) {
                $value = $this->formatDetailValue($detail);
                $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$detail->label.':</> '.$value);
            }

            foreach ($issue->notes as $note) {
                $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' Note:</> '.$note);
            }

            $io->newLine();
        }

        if ([] !== $section->suggestions) {
            $this->renderSubsectionHeader($io, 'Suggestions');
            foreach ($section->suggestions as $suggestion) {
                $io->writeln('  <fg=gray>'.self::ARROW_LABEL.'</> '.$suggestion);
            }
            $io->newLine();
        }

        if ($debug && [] !== $section->debug) {
            $this->renderSubsectionHeader($io, 'Debug');
            $this->renderMeta($io, $section->debug);
        }
    }

    /**
     * @param array<int, AnalysisNotice> $notices
     */
    private function renderNotices(SymfonyStyle $io, array $notices): void
    {
        if ([] === $notices) {
            return;
        }

        foreach ($notices as $notice) {
            $io->writeln('  '.$this->badge($notice->severity).' <fg=white>'.$notice->message.'</>');
        }

        $io->newLine();
    }

    /**
     * @param array<string, int|string> $meta
     */
    private function renderMeta(SymfonyStyle $io, array $meta): void
    {
        if ([] === $meta) {
            return;
        }

        $labels = array_keys($meta);
        $maxLabelLength = max(array_map(strlen(...), $labels));

        foreach ($meta as $label => $value) {
            $io->writeln($this->formatMetaLine($label, (string) $value, $maxLabelLength));
        }

        $io->newLine();
    }

    private function badge(CheckOutcome $severity): string
    {
        return match ($severity) {
            CheckOutcome::Critical => self::BADGE_CRIT,
            CheckOutcome::Fail => self::BADGE_FAIL,
            CheckOutcome::Warn => self::BADGE_WARN,
            CheckOutcome::Pass => self::BADGE_PASS,
        };
    }

    private function formatDetailValue(IssueDetail $detail): string
    {
        return match ($detail->kind) {
            'example' => $this->formatExample($detail->value),
            'pattern' => $this->formatPattern($detail->value),
            default => $detail->value,
        };
    }

    private function formatExample(string $example): string
    {
        return DisplayEscaper::quote($example, '<fg=cyan>', '</>');
    }

    private function formatPattern(string $pattern): string
    {
        if ('' === $pattern) {
            return '<fg=cyan>""</>';
        }

        return '<fg=cyan>'.$pattern.'</>';
    }

    private function renderSectionHeader(SymfonyStyle $io, string $title): void
    {
        $io->writeln(\sprintf(self::SECTION_BADGE, $title));
    }

    private function renderSubsectionHeader(SymfonyStyle $io, string $title): void
    {
        $io->writeln(\sprintf(self::SUBSECTION_BADGE, $title));
    }

    private function formatMetaLine(string $label, string $value, int $maxLabelLength): string
    {
        return \sprintf(
            '<fg=white;options=bold>%s</> : <fg=yellow>%s</>',
            str_pad($label, $maxLabelLength),
            $value,
        );
    }
}

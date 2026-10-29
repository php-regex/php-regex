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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\Printer\DisplayEscaper;

/**
 * The console report of the framework bridges' regex:lint, in console tags.
 *
 * Laravel builds its console on Symfony's, so both bridges render the report
 * the same way. The parts it shares with `regex lint`'s report are the
 * console layout's, in tags; the file label links to the editor, and a
 * suggested rewrite is shown as its two patterns.
 *
 * @phpstan-import-type OptimizationEntry from LintReport
 * @phpstan-import-type LintResult from LintReport
 *
 * @internal
 */
final class ConsoleTagFormatter implements OutputFormatterInterface
{
    private readonly TagMarkup $markup;

    private ?ConsoleLayout $layout = null;

    public function __construct(
        private readonly AnalysisService $analysis,
        private readonly LinkFormatter $linkFormatter,
        private readonly bool $decorated = true,
        private readonly ?int $width = null,
    ) {
        $this->markup = new TagMarkup();
    }

    public function format(LintReport $report): string
    {
        // The width is read for each report written, not when the formatter
        // is built: a registry builds one for every format.
        $this->layout = null;

        $output = '';
        foreach ($report->results as $result) {
            $output .= $this->displayPatternContext($result);
            $output .= $this->displayIssues($result['issues'], ConsoleResultText::pattern($result));
            $output .= $this->displayOptimizations($result['optimizations']);
            $output .= "\n";
        }

        return $output.$this->layout()->summary($report->stats);
    }

    public function formatError(string $message): string
    {
        return $message;
    }

    /**
     * The file and line of a result, linked to the editor, its pattern on
     * the next line, and where the pattern was found when the source names
     * it.
     *
     * @phpstan-param LintResult $result
     */
    private function displayPatternContext(array $result): string
    {
        $pattern = ConsoleResultText::pattern($result);
        $line = $result['line'];
        $column = (int) ($result['column'] ?? 0);
        $file = $result['file'];
        $location = $result['location'] ?? null;

        $label = $this->linkFormatter->getRelativePath($file);
        if (!str_starts_with($label, '/')) {
            $label = './'.$label;
        }
        if ($line > 0) {
            $label .= ':'.$line;
            if ($column > 1) {
                $label .= ':'.$column;
            }
        }
        // The label is the file name in display form on one line, its tags
        // escaped; the link still points at the file as named.
        $label = $this->markup->escape(ReportSpelling::displayField($label));
        $output = '  '.$this->markup->style($this->linkFormatter->format($file, $line, $label, $column > 0 ? $column : 1, $label), ConsoleStyle::AccentBold)."\n";

        if (null !== $pattern && '' !== $pattern) {
            $output .= '      '.$this->markup->style('→ ', ConsoleStyle::AccentBold).$this->markup->style($this->safelyHighlightPattern($pattern), ConsoleStyle::Plain)."\n";
        }

        if (\is_string($location) && '' !== $location) {
            $output .= '     '.$this->markup->style(ConsoleLayout::ARROW.' '.$this->markup->escape(ReportSpelling::displayField($location)), ConsoleStyle::Muted)."\n";
        }

        return $output;
    }

    /**
     * The pattern, highlighted on a decorated console. A route requirement,
     * a pattern body with no delimiter, is shown as written, as the CLI
     * shows it: it does not highlight.
     */
    private function safelyHighlightPattern(string $pattern): string
    {
        // Control characters are escaped, so none lays the line out.
        $escapedPattern = DisplayEscaper::escape($pattern);

        // Highlighting reads the pattern as written: once its display form
        // differs (escapes, an x pattern put on one line), the display form
        // is shown as is.
        if (!$this->decorated || $escapedPattern !== $pattern || str_contains($escapedPattern, '\\')) {
            return $this->markup->escape($escapedPattern);
        }

        try {
            return $this->markup->escape($this->analysis->highlight($pattern));
        } catch (\Throwable) {
            return $this->markup->escape($escapedPattern);
        }
    }

    /**
     * The layout of the report being written.
     */
    private function layout(): ConsoleLayout
    {
        return $this->layout ??= new ConsoleLayout($this->markup, $this->width ?? ConsoleWidth::detect());
    }

    /**
     * @phpstan-param array<LintIssue> $issues
     */
    private function displayIssues(array $issues, ?string $pattern): string
    {
        $output = '';
        foreach ($issues as $issue) {
            $output .= $this->layout()->issue($this->layout()->issueBadge($issue->severity->level()), ConsoleResultText::messageWithSnippet($issue, $pattern));

            // An invalid pattern shows the fix its validation names; a ReDoS
            // error its hint, which opens on the evidence, and a lint rule
            // at Error its fix.
            $hints = null !== $issue->validation ? [$issue->validation->hint] : $issue->hints;
            $hints = array_values(array_filter(array_map(static fn (?string $hint): string => ReportSpelling::displayText((string) $hint), $hints), static fn (string $hint): bool => '' !== $hint));
            if ([] !== $hints) {
                $output .= $this->layout()->hints($hints);
            }
        }

        return $output;
    }

    /**
     * @phpstan-param array<OptimizationEntry> $optimizations
     */
    private function displayOptimizations(array $optimizations): string
    {
        $output = '';
        foreach ($optimizations as $entry) {
            $optimization = $entry['optimization'];
            $output .= $this->layout()->tip();
            // An entry a caller built by hand may hold no rewrite: its badge
            // stands alone.
            if (!$optimization instanceof OptimizationResult) {
                continue;
            }
            $output .= '         '.$this->markup->style('- '.$this->safelyHighlightPattern($optimization->original), ConsoleStyle::Error)."\n";
            $output .= '         '.$this->markup->style('+ '.$this->safelyHighlightPattern($optimization->optimized), ConsoleStyle::Success)."\n";
        }

        return $output;
    }
}

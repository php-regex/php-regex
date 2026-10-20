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
use PHPRegex\Parser\Text\DisplayEscaper;

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

    private readonly ConsoleLayout $layout;

    public function __construct(
        private readonly AnalysisService $analysis,
        private readonly LinkFormatter $linkFormatter,
        private readonly bool $decorated = true,
    ) {
        $this->markup = new TagMarkup();
        $this->layout = new ConsoleLayout($this->markup);
    }

    public function format(LintReport $report): string
    {
        $output = '';
        foreach ($report->results as $result) {
            $output .= $this->displayPatternContext($result);
            $output .= $this->displayIssues($result['issues'], ConsoleResultText::pattern($result));
            $output .= $this->displayOptimizations($result['optimizations']);
            $output .= \PHP_EOL;
        }

        return $output.$this->layout->summary($report->stats);
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
        $output = '  '.$this->markup->style($this->linkFormatter->format($file, $line, $label, $column > 0 ? $column : 1, $label), ConsoleStyle::AccentBold).\PHP_EOL;

        if (null !== $pattern && '' !== $pattern) {
            $output .= '      '.$this->markup->style('→ ', ConsoleStyle::AccentBold).$this->markup->style($this->safelyHighlightPattern($pattern), ConsoleStyle::Plain).\PHP_EOL;
        }

        if (\is_string($location) && '' !== $location) {
            $output .= '     '.$this->markup->style(ConsoleLayout::ARROW.' '.$this->markup->escape(ReportSpelling::displayField($location)), ConsoleStyle::Muted).\PHP_EOL;
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
     * @phpstan-param array<LintIssue> $issues
     */
    private function displayIssues(array $issues, ?string $pattern): string
    {
        $output = '';
        foreach ($issues as $issue) {
            $output .= $this->layout->issue($this->layout->issueBadge($issue->severity->level()), ConsoleResultText::messageWithSnippet($issue, $pattern));

            // An invalid pattern shows the fix its validation names; a ReDoS
            // error its hint, which opens on the evidence, and a lint rule
            // at Error its fix.
            $hint = null !== $issue->validation ? $issue->validation->hint : $issue->hint;
            if (null !== $hint && '' !== $hint) {
                $output .= $this->layout->hint(ReportSpelling::displayText($hint));
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
            $output .= $this->layout->tip();
            // An entry a caller built by hand may hold no rewrite: its badge
            // stands alone.
            if (!$optimization instanceof OptimizationResult) {
                continue;
            }
            $output .= '         '.$this->markup->style('- '.$this->safelyHighlightPattern($optimization->original), ConsoleStyle::Error).\PHP_EOL;
            $output .= '         '.$this->markup->style('+ '.$this->safelyHighlightPattern($optimization->optimized), ConsoleStyle::Success).\PHP_EOL;
        }

        return $output;
    }
}

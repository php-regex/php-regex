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
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\Text\Ascii;

/**
 * The console report of `regex lint`, in ANSI colors when the terminal
 * takes them, at the verbosity asked for: the patterns with their issues
 * and their suggested rewrites, each rewrite shown as a diff.
 *
 * @phpstan-import-type OptimizationEntry from LintReport
 * @phpstan-import-type LintResult from LintReport
 * @phpstan-import-type LintStats from LintReport
 *
 * @internal
 */
final class ConsoleFormatter extends AbstractOutputFormatter
{
    private readonly AnsiMarkup $markup;

    private readonly ConsoleLayout $layout;

    public function __construct(
        private readonly ?AnalysisService $analysisService = null,
        OutputConfiguration $config = new OutputConfiguration(),
        private readonly string $ide = '',
        private readonly ?LinkFormatter $linkFormatter = null,
    ) {
        parent::__construct($config);
        $this->markup = new AnsiMarkup($config->ansi);
        $this->layout = new ConsoleLayout($this->markup);
    }

    public function format(LintReport $report): string
    {
        if (OutputConfiguration::VERBOSITY_QUIET === $this->config->verbosity) {
            return $this->formatQuiet($report);
        }

        $parts = [];
        foreach ($this->groupResults($report->results) as $results) {
            foreach ($results as $result) {
                $parts[] = $this->formatPatternContext($result);
                $parts[] = $this->formatIssues($result['issues'], ConsoleResultText::pattern($result));
                $parts[] = $this->formatOptimizations($result['optimizations']);
                $parts[] = \PHP_EOL;
            }
        }

        return implode('', $parts);
    }

    /**
     * @phpstan-param LintStats $stats
     */
    public function getSummary(array $stats): string
    {
        return $this->layout->summary($stats);
    }

    public function formatFooter(): string
    {
        return '  '.$this->markup->style('If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex', ConsoleStyle::Muted).\PHP_EOL.\PHP_EOL;
    }

    private function formatQuiet(LintReport $report): string
    {
        if ($report->stats['errors'] > 0) {
            return \sprintf('FAIL: %s, %s.'.\PHP_EOL, LintSummary::errors($report->stats), LintSummary::failureCounts($report->stats));
        }

        return \sprintf('%s: %s, %d optimizations available.'.\PHP_EOL, $report->stats['warnings'] > 0 ? 'WARN' : 'PASS', LintSummary::passCounts($report->stats), $report->stats['optimizations']);
    }

    /**
     * The file and line of a result, its pattern on the next line, and
     * where the pattern was found when the source names it.
     *
     * @phpstan-param LintResult $result
     */
    private function formatPatternContext(array $result): string
    {
        $pattern = ConsoleResultText::pattern($result);
        $file = $result['file'];
        $line = $result['line'];
        $column = (int) ($result['column'] ?? 0);
        $location = $result['location'] ?? null;

        // A compact "src/Console/Command.php:679" prefix, the file name in
        // display form, on one line.
        $prefix = '';
        if ('' !== $file && $line > 0) {
            $prefix = ReportSpelling::displayField($file).':'.$line;
            if ($column > 1) {
                $prefix .= ':'.$column;
            }
            if ('' !== $this->ide && null !== $this->linkFormatter) {
                $prefix .= ' '.$this->formatPenLink($file, $line, $column > 0 ? $column : 1);
            }
        } elseif ('' !== $file) {
            $prefix = ReportSpelling::displayField($file);
        } elseif ($line > 0) {
            $prefix = (string) $line;
        }

        $hasPattern = null !== $pattern && '' !== $pattern;
        $label = '' !== $prefix ? $prefix : ($hasPattern ? '(pattern)' : '(pattern unavailable)');
        $output = '  '.$this->markup->style($label, ConsoleStyle::AccentBold).\PHP_EOL;
        if ($hasPattern) {
            $output .= '      '.$this->markup->style('→ ', ConsoleStyle::AccentBold).$this->formatPatternForDisplay($pattern).\PHP_EOL;
        }

        if (\is_string($location) && '' !== $location) {
            $output .= '     '.$this->markup->style(ConsoleLayout::ARROW.' '.ReportSpelling::displayField($location), ConsoleStyle::Accent).\PHP_EOL;
        }

        return $output;
    }

    /**
     * @phpstan-param array<LintIssue> $issues
     */
    private function formatIssues(array $issues, ?string $pattern): string
    {
        $parts = [];
        foreach ($issues as $issue) {
            $parts[] = $this->layout->issue($this->layout->issueBadge($issue->severity->level()), ConsoleResultText::messageWithSnippet($issue, $pattern));

            // An invalid pattern shows the fix its validation names; a ReDoS
            // error its hint, which opens on the evidence, and a lint rule
            // at Error its fix.
            $hint = null !== $issue->validation ? $issue->validation->hint : $issue->hint;
            if (null === $hint || '' === $hint || !$this->config->shouldShowHints()) {
                continue;
            }

            $hint = ReportSpelling::displayText($this->formatHint($hint));
            if ('' !== $hint) {
                $parts[] = $this->layout->hint($hint);
            }
        }

        return implode('', $parts);
    }

    /**
     * @phpstan-param array<OptimizationEntry> $optimizations
     */
    private function formatOptimizations(array $optimizations): string
    {
        if (!$this->config->shouldShowOptimizations()) {
            return '';
        }

        $parts = [];
        foreach ($optimizations as $entry) {
            $parts[] = $this->layout->tip();
            // An entry a caller built by hand may hold no rewrite: its badge
            // stands alone.
            if ($entry['optimization'] instanceof OptimizationResult) {
                $parts[] = $this->formatOptimizationDiff($entry['optimization']);
            }
        }

        return implode('', $parts);
    }

    /**
     * A rewrite on one line as the two lines it changes, the part they
     * share dimmed; a pattern under x as the lines of the layout it keeps.
     */
    private function formatOptimizationDiff(OptimizationResult $optimization): string
    {
        $original = $optimization->original;
        $optimized = $optimization->optimized;
        if (self::isExtendedModePattern($original) || self::isExtendedModePattern($optimized)) {
            return $this->formatMultilineDiff($original, $optimized);
        }

        [$old, $new] = $this->computeSimpleDiff(DisplayEscaper::escape($original), DisplayEscaper::escape($optimized));

        return $this->formatDiffLine('-', $old, ConsoleStyle::Error).$this->formatDiffLine('+', $new, ConsoleStyle::Success);
    }

    /**
     * Every line is printed with its marker, none elided: a diff that
     * elides invites pasting a truncated rewrite.
     */
    private function formatMultilineDiff(string $old, string $new): string
    {
        // Each line is spelled in its pattern's mode, so no character that
        // moves or hides text reaches the terminal raw; the tabs that only
        // lay the pattern out are shown as the indent they are.
        $oldLines = array_map(static fn (string $line): string => ReportSpelling::displayFragment($line, $old), self::splitLines(ReportSpelling::expandLayoutTabs($old)));
        $newLines = array_map(static fn (string $line): string => ReportSpelling::displayFragment($line, $new), self::splitLines(ReportSpelling::expandLayoutTabs($new)));

        $output = '';
        $block = [];
        foreach (LineDiff::lines($oldLines, $newLines) as $op) {
            if ('equal' !== $op['type']) {
                $block[] = $op;

                continue;
            }

            $output .= $this->formatDiffChangeBlock($block);
            $block = [];
            $output .= $this->formatDiffLine(' ', $this->markup->style($op['line'], ConsoleStyle::Muted), ConsoleStyle::Muted);
        }

        return $output.$this->formatDiffChangeBlock($block);
    }

    private function formatDiffLine(string $sign, string $line, ConsoleStyle $signStyle): string
    {
        return '         '.$this->markup->style($sign.' ', $signStyle).$line.\PHP_EOL;
    }

    /**
     * The lines a change deletes, then those it inserts, a line deleted and
     * a line inserted paired in order and diffed against each other.
     *
     * @param list<array{type: string, line: string}> $block
     */
    private function formatDiffChangeBlock(array $block): string
    {
        $deletes = [];
        $inserts = [];
        foreach ($block as $op) {
            if ('delete' === $op['type']) {
                $deletes[] = $op['line'];
            } else {
                $inserts[] = $op['line'];
            }
        }

        $deleteLines = '';
        $insertLines = '';
        foreach ($deletes as $index => $delete) {
            $line = isset($inserts[$index]) ? $this->computeSimpleDiff($delete, $inserts[$index])[0] : $this->markup->style($delete, ConsoleStyle::Error);
            $deleteLines .= $this->formatDiffLine('-', $line, ConsoleStyle::Error);
        }
        foreach ($inserts as $index => $insert) {
            $line = isset($deletes[$index]) ? $this->computeSimpleDiff($deletes[$index], $insert)[1] : $this->markup->style($insert, ConsoleStyle::Success);
            $insertLines .= $this->formatDiffLine('+', $line, ConsoleStyle::Success);
        }

        $separator = '' !== $deleteLines && '' !== $insertLines ? '         '.$this->markup->style('---', ConsoleStyle::Muted).\PHP_EOL : '';

        return $deleteLines.$separator.$insertLines;
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        return '' === $normalized ? [] : explode("\n", $normalized);
    }

    private static function isExtendedModePattern(string $pattern): bool
    {
        try {
            [, $flags] = PatternParser::extractPatternAndFlags($pattern);
        } catch (ParserException) {
            return false;
        }

        return str_contains($flags, 'x');
    }

    /**
     * Two lines with only what changed between them highlighted: the
     * prefix and the suffix both share dimmed.
     *
     * @return array{string, string}
     */
    private function computeSimpleDiff(string $old, string $new): array
    {
        if ($old === $new) {
            return [$old, $new];
        }

        [$prefixLength, $suffixLength] = LineDiff::affixes($old, $new);
        $prefix = substr($old, 0, $prefixLength);
        $suffix = substr($old, \strlen($old) - $suffixLength);
        $dimPrefix = '' === $prefix ? '' : $this->markup->style($prefix, ConsoleStyle::Muted);
        $dimSuffix = '' === $suffix ? '' : $this->markup->style($suffix, ConsoleStyle::Muted);
        $middleOld = substr($old, $prefixLength, \strlen($old) - $prefixLength - $suffixLength);
        $middleNew = substr($new, $prefixLength, \strlen($new) - $prefixLength - $suffixLength);

        return [
            $dimPrefix.$this->markup->style($middleOld, ConsoleStyle::ErrorBold).$dimSuffix,
            $dimPrefix.$this->markup->style($middleNew, ConsoleStyle::SuccessBold).$dimSuffix,
        ];
    }

    /**
     * The pen that links the file in the editor, as an OSC 8 hyperlink.
     */
    private function formatPenLink(string $file, int $line, int $column): string
    {
        // The link formatter gives a console tag, <href=URL>✏️</>: its URL
        // goes into the escape sequence the terminal reads.
        $href = $this->linkFormatter?->format($file, $line, '✏️', $column);
        if (LibraryPcre::match('/<href=([^>]+)>/', (string) $href, $matches)) {
            return "\e]8;;{$matches[1]}\e\\✏️\e]8;;\e\\";
        }

        return '✏️';
    }

    private function safelyHighlightBody(string $body, string $flags, string $delimiter): ?string
    {
        if (null === $this->analysisService || !$this->config->ansi) {
            return null;
        }

        try {
            return $this->analysisService->highlightBody($body, $flags, $delimiter);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The pattern as it was found in the source, highlighted when the
     * terminal takes colors: the parser's highlighter first, the text one
     * when the body does not parse or would not read back the same. Neither
     * changes a character.
     */
    private function formatPatternForDisplay(string $pattern): string
    {
        // Control characters are escaped, so none lays the line out.
        $pattern = DisplayEscaper::escape($pattern);
        if (!$this->config->ansi) {
            return $pattern;
        }

        // The delimiters and the flags are colored apart from the body; a
        // pattern of no known structure is shown as it is.
        $parts = self::splitPatternWithFlags($pattern);
        if (null === $parts) {
            return $pattern;
        }

        ['delimiter' => $delimiter, 'closingDelimiter' => $closingDelimiter, 'flags' => $flags, 'body' => $body] = $parts;

        $highlightedBody = $this->safelyHighlightBody($body, $flags, $delimiter);
        if (null === $highlightedBody || $this->stripAnsi($highlightedBody) !== $body) {
            $highlightedBody = (new TextPreservingHighlighter($this->markup))->highlight($body);
        }

        $highlighted = $this->markup->style($delimiter, ConsoleStyle::AccentBold).$highlightedBody.$this->markup->style($closingDelimiter, ConsoleStyle::AccentBold);

        return '' === $flags ? $highlighted : $highlighted.$this->markup->style($flags, ConsoleStyle::Accent);
    }

    /**
     * A pattern of the form /body/flags or {body}flags split into its
     * parts, the bytes untouched; null when it has no delimiters.
     *
     * @return array{delimiter: string, closingDelimiter: string, flags: string, body: string}|null
     */
    private static function splitPatternWithFlags(string $pattern): ?array
    {
        if ('' === $pattern) {
            return null;
        }

        $delimiter = $pattern[0];

        // PHP refuses an alphanumeric, backslash or NUL delimiter. It skips
        // leading whitespace before the delimiter, and this text is not
        // trimmed, so a whitespace first byte is no delimiter either.
        if (Ascii::isAlnum($delimiter) || '\\' === $delimiter || "\0" === $delimiter || Ascii::isSpace($delimiter)) {
            return null;
        }

        // PCRE takes paired delimiters: {^pattern$}i, (pattern)i and so on.
        $closingDelimiter = match ($delimiter) {
            '{' => '}',
            '(' => ')',
            '[' => ']',
            '<' => '>',
            default => $delimiter,
        };

        $lastDelimiterPos = strrpos($pattern, $closingDelimiter);
        if (false === $lastDelimiterPos || 0 === $lastDelimiterPos) {
            return null;
        }

        return [
            'delimiter' => $delimiter,
            'closingDelimiter' => $closingDelimiter,
            'flags' => substr($pattern, $lastDelimiterPos + 1),
            'body' => substr($pattern, 1, $lastDelimiterPos - 1),
        ];
    }

    private function stripAnsi(string $text): string
    {
        return LibraryPcre::replace('/\e\\[[0-9;]*m/', '', $text) ?? $text;
    }
}

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
use PHPRegex\Linter\Internal\LintSummary;
use PHPRegex\Linter\LintReport;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Validation\ValidationResult;

/**
 * Console output formatter with ANSI colors and verbosity levels.
 *
 * @phpstan-import-type LintIssue from LintReport
 * @phpstan-import-type OptimizationEntry from LintReport
 * @phpstan-import-type LintResult from LintReport
 * @phpstan-import-type LintStats from LintReport
 *
 * @internal
 */
class ConsoleFormatter extends AbstractOutputFormatter
{
    // ANSI color codes
    private const RESET = "\033[0m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLACK = "\033[30m";
    private const CYAN = "\033[36m";
    private const WHITE = "\033[37m";
    private const GRAY = "\033[90m";
    private const BOLD = "\033[1m";
    private const BG_RED = "\033[41m";
    private const BG_GREEN = "\033[42m";
    private const BG_YELLOW = "\033[43m";
    private const BG_CYAN = "\033[46m";
    private const BG_GRAY = "\033[100m";
    private const ARROW_LABEL = '↳';

    public function __construct(
        private readonly ?AnalysisService $analysisService = null,
        OutputConfiguration $config = new OutputConfiguration(),
        private readonly string $ide = '',
        private readonly ?LinkFormatter $linkFormatter = null,
    ) {
        parent::__construct($config);
    }

    public function format(LintReport $report): string
    {
        if (OutputConfiguration::VERBOSITY_QUIET === $this->config->verbosity) {
            return $this->formatQuiet($report);
        }

        $parts = [];
        $groupedResults = $this->groupResults($report->results);

        foreach ($groupedResults as $results) {
            /** @var array<LintResult> $results */
            foreach ($results as $result) {
                $pattern = $this->extractPatternForResult($result);
                $parts[] = $this->formatPatternContext($result);
                /** @var array<LintIssue> $issues */
                $issues = $result['issues'] ?? [];
                $parts[] = $this->formatIssues($issues, $pattern);
                /** @var array<OptimizationEntry> $optimizations */
                $optimizations = $result['optimizations'] ?? [];
                $parts[] = $this->formatOptimizations($optimizations);
                $parts[] = \PHP_EOL;
            }
        }

        return implode('', $parts);
    }

    /**
     * @param array{errors: int, warnings: int, optimizations: int, redos?: int, infos?: int, lintErrors?: int, parserFallbacks?: int} $stats
     */
    public function getSummary(array $stats): string
    {
        return $this->formatSummary($stats);
    }

    /**
     * Format footer.
     */
    public function formatFooter(): string
    {
        $message = 'If PHPRegex helps, a GitHub star is appreciated: ';

        return '  '.$this->dim($message.'https://github.com/php-regex/php-regex').\PHP_EOL.\PHP_EOL;
    }

    private function formatQuiet(LintReport $report): string
    {
        $errors = $report->stats['errors'];
        $warnings = $report->stats['warnings'];

        if ($errors > 0) {
            return \sprintf('FAIL: %s, %s.'.\PHP_EOL,
                LintSummary::errors($report->stats), LintSummary::failureCounts($report->stats));
        }

        return \sprintf('%s: %s, %d optimizations available.'.\PHP_EOL,
            $warnings > 0 ? 'WARN' : 'PASS', LintSummary::passCounts($report->stats), $report->stats['optimizations']);
    }

    /**
     * Format pattern context information.
     *
     * @phpstan-param LintResult $result
     */
    private function formatPatternContext(array $result): string
    {
        $pattern = $this->extractPatternForResult($result);
        $file = (string) ($result['file'] ?? '');
        $line = (int) ($result['line'] ?? 0);
        $column = (int) ($result['column'] ?? 0);
        $location = $result['location'] ?? null;

        $hasLocation = \is_string($location) && '' !== $location;

        // Build a compact file:line prefix, e.g. "src/Console/Command.php:679",
        // the file name in display form, on one line.
        $prefix = '';
        if ('' !== $file && $line > 0) {
            $fileLine = ReportSpelling::displayField($file).':'.$line;
            if ($column > 1) {
                $fileLine .= ':'.$column;
            }
            if ('' !== $this->ide && null !== $this->linkFormatter) {
                $penLink = $this->formatPenLink($file, $line, $column > 0 ? $column : 1);
                $prefix = $fileLine.' '.$penLink;
            } else {
                $prefix = $fileLine;
            }
        } elseif ('' !== $file) {
            $prefix = ReportSpelling::displayField($file);
        } elseif ($line > 0) {
            $prefix = (string) $line;
        }

        // Always wrap: show file:line, then the pattern on the next indented line.

        if (null !== $pattern && '' !== $pattern) {
            $formatted = $this->formatPatternForDisplay($pattern);
            $label = '' !== $prefix ? $this->color($prefix, self::CYAN.self::BOLD) : $this->color('(pattern)', self::CYAN.self::BOLD);

            // Wrap: show file:line, then the pattern on the next indented line.
            $output = '  '.$label.\PHP_EOL;
            $output .= '      '.$this->color('→ ', self::CYAN.self::BOLD).$formatted.\PHP_EOL;
        } else {
            $label = '' !== $prefix ? $this->color($prefix, self::CYAN.self::BOLD) : $this->color('(pattern unavailable)', self::CYAN.self::BOLD);
            $output = '  '.$label.\PHP_EOL;
        }

        if ($hasLocation) {
            $output .= \sprintf('     %s'.\PHP_EOL, $this->color(self::ARROW_LABEL.' '.ReportSpelling::displayField($location), self::CYAN));
        }

        return $output;
    }

    /**
     * Format issues for a result.
     *
     * @phpstan-param array<LintIssue> $issues
     */
    private function formatIssues(array $issues, ?string $pattern): string
    {
        $parts = [];

        foreach ($issues as $issue) {
            $issueType = (string) ($issue['type'] ?? 'info');
            $badge = $this->issueBadge($issueType);
            $parts[] = $this->displaySingleIssue($badge, $this->messageWithSnippet($issue, $pattern));

            // An invalid pattern says it all in its message and caret; a
            // ReDoS error still needs its hint, which opens on the evidence,
            // and a lint rule at Error its fix.
            $hint = $issue['hint'] ?? null;
            if (!isset($issue['validation']) && \is_string($hint) && '' !== $hint && $this->config->shouldShowHints()) {
                $formattedHint = ReportSpelling::displayText($this->formatHint($hint));
                if ('' !== $formattedHint) {
                    $parts[] = \sprintf('         %s'.\PHP_EOL, $this->dim(self::ARROW_LABEL.' '.$formattedHint));
                }
            }
        }

        return implode('', $parts);
    }

    /**
     * Format optimizations for a result.
     *
     * @phpstan-param array<OptimizationEntry> $optimizations
     */
    private function formatOptimizations(array $optimizations): string
    {
        if (!$this->config->shouldShowOptimizations() || empty($optimizations)) {
            return '';
        }

        $parts = [];

        foreach ($optimizations as $opt) {
            $parts[] = \sprintf('    %s'.\PHP_EOL,
                $this->badge('TIP', self::WHITE, self::BG_CYAN),
            );

            $optimization = $opt['optimization'];
            if (!$optimization instanceof OptimizationResult) {
                continue;
            }

            $parts[] = $this->formatOptimizationDiff($optimization->original, $optimization->optimized);
        }

        return implode('', $parts);
    }

    private function formatOptimizationDiff(string $original, string $optimized): string
    {
        if ($this->shouldUseMultilineDiff($original, $optimized)) {
            return $this->formatMultilineDiff($original, $optimized);
        }

        $diff = $this->computeSimpleDiff(
            $this->escapeControlChars($original),
            $this->escapeControlChars($optimized),
        );

        return \sprintf('         %s%s'.\PHP_EOL,
            $this->color('- ', self::RED),
            $diff['old'],
        ).\sprintf('         %s%s'.\PHP_EOL,
            $this->color('+ ', self::GREEN),
            $diff['new'],
        );
    }

    private function formatMultilineDiff(string $old, string $new): string
    {
        // Each line is spelled in its pattern's mode, so no character that
        // moves or hides text reaches the terminal raw.
        $oldLines = array_map(static fn (string $line): string => ReportSpelling::displayFragment($line, $old), $this->splitLines($old));
        $newLines = array_map(static fn (string $line): string => ReportSpelling::displayFragment($line, $new), $this->splitLines($new));

        $ops = $this->diffLines($oldLines, $newLines);
        if (empty($ops)) {
            return '';
        }

        // Nothing is ever hidden: a diff that elides invites pasting a
        // truncated rewrite, so every line is printed with its marker.
        $output = '';
        $index = 0;
        $opCount = \count($ops);

        while ($index < $opCount) {
            $op = $ops[$index];

            if ('equal' === $op['type']) {
                $output .= $this->formatDiffLine(' ', $op['line'], self::GRAY, true);
                $index++;

                continue;
            }

            $block = [];
            while ($index < $opCount && 'equal' !== $ops[$index]['type']) {
                $block[] = $ops[$index];
                $index++;
            }

            $output .= $this->formatDiffChangeBlock($block);
        }

        return $output;
    }

    private function formatDiffLine(string $sign, string $line, string $signColor, bool $dimLine = false): string
    {
        $content = $dimLine ? $this->dim($line) : $line;

        return \sprintf('         %s%s'.\PHP_EOL,
            $this->color($sign.' ', $signColor),
            $content,
        );
    }

    private function formatDiffSeparator(): string
    {
        return \sprintf('         %s'.\PHP_EOL, $this->dim('---'));
    }

    /**
     * @param array<int, string> $oldLines
     * @param array<int, string> $newLines
     *
     * @return array<int, array{type: string, line: string}>
     */
    private function diffLines(array $oldLines, array $newLines): array
    {
        $oldCount = \count($oldLines);
        $newCount = \count($newLines);

        $lcs = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        for ($i = 1; $i <= $oldCount; $i++) {
            for ($j = 1; $j <= $newCount; $j++) {
                if ($oldLines[$i - 1] === $newLines[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        $ops = [];
        $i = $oldCount;
        $j = $newCount;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $oldLines[$i - 1] === $newLines[$j - 1]) {
                $ops[] = ['type' => 'equal', 'line' => $oldLines[$i - 1]];
                $i--;
                $j--;

                continue;
            }

            if ($j > 0) {
                if (0 === $i) {
                    $ops[] = ['type' => 'insert', 'line' => $newLines[$j - 1]];
                    $j--;

                    continue;
                }

                if ($lcs[$i][$j - 1] >= $lcs[$i - 1][$j]) {
                    $ops[] = ['type' => 'insert', 'line' => $newLines[$j - 1]];
                    $j--;

                    continue;
                }
            }

            $ops[] = ['type' => 'delete', 'line' => $oldLines[$i - 1]];
            $i--;
        }

        return array_reverse($ops);
    }

    /**
     * @param array<int, array{type: string, line: string}> $block
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

        $deleteLines = [];
        $insertLines = [];
        $pairCount = min(\count($deletes), \count($inserts));

        for ($i = 0; $i < $pairCount; $i++) {
            $diff = $this->computeSimpleDiff($deletes[$i], $inserts[$i]);
            $deleteLines[] = $this->formatDiffLine('-', $diff['old'], self::RED);
            $insertLines[] = $this->formatDiffLine('+', $diff['new'], self::GREEN);
        }

        for ($i = $pairCount, $count = \count($deletes); $i < $count; $i++) {
            $deleteLines[] = $this->formatDiffLine('-', $this->color($deletes[$i], self::RED), self::RED);
        }

        for ($i = $pairCount, $count = \count($inserts); $i < $count; $i++) {
            $insertLines[] = $this->formatDiffLine('+', $this->color($inserts[$i], self::GREEN), self::GREEN);
        }

        $separator = (!empty($deleteLines) && !empty($insertLines)) ? $this->formatDiffSeparator() : '';

        return implode('', $deleteLines).$separator.implode('', $insertLines);
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        if ('' === $normalized) {
            return [];
        }

        return explode("\n", $normalized);
    }

    private function shouldUseMultilineDiff(string $old, string $new): bool
    {
        return $this->isExtendedModePattern($old) || $this->isExtendedModePattern($new);
    }

    private function isExtendedModePattern(string $pattern): bool
    {
        $pattern = Ascii::trimLeadingSpaces($pattern);
        if ('' === $pattern) {
            return false;
        }

        try {
            [, $flags] = PatternParser::extractPatternAndFlags($pattern);
        } catch (\Throwable) {
            return false;
        }

        return str_contains($flags, 'x');
    }

    private function escapeControlChars(string $text): string
    {
        return DisplayEscaper::escape($text);
    }

    /**
     * Compute a diff between two strings by finding common prefix and suffix,
     * highlighting only the differing middle parts and dimming common spans.
     *
     * @return array{old: string, new: string}
     */
    private function computeSimpleDiff(string $old, string $new): array
    {
        if ($old === $new) {
            return ['old' => $old, 'new' => $new];
        }

        $oldLen = \strlen($old);
        $newLen = \strlen($new);

        // Find common prefix
        $prefixLen = 0;
        $minLen = min($oldLen, $newLen);
        while ($prefixLen < $minLen && $old[$prefixLen] === $new[$prefixLen]) {
            $prefixLen++;
        }

        // Find common suffix
        $suffixLen = 0;
        $maxSuffix = min($oldLen - $prefixLen, $newLen - $prefixLen);
        while ($suffixLen < $maxSuffix && $old[$oldLen - 1 - $suffixLen] === $new[$newLen - 1 - $suffixLen]) {
            $suffixLen++;
        }

        // Extract middle parts
        $middleOld = substr($old, $prefixLen, $oldLen - $prefixLen - $suffixLen);
        $middleNew = substr($new, $prefixLen, $newLen - $prefixLen - $suffixLen);

        // Build colored strings
        $prefix = substr($old, 0, $prefixLen);
        $suffix = substr($old, $oldLen - $suffixLen);

        $dimPrefix = '' === $prefix ? '' : $this->dim($prefix);
        $dimSuffix = '' === $suffix ? '' : $this->dim($suffix);

        $coloredOld = $dimPrefix.$this->color($middleOld, self::RED.self::BOLD).$dimSuffix;
        $coloredNew = $dimPrefix.$this->color($middleNew, self::GREEN.self::BOLD).$dimSuffix;

        return ['old' => $coloredOld, 'new' => $coloredNew];
    }

    /**
     * Format summary statistics.
     *
     * @phpstan-param LintStats $stats
     */
    private function formatSummary(array $stats): string
    {
        $output = \PHP_EOL;

        $errors = (int) $stats['errors'];
        $warnings = (int) $stats['warnings'];
        $optimizations = (int) $stats['optimizations'];

        if ($errors > 0) {
            $output .= \sprintf('  %s %s%s'.\PHP_EOL,
                $this->badge('FAIL', self::WHITE, self::BG_RED),
                $this->color(LintSummary::errors($stats), self::RED.self::BOLD),
                $this->dim(', '.LintSummary::failureCounts($stats).'.'),
            );
        } elseif ($warnings > 0) {
            $output .= \sprintf('  %s %s%s'.\PHP_EOL,
                $this->badge('PASS', self::BLACK, self::BG_YELLOW),
                $this->color(LintSummary::passCounts($stats), self::YELLOW.self::BOLD),
                $this->dim(\sprintf(', %d optimizations available.', $optimizations)),
            );
        } else {
            $output .= \sprintf('  %s %s%s'.\PHP_EOL,
                $this->badge('PASS', self::WHITE, self::BG_GREEN),
                $this->color(LintSummary::passCounts($stats), self::GREEN.self::BOLD),
                $this->dim(\sprintf(', %d optimizations available.', $optimizations)),
            );
        }

        return $output;
    }

    /**
     * The message of an issue in display form, and under it the caret
     * snippet its validation carries apart: the line of the pattern with the
     * character at fault, spelled in the pattern's mode.
     *
     * @param array<array-key, mixed> $issue
     */
    private function messageWithSnippet(array $issue, ?string $pattern): string
    {
        $message = ReportSpelling::displayText(\is_string($issue['message'] ?? null) ? $issue['message'] : '');
        $validation = $issue['validation'] ?? null;
        if (!$validation instanceof ValidationResult || null === $validation->caretSnippet || '' === $validation->caretSnippet) {
            return $message;
        }

        return $message."\n".ReportSpelling::displaySnippet($validation->caretSnippet, $pattern);
    }

    private function displaySingleIssue(string $badge, string $message): string
    {
        $lines = explode("\n", $message);
        $firstLine = array_shift($lines) ?? '';

        $output = \sprintf(
            '    %s %s'.\PHP_EOL,
            $badge,
            $this->color($firstLine, self::WHITE),
        );

        if (!empty($lines)) {
            foreach ($lines as $index => $line) {
                $prefix = 0 === $index ? self::ARROW_LABEL : ' ';
                $output .= \sprintf(
                    '         %s %s'.\PHP_EOL,
                    $this->dim($prefix),
                    $this->dim($this->stripMessageLine($line)),
                );
            }
        }

        return $output;
    }

    /**
     * Format pen emoji with IDE link.
     */
    private function formatPenLink(string $file, int $line, int $column): string
    {
        // Use LinkFormatter to get the URL, but convert Symfony href format to ANSI escape sequences
        $href = $this->linkFormatter?->format($file, $line, '✏️', $column);

        // Extract URL from Symfony href format: <href=URL>✏️</>
        if (LibraryPcre::match('/<href=([^>]+)>/', (string) $href, $matches)) {
            $url = $matches[1];

            return "\e]8;;{$url}\e\\✏️\e]8;;\e\\";
        }

        return '✏️';
    }

    // getPenLabel() is intentionally omitted in this formatter. The plain
    // console output does not currently render a clickable "pen" link; this
    // responsibility is handled by the SymfonyConsoleFormatter bridge.

    private function safelyHighlightBody(string $body, string $flags, string $delimiter): ?string
    {
        if (!$this->analysisService || !$this->config->ansi) {
            return null;
        }

        try {
            return $this->analysisService->highlightBody($body, $flags, $delimiter);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Format pattern for display.
     *
     * The linter output must be text-preserving: the pattern shown in the
     * console should match exactly what was found in the source file.
     *
     * We try the AST-based highlighter first for better syntax highlighting,
     * falling back to a lightweight regex-based highlighter if parsing fails.
     * Both preserve the exact characters while adding ANSI color codes to
     * delimiters, flags, and pattern elements.
     */
    private function formatPatternForDisplay(string $pattern): string
    {
        // Escape control characters to prevent visual layout issues
        $pattern = DisplayEscaper::escape($pattern);

        // No ANSI: return the escaped pattern exactly as we received it.
        if (!$this->config->ansi) {
            return $pattern;
        }

        // Try to split /body/flags or {body}flags so we can color the
        // delimiters and flags without touching the body itself.
        $parts = $this->splitPatternWithFlags($pattern);
        if (null === $parts) {
            // Unknown structure (e.g. missing delimiters) – fall back to
            // returning the raw pattern unchanged, just to be safe.
            return $pattern;
        }

        $delimiter = $parts['delimiter'];
        $closingDelimiter = $parts['closingDelimiter'];
        $flags = $parts['flags'];

        // Recompute the last closing-delimiter position so we can slice out
        // the body without interpreting escapes. This mirrors the behavior of
        // PatternParser but never alters the bytes.
        $lastPos = strrpos($pattern, $closingDelimiter);
        if (false === $lastPos || 0 === $lastPos) {
            return $pattern;
        }
        $body = substr($pattern, 1, $lastPos - 1);

        // Try the AST-based highlighter first for better highlighting, then
        // fall back to the lightweight, text-preserving highlighter if it fails.
        // Both never change characters in the pattern; they only wrap segments in
        // ANSI color codes.
        $highlightedBody = $this->safelyHighlightBody($body, $flags, $delimiter);
        if (null === $highlightedBody) {
            $highlightedBody = $this->highlightPatternBodyPreservingText($body);
        } elseif ($this->stripAnsi($highlightedBody) !== $body) {
            $highlightedBody = $this->highlightPatternBodyPreservingText($body);
        }

        $open = $this->color($delimiter, self::CYAN.self::BOLD);
        $close = $this->color($closingDelimiter, self::CYAN.self::BOLD);

        if ('' === $flags) {
            return $open.$highlightedBody.$close;
        }

        return $open.$highlightedBody.$close.$this->color($flags, self::CYAN);
    }

    /**
     * Very small regex highlighter that operates directly on the pattern body
     * without changing any of its characters.
     *
     * It understands only a subset of PCRE syntax (escapes, character
     * classes, grouping, anchors, and basic quantifiers), but that is enough
     * to give a pleasant visual layout while guaranteeing the printed text is
     * identical to the source.
     */
    private function highlightPatternBodyPreservingText(string $body): string
    {
        if (!$this->config->ansi || '' === $body) {
            return $body;
        }

        $len = \strlen($body);
        $out = '';
        $inClass = false;
        $escaped = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];

            if ($escaped) {
                // Render "\\X" as a single green escape sequence.
                $out .= $this->color('\\'.$ch, self::GREEN);
                $escaped = false;

                continue;
            }

            if ('\\' === $ch) {
                $escaped = true;

                continue;
            }

            if ($inClass) {
                if (']' === $ch) {
                    $inClass = false;
                    $out .= $this->color(']', self::CYAN);

                    continue;
                }

                // Inside a character class we don't try to be clever; just
                // echo characters as-is to avoid mis-highlighting.
                $out .= $ch;

                continue;
            }

            if ('[' === $ch) {
                $inClass = true;
                $out .= $this->color('[', self::CYAN);

                continue;
            }

            // Grouping and structural meta-chars.
            if ('(' === $ch || ')' === $ch || '|' === $ch || '.' === $ch) {
                $out .= $this->color($ch, self::CYAN);

                continue;
            }

            // Simple quantifiers.
            if ('+' === $ch || '*' === $ch || '?' === $ch) {
                $out .= $this->color($ch, self::YELLOW);

                continue;
            }

            // Bounded quantifier like {2} or {1,3}.
            if ('{' === $ch) {
                $j = $i + 1;
                while ($j < $len && Ascii::isDigit($body[$j])) {
                    $j++;
                }

                $isQuant = false;
                if ($j < $len && '}' === $body[$j]) {
                    $isQuant = true;
                } elseif ($j < $len && ',' === $body[$j]) {
                    $j++;
                    while ($j < $len && Ascii::isDigit($body[$j])) {
                        $j++;
                    }
                    if ($j < $len && '}' === $body[$j]) {
                        $isQuant = true;
                    }
                }

                if ($isQuant) {
                    $segment = substr($body, $i, $j - $i + 1);
                    $out .= $this->color($segment, self::YELLOW);
                    $i = $j;

                    continue;
                }

                $out .= '{';

                continue;
            }

            // Anchors.
            if ('^' === $ch || '$' === $ch) {
                $out .= $this->color($ch, self::CYAN);

                continue;
            }

            $out .= $ch;
        }

        if ($escaped) {
            // Trailing backslash with no following char; just output it.
            $out .= '\\';
        }

        return $out;
    }

    /**
     * Split a regex pattern of the form /body/flags or {body}flags into its components.
     *
     * @return array{delimiter: string, closingDelimiter: string, flags: string}|null
     */
    private function splitPatternWithFlags(string $pattern): ?array
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

        // PCRE supports paired delimiters like {^pattern$}i, (pattern)i, etc.
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
        $flags = substr($pattern, $lastDelimiterPos + 1);

        return [
            'delimiter' => $delimiter,
            'closingDelimiter' => $closingDelimiter,
            'flags' => $flags,
        ];
    }

    /**
     * Get badge for issue type.
     */
    private function issueBadge(string $type): string
    {
        return match ($type) {
            'error' => $this->badge('FAIL', self::WHITE, self::BG_RED),
            'warning' => $this->badge('WARN', self::BLACK, self::BG_YELLOW),
            'info' => $this->badge('INFO', self::WHITE, self::BG_GRAY),
            default => $this->badge('INFO', self::WHITE, self::BG_GRAY),
        };
    }

    /**
     * Create a badge with colors.
     */
    private function badge(string $text, string $fg, string $bg): string
    {
        if (!$this->config->ansi) {
            return \sprintf('%-4s', $text);
        }

        return $this->color(' '.$text.' ', $bg.$fg.self::BOLD);
    }

    /**
     * Apply color to text.
     */
    private function color(string $text, string $color): string
    {
        if (!$this->config->ansi) {
            return $text;
        }

        return $color.$text.self::RESET;
    }

    private function stripAnsi(string $text): string
    {
        return LibraryPcre::replace('/\e\\[[0-9;]*m/', '', $text) ?? $text;
    }

    /**
     * Apply dim color to text.
     */
    private function dim(string $text): string
    {
        return $this->color($text, self::GRAY);
    }

    /**
     * @phpstan-param LintResult $result
     */
    private function extractPatternForResult(array $result): ?string
    {
        $pattern = $result['pattern'];
        if (\is_string($pattern) && '' !== $pattern) {
            return $pattern;
        }

        if (!empty($result['issues'])) {
            $firstIssue = $result['issues'][0];
            $issuePattern = $firstIssue['pattern'] ?? $firstIssue['regex'] ?? null;
            if (\is_string($issuePattern) && '' !== $issuePattern) {
                return $issuePattern;
            }
        }

        if (!empty($result['optimizations'])) {
            $firstOpt = $result['optimizations'][0];
            $optimization = $firstOpt['optimization'];
            if ($optimization instanceof OptimizationResult) {
                return $optimization->original;
            }
        }

        return null;
    }

    private function stripMessageLine(string $message): string
    {
        return LibraryPcre::replaceCallback(
            '/^Line \d+:/m',
            static fn (array $matches): string => str_repeat(' ', \strlen($matches[0])),
            $message,
        ) ?? $message;
    }
}

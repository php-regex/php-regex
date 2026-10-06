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
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Validation\ValidationResult;

/**
 * Console output formatter shared by the framework bridges.
 *
 * Renders the classic Nuno-style layout with console tags. Laravel builds its
 * console on Symfony's, so both bridges render the report the same way; each
 * one only names the formatter it exposes. The text it quotes is escaped as
 * Symfony Console's OutputFormatter::escape() escapes it, so the linter does
 * not depend on Symfony Console.
 *
 * @internal shared by the Symfony and Laravel bridges, not for extension
 *
 * @phpstan-import-type LintIssue from LintReport
 * @phpstan-import-type OptimizationEntry from LintReport
 * @phpstan-import-type LintResult from LintReport
 * @phpstan-import-type LintStats from LintReport
 */
abstract readonly class AbstractConsoleTagFormatter implements OutputFormatterInterface
{
    private const ARROW_LABEL = "\u{21B3}";

    public function __construct(
        private AnalysisService $analysis,
        private LinkFormatter $linkFormatter,
        private bool $decorated = true,
    ) {}

    public function format(LintReport $report): string
    {
        $parts = [];

        if (!empty($report->results)) {
            $parts[] = $this->renderResults($report->results);
        }

        $parts[] = $this->renderSummary($report->stats);

        return implode('', $parts);
    }

    public function formatError(string $message): string
    {
        return $message;
    }

    /**
     * @phpstan-param array<LintResult> $results
     */
    private function renderResults(array $results): string
    {
        $parts = [];

        foreach ($results as $result) {
            $parts[] = $this->renderResultCard($result);
        }

        return implode('', $parts);
    }

    /**
     * @phpstan-param LintResult $result
     */
    private function renderResultCard(array $result): string
    {
        /** @var array<LintIssue> $issues */
        $issues = $result['issues'] ?? [];
        /** @var array<OptimizationEntry> $optimizations */
        $optimizations = $result['optimizations'] ?? [];
        $pattern = $this->extractPatternForResult($result);

        $output = '';
        $output .= $this->displayPatternContext($result);
        $output .= $this->displayIssues($issues, $pattern);
        $output .= $this->displayOptimizations($optimizations);
        $output .= \PHP_EOL;

        return $output;
    }

    /**
     * @phpstan-param LintResult $result
     */
    private function displayPatternContext(array $result): string
    {
        $pattern = $this->extractPatternForResult($result);
        $line = (int) $result['line'];
        $column = (int) ($result['column'] ?? 0);
        $file = (string) $result['file'];
        $location = $result['location'] ?? null;

        $hasLocation = \is_string($location) && '' !== $location;

        $relPath = $this->linkFormatter->getRelativePath($file);
        if (!str_starts_with($relPath, '/')) {
            $relPath = './'.$relPath;
        }
        $label = $relPath;
        if ($line > 0) {
            $label .= ':'.$line;
            if ($column > 1) {
                $label .= ':'.$column;
            }
        }
        // The label is the file name in display form on one line, its tags
        // escaped; the link still points at the file as named.
        $label = self::escape(ReportSpelling::displayField($label));
        $linkedLabel = $this->linkFormatter->format($file, $line, $label, $column > 0 ? $column : 1, $label);

        $output = '  <fg=cyan;options=bold>'.$linkedLabel.'</>'.\PHP_EOL;

        if (null !== $pattern && '' !== $pattern) {
            $highlighted = $this->safelyHighlightPattern($pattern);
            $output .= '      <fg=cyan;options=bold>→ </><fg=white>'.$highlighted.'</>'.\PHP_EOL;
        }

        if ($hasLocation) {
            $output .= \sprintf(
                '     <fg=gray>%s %s</>'.\PHP_EOL,
                self::ARROW_LABEL,
                self::escape(ReportSpelling::displayField($location)),
            );
        }

        return $output;
    }

    private function safelyHighlightPattern(string $pattern): string
    {
        // Wrap with / / if no delimiter
        if (!LibraryPcre::match('/^[^a-zA-Z0-9\\\\]/', $pattern)) {
            $pattern = '/'.$pattern.'/';
        }

        // Always escape control characters to prevent layout issues
        $escapedPattern = DisplayEscaper::escape($pattern);

        if (!$this->decorated) {
            return self::escape($escapedPattern);
        }

        try {
            // Highlighting reads the pattern as written: once its display
            // form differs (escapes, an x pattern put on one line), the
            // display form is shown as is.
            if ($escapedPattern !== $pattern || str_contains($escapedPattern, '\\')) {
                return self::escape($escapedPattern);
            }

            $highlighted = $this->analysis->highlight($pattern);

            return self::escape($highlighted);
        } catch (\Throwable) {
            return self::escape($escapedPattern);
        }
    }

    /**
     * @phpstan-param array<LintIssue> $issues
     */
    private function displayIssues(array $issues, ?string $pattern = null): string
    {
        $parts = [];
        foreach ($issues as $issue) {
            $issueType = (string) ($issue['type'] ?? 'info');
            $badge = $this->getIssueBadge($issueType);
            $parts[] = $this->displaySingleIssue($badge, $this->messageWithSnippet($issue, $pattern));

            // An invalid pattern says it all in its message and caret; a
            // ReDoS error still needs its hint, which opens on the evidence,
            // and a lint rule at Error its fix.
            $hint = $issue['hint'] ?? null;
            if (!isset($issue['validation']) && \is_string($hint) && '' !== $hint) {
                $parts[] = \sprintf(
                    '         <fg=gray>%s %s</>'.\PHP_EOL,
                    self::ARROW_LABEL,
                    self::escape(ReportSpelling::displayText($hint)),
                );
            }

        }

        return implode('', $parts);
    }

    private function getIssueBadge(string $type): string
    {
        return match ($type) {
            'error' => '<bg=red;fg=white;options=bold> FAIL </>',
            'warning' => '<bg=yellow;fg=black;options=bold> WARN </>',
            default => '<bg=gray;fg=white;options=bold> INFO </>',
        };
    }

    /**
     * @phpstan-param array<OptimizationEntry> $optimizations
     */
    private function displayOptimizations(array $optimizations): string
    {
        $parts = [];

        foreach ($optimizations as $opt) {
            $parts[] = '    <bg=cyan;fg=white;options=bold> TIP </>'.\PHP_EOL;

            $optimization = $opt['optimization'];
            if (!$optimization instanceof OptimizationResult) {
                continue;
            }

            $original = $this->safelyHighlightPattern($optimization->original);
            $optimized = $this->safelyHighlightPattern($optimization->optimized);

            $parts[] = \sprintf('         <fg=red>- %s</>'.\PHP_EOL, $original);
            $parts[] = \sprintf('         <fg=green>+ %s</>'.\PHP_EOL, $optimized);
        }

        return implode('', $parts);
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

        $parts = [\sprintf(
            '    %s <fg=white>%s</>'.\PHP_EOL,
            $badge,
            self::escape($firstLine),
        )];

        if (!empty($lines)) {
            foreach ($lines as $index => $line) {
                $parts[] = \sprintf(
                    '         <fg=gray>%s %s</>'.\PHP_EOL,
                    0 === $index ? self::ARROW_LABEL : ' ',
                    self::escape($this->stripMessageLine($line)),
                );
            }
        }

        return implode('', $parts);
    }

    /**
     * @phpstan-param LintStats $stats
     */
    private function renderSummary(array $stats): string
    {
        $output = \PHP_EOL;
        $output .= $this->showSummaryMessage($stats);

        return $output;
    }

    /**
     * @phpstan-param LintStats $stats
     */
    private function showSummaryMessage(array $stats): string
    {
        $errors = (int) $stats['errors'];
        $warnings = (int) $stats['warnings'];
        $optimizations = (int) $stats['optimizations'];

        $message = match (true) {
            $errors > 0 => \sprintf(
                '  <bg=red;fg=white;options=bold> FAIL </> <fg=red;options=bold>%s</><fg=gray>, %s.</>',
                LintSummary::errors($stats),
                LintSummary::failureCounts($stats),
            ),
            $warnings > 0 => \sprintf(
                '  <bg=yellow;fg=black;options=bold> PASS </> <fg=yellow;options=bold>%s</><fg=gray>, %d optimizations available.</>',
                LintSummary::passCounts($stats),
                $optimizations,
            ),
            default => \sprintf(
                '  <bg=green;fg=white;options=bold> PASS </> <fg=green;options=bold>%s</><fg=gray>, %d optimizations available.</>',
                LintSummary::passCounts($stats),
                $optimizations,
            ),
        };

        return $message.\PHP_EOL;
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

    /**
     * Escapes text for a console that reads style tags: "<" and ">" take a
     * backslash, and trailing backslashes become NUL bytes so they do not
     * escape the closing tag that follows (the console prints them back as
     * backslashes).
     */
    private static function escape(string $text): string
    {
        $text = str_replace(['<', '>'], ['\\<', '\\>'], $text);

        if (!str_ends_with($text, '\\')) {
            return $text;
        }

        $length = \strlen($text);
        $text = str_replace("\0", '', rtrim($text, '\\'));

        return $text.str_repeat("\0", $length - \strlen($text));
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

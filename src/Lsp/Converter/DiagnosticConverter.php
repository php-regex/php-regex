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

namespace RegexParser\Lsp\Converter;

use RegexParser\ErrorCode;
use RegexParser\LintIssue;
use RegexParser\Severity;

/**
 * Converts RegexParser diagnostics to LSP diagnostic format.
 */
final class DiagnosticConverter
{
    // LSP Diagnostic Severity
    private const SEVERITY_ERROR = 1;
    private const SEVERITY_WARNING = 2;
    private const SEVERITY_INFORMATION = 3;
    private const SEVERITY_HINT = 4;

    /**
     * Convert a LintIssue to LSP diagnostic format.
     *
     * @param array{line: int, character: int} $start Position where the pattern starts in the file
     *
     * @return array<string, mixed>
     */
    public function convert(LintIssue $issue, array $start, int $patternLength): array
    {
        $offset = $issue->offset ?? 0;
        $endOffset = $offset + 1;

        // Clamp to pattern bounds
        if ($offset > $patternLength) {
            $offset = $patternLength;
        }
        if ($endOffset > $patternLength) {
            $endOffset = $patternLength;
        }

        return [
            'range' => [
                'start' => [
                    'line' => $start['line'],
                    'character' => $start['character'] + $offset,
                ],
                'end' => [
                    'line' => $start['line'],
                    'character' => $start['character'] + $endOffset,
                ],
            ],
            'severity' => $this->mapSeverity($issue->severity),
            'code' => $issue->id,
            'source' => 'regex-parser',
            'message' => $issue->message,
        ];
    }

    /**
     * Create a diagnostic for a parse error.
     *
     * @param array{line: int, character: int} $start
     *
     * @return array<string, mixed>
     */
    public function fromParseError(string $message, ErrorCode $code, array $start, int $patternLength, ?int $offset = null): array
    {
        $errorOffset = $offset ?? 0;

        return [
            'range' => [
                'start' => [
                    'line' => $start['line'],
                    'character' => $start['character'] + $errorOffset,
                ],
                'end' => [
                    'line' => $start['line'],
                    'character' => $start['character'] + $patternLength,
                ],
            ],
            'severity' => self::SEVERITY_ERROR,
            'code' => $code->value,
            'source' => 'regex-parser',
            'message' => $message,
        ];
    }

    /**
     * Create a diagnostic for a validation error.
     *
     * @param array{line: int, character: int} $start
     *
     * @return array<string, mixed>
     */
    public function fromValidationError(string $message, ErrorCode $code, array $start, int $patternLength, ?int $offset = null): array
    {
        $errorOffset = $offset ?? 0;

        return [
            'range' => [
                'start' => [
                    'line' => $start['line'],
                    'character' => $start['character'] + $errorOffset,
                ],
                'end' => [
                    'line' => $start['line'],
                    'character' => $start['character'] + $patternLength,
                ],
            ],
            'severity' => self::SEVERITY_ERROR,
            'code' => $code->value,
            'source' => 'regex-parser',
            'message' => $message,
        ];
    }

    private function mapSeverity(Severity $severity): int
    {
        return match ($severity) {
            Severity::Critical, Severity::Error => self::SEVERITY_ERROR,
            Severity::Warning => self::SEVERITY_WARNING,
            Severity::Style, Severity::Perf => self::SEVERITY_INFORMATION,
            Severity::Info => self::SEVERITY_HINT,
        };
    }
}

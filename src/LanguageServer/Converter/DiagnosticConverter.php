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

namespace PHPRegex\LanguageServer\Converter;

use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Parser\ErrorCode;

/**
 * Converts PHPRegex diagnostics to LSP diagnostic format.
 *
 * @phpstan-import-type Position from RegexOccurrence
 *
 * @internal
 */
final class DiagnosticConverter
{
    // LSP Diagnostic Severity
    private const SEVERITY_ERROR = 1;
    private const SEVERITY_WARNING = 2;
    private const SEVERITY_INFORMATION = 3;

    /**
     * Convert a LintIssue to LSP diagnostic format.
     *
     * @param Position $start Position where the pattern starts in the file
     *
     * @return array<string, mixed>
     */
    public function convert(RuleViolation $issue, array $start, int $patternLength): array
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
            'source' => 'php-regex',
            'message' => $issue->message,
        ];
    }

    /**
     * Create a diagnostic for a parse error.
     *
     * @param Position $start
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
            'source' => 'php-regex',
            'message' => $message,
        ];
    }

    /**
     * Create a diagnostic for a validation error.
     *
     * @param Position $start
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
            'source' => 'php-regex',
            'message' => $message,
        ];
    }

    private function mapSeverity(LintSeverity $severity): int
    {
        return match ($severity) {
            LintSeverity::Critical, LintSeverity::Error => self::SEVERITY_ERROR,
            LintSeverity::Warning => self::SEVERITY_WARNING,
            LintSeverity::Style, LintSeverity::Perf, LintSeverity::Info => self::SEVERITY_INFORMATION,
        };
    }
}

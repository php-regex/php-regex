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
 * Converts PHPRegex diagnostics to LSP diagnostic format, each placed on
 * the document through the span of its pattern.
 *
 * @phpstan-type Diagnostic array{range: Range, severity: 1|2|3, code: string, source: 'php-regex', message: string}
 *
 * @phpstan-import-type Range from RegexOccurrence
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
     * A lint issue, over the node its rule names: from its offset to its
     * end, or one character when the rule gives no end.
     *
     * @return Diagnostic
     */
    public function convert(RuleViolation $issue, PatternSpan $span): array
    {
        return [
            'range' => $span->range($issue->offset ?? 0, $issue->end),
            'severity' => $this->mapSeverity($issue->severity),
            'code' => $issue->id,
            'source' => 'php-regex',
            'message' => $issue->message,
        ];
    }

    /**
     * A parse error, on the character `regex validate` puts its caret
     * under: where PCRE reports the error.
     *
     * @return Diagnostic
     */
    public function fromParseError(string $message, ErrorCode $code, PatternSpan $span, ?int $offset = null, ?string $hint = null): array
    {
        return [
            'range' => $span->range($offset ?? 0),
            'severity' => self::SEVERITY_ERROR,
            'code' => $code->value,
            'source' => 'php-regex',
            'message' => self::withHint($message, $hint),
        ];
    }

    /**
     * A limit of the library met on the pattern: no error in it, the
     * pattern is not analyzed. Shown as information over the whole body.
     *
     * @return Diagnostic
     */
    public function fromLimit(string $message, ErrorCode $code, PatternSpan $span): array
    {
        return [
            'range' => $span->range(0, $span->bodyLength()),
            'severity' => self::SEVERITY_INFORMATION,
            'code' => $code->value,
            'source' => 'php-regex',
            'message' => 'Not analyzed: '.$message,
        ];
    }

    /**
     * A validation error, on the character `regex validate` puts its caret
     * under, as a parse error.
     *
     * @return Diagnostic
     */
    public function fromValidationError(string $message, ErrorCode $code, PatternSpan $span, ?int $offset = null, ?string $hint = null): array
    {
        return [
            'range' => $span->range($offset ?? 0),
            'severity' => self::SEVERITY_ERROR,
            'code' => $code->value,
            'source' => 'php-regex',
            'message' => self::withHint($message, $hint),
        ];
    }

    /**
     * A ReDoS verdict, as `regex lint` reports it: over the risky part of
     * the body when the analysis names one, over the whole body otherwise.
     *
     * @param list<string> $evidence the lines under the verdict: the attack, its replay
     *
     * @return Diagnostic
     */
    public function fromRedos(string $code, string $message, array $evidence, bool $error, PatternSpan $span, ?int $from = null, ?int $to = null): array
    {
        return [
            'range' => null === $from ? $span->range(0, $span->bodyLength()) : $span->range($from, $to),
            'severity' => $error ? self::SEVERITY_ERROR : self::SEVERITY_WARNING,
            'code' => $code,
            'source' => 'php-regex',
            'message' => implode("\n", [$message, ...$evidence]),
        ];
    }

    /**
     * The fix on the line under the error, unless the message already
     * suggests it.
     */
    private static function withHint(string $message, ?string $hint): string
    {
        return null === $hint || '' === $hint || str_contains($message, $hint) ? $message : $message."\n".$hint;
    }

    /**
     * @return self::SEVERITY_*
     */
    private function mapSeverity(LintSeverity $severity): int
    {
        return match ($severity) {
            LintSeverity::Critical, LintSeverity::Error => self::SEVERITY_ERROR,
            LintSeverity::Warning => self::SEVERITY_WARNING,
            LintSeverity::Style, LintSeverity::Perf, LintSeverity::Info => self::SEVERITY_INFORMATION,
        };
    }
}

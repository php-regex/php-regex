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

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\LintException;
use PHPRegex\Linter\LintReport;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Internal\JsonEncodingFailure;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;

/**
 * JSON output formatter for machine-readable output.
 *
 * Every object is written by the mapping below, key by key: a property
 * added to an issue array, a result or a value object does not reach the
 * report unless it is mapped here. Every issue carries every key, null
 * when it does not apply.
 *
 * @phpstan-type JsonIssue array{
 *     severity: string,
 *     file: string,
 *     line: int,
 *     column: int|null,
 *     file_offset: int|null,
 *     position: int|null,
 *     issue_id: string|null,
 *     message: string,
 *     hint: string|null,
 *     tip: string|null,
 *     source: string|null,
 *     validation: ValidationResult|null,
 *     analysis: RedosAnalysis|null,
 *     target: array{php: string, pcre: string, guard: string|null, guard_pcre: string|null}|null,
 * }
 * @phpstan-type JsonOptimization array{
 *     file: string,
 *     line: int,
 *     column: int|null,
 *     file_offset: int|null,
 *     optimization: OptimizationResult,
 *     savings: int,
 *     source: string|null,
 * }
 *
 * @phpstan-import-type LintResult from LintReport
 * @phpstan-import-type LintIssue from LintReport
 * @phpstan-import-type OptimizationEntry from LintReport
 * @phpstan-import-type TargetDescription from ProjectTarget
 *
 * @internal
 */
final class JsonFormatter extends AbstractOutputFormatter
{
    /**
     * @param array{php: string, pcre: string, source: string, range?: list<TargetDescription>}|null $target the PHP and PCRE2 the patterns were judged for, where that came from, and every PHP and PCRE2 they were validated at
     */
    public function __construct(OutputConfiguration $config = new OutputConfiguration(), private readonly ?array $target = null)
    {
        parent::__construct($config);
    }

    /**
     * @throws LintException when a value of the report has no JSON form
     */
    public function format(LintReport $report): string
    {
        $data = null === $this->target ? [] : ['target' => $this->target];
        $data += [
            // What ran the judgement: the PCRE2 that compiled the patterns
            // and the PHP whose mbstring tables fold their case under "iu".
            'host' => ['php' => \PHP_VERSION, 'pcre' => explode(' ', \PCRE_VERSION)[0]],
            // A report reader finds every key on every run, the optional
            // counts included.
            'stats' => [
                'errors' => $report->stats['errors'],
                'warnings' => $report->stats['warnings'],
                'optimizations' => $report->stats['optimizations'],
                'redos_errors' => $report->stats['redos'] ?? 0,
                'infos' => $report->stats['infos'] ?? 0,
                'lint_errors' => $report->stats['lintErrors'] ?? 0,
                'parser_fallbacks' => $report->stats['parserFallbacks'] ?? 0,
            ],
            'results' => $this->mapResults($report->results),
        ];

        try {
            return JsonDocument::encode($data);
        } catch (JsonEncodingFailure $e) {
            throw new LintException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The error envelope, {"error", "stage"}; the stage says where the run
     * stopped (usage, config, collect, pattern, internal).
     */
    public function formatError(string $message, string $stage = JsonDocument::STAGE_INTERNAL): string
    {
        return JsonDocument::error($message, $stage);
    }

    /**
     * @phpstan-param array<LintResult> $results
     *
     * @return list<array{file: string, line: int, column: int|null, file_offset: int|null, source: string|null, pattern: string|null, location: string|null, issues: list<JsonIssue>, optimizations: list<JsonOptimization>}>
     */
    private function mapResults(array $results): array
    {
        $mapped = [];

        foreach ($results as $result) {
            // A malformed entry has nothing a reader could rely on.
            if (!\is_array($result)) {
                continue;
            }

            $mapped[] = [
                'file' => $result['file'],
                'line' => $result['line'],
                'column' => $result['column'] ?? null,
                'file_offset' => $result['fileOffset'] ?? null,
                'source' => $result['source'] ?? null,
                'pattern' => $result['pattern'],
                'location' => $result['location'] ?? null,
                'issues' => array_map($this->mapIssue(...), array_values($result['issues'])),
                'optimizations' => array_map($this->mapOptimization(...), array_values($result['optimizations'])),
            ];
        }

        return $mapped;
    }

    /**
     * @phpstan-param LintIssue $issue
     *
     * @return JsonIssue
     */
    private function mapIssue(array $issue): array
    {
        return [
            'severity' => $issue['type'],
            'file' => $issue['file'],
            'line' => $issue['line'],
            'column' => $issue['column'] ?? null,
            'file_offset' => $issue['fileOffset'] ?? null,
            'position' => $issue['position'] ?? null,
            'issue_id' => $issue['issueId'] ?? null,
            'message' => $issue['message'],
            'hint' => $issue['hint'] ?? null,
            'tip' => $issue['tip'] ?? null,
            'source' => $issue['source'] ?? null,
            'validation' => $issue['validation'] ?? null,
            'analysis' => $issue['analysis'] ?? null,
            // The PHP and PCRE2 of the range that refuse a pattern the
            // floor accepts, or that judged it under a guard or a marker,
            // with the constraint of the guard; null when the issue is the
            // floor's.
            'target' => isset($issue['target']) ? self::target($issue['target']) : null,
        ];
    }

    /**
     * An issue's target with every key of the contract: the constraint of
     * the guard or the marker that narrowed the versions judged, null when
     * none did.
     *
     * @param TargetDescription $target
     *
     * @return array{php: string, pcre: string, guard: string|null, guard_pcre: string|null}
     */
    private static function target(array $target): array
    {
        return ['php' => $target['php'], 'pcre' => $target['pcre'], 'guard' => $target['guard'] ?? null, 'guard_pcre' => $target['guard_pcre'] ?? null];
    }

    /**
     * @phpstan-param OptimizationEntry $optimization
     *
     * @return JsonOptimization
     */
    private function mapOptimization(array $optimization): array
    {
        return [
            'file' => $optimization['file'],
            'line' => $optimization['line'],
            'column' => $optimization['column'] ?? null,
            'file_offset' => $optimization['fileOffset'] ?? null,
            'optimization' => $optimization['optimization'],
            'savings' => $optimization['savings'],
            'source' => $optimization['source'] ?? null,
        ];
    }
}

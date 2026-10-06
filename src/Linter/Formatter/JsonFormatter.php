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

use PHPRegex\Linter\LintException;
use PHPRegex\Linter\LintReport;

/**
 * JSON output formatter for machine-readable output.
 *
 * @internal
 */
final class JsonFormatter extends AbstractOutputFormatter
{
    /**
     * @param array{php: string, pcre: string, source: string}|null $target the PHP and PCRE2 the patterns were judged for, and where that came from
     */
    public function __construct(OutputConfiguration $config = new OutputConfiguration(), private readonly ?array $target = null)
    {
        parent::__construct($config);
    }

    public function format(LintReport $report): string
    {
        $data = null === $this->target ? [] : ['target' => $this->target];
        $data += [
            // A report reader finds every key on every run, the optional
            // counts included.
            'stats' => [
                'errors' => $report->stats['errors'],
                'warnings' => $report->stats['warnings'],
                'optimizations' => $report->stats['optimizations'],
                'redos' => $report->stats['redos'] ?? 0,
                'infos' => $report->stats['infos'] ?? 0,
                'lintErrors' => $report->stats['lintErrors'] ?? 0,
            ],
            'results' => $this->normalizeResults($report->results),
        ];

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            throw new LintException('Failed to encode JSON');
        }

        return $json;
    }

    public function formatError(string $message): string
    {
        return json_encode(['error' => $message], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array<string, mixed>> $results
     *
     * @return array<array<string, mixed>>
     */
    private function normalizeResults(array $results): array
    {
        $normalized = [];

        foreach ($results as $result) {
            if (!\is_array($result)) {
                continue;
            }

            $entry = $result;
            unset($entry['problems']);
            $normalized[] = $this->escapeStrings($entry);
        }

        return $normalized;
    }

    /**
     * Every string of the report, including the ones held by issue and
     * optimization objects, is carried as it was found: only the bytes that
     * are no part of a UTF-8 character, which json_encode() rejects, are
     * written "\xHH".
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $value
     *
     * @return array<TKey, mixed>
     */
    private function escapeStrings(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = $this->escapeValue($item);
        }

        return $value;
    }

    private function escapeValue(mixed $value): mixed
    {
        if (\is_string($value)) {
            return ReportSpelling::source($value);
        }

        if (\is_array($value)) {
            return $this->escapeStrings($value);
        }

        if ($value instanceof \JsonSerializable) {
            return $this->escapeValue($value->jsonSerialize());
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (\is_object($value)) {
            return $this->escapeStrings(get_object_vars($value));
        }

        return $value;
    }
}

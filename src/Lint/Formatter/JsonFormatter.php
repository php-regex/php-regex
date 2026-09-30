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

namespace RegexParser\Lint\Formatter;

use RegexParser\Internal\DisplayEscaper;
use RegexParser\Lint\LintException;
use RegexParser\Lint\RegexLintReport;

/**
 * JSON output formatter for machine-readable output.
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

    public function format(RegexLintReport $report): string
    {
        $data = null === $this->target ? [] : ['target' => $this->target];
        $data += [
            'stats' => $report->stats,
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
     * Byte-mode patterns are not valid UTF-8, which json_encode() rejects.
     * Every string of the report — including the ones held by issue and
     * optimization objects — is escaped the way the console renders them, so
     * the report stays encodable and the patterns remain readable.
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
            return DisplayEscaper::escape($value);
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
